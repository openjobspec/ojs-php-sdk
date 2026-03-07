<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Encryption middleware for OJS jobs.
 *
 * Provides AES-256-GCM encryption/decryption of job args with key rotation
 * support. Encrypted args are stored as a single base64-encoded element in
 * the args array, with spec-canonical metadata (ojs.codec.encodings,
 * ojs.codec.key_id) in the job's meta map. The nonce is prepended to the
 * ciphertext blob rather than stored separately in meta.
 */

// -- Meta keys (OJS spec-canonical names) --
const META_CODEC_ENCODINGS = 'ojs.codec.encodings';
const META_CODEC_KEY_ID    = 'ojs.codec.key_id';

const ENCODING_BINARY_ENCRYPTED = 'binary/encrypted';

// -- Legacy meta keys for backward compatibility on decrypt --
const LEGACY_META_ENCRYPTED = 'ojs.encryption.encrypted';
const LEGACY_META_ALGORITHM = 'ojs.encryption.algorithm';
const LEGACY_META_KEY_ID    = 'ojs.encryption.key_id';
const LEGACY_META_NONCE     = 'ojs.encryption.nonce';

/**
 * Provides encryption keys by ID, supporting key rotation.
 */
interface KeyProvider
{
    /**
     * Retrieve a key by its identifier.
     *
     * @param string $keyId The key identifier
     * @return string Raw binary key (32 bytes for AES-256)
     * @throws \RuntimeException If the key ID is unknown
     */
    public function getKey(string $keyId): string;

    /**
     * Return the identifier of the current (latest) encryption key.
     */
    public function getCurrentKeyId(): string;
}

/**
 * Simple in-memory key provider backed by an associative array.
 */
class StaticKeyProvider implements KeyProvider
{
    /**
     * @param array<string, string> $keys    Map of keyId => raw binary key (32 bytes)
     * @param string                $currentKeyId The key ID to use for new encryptions
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $currentKeyId,
    ) {
        if (!isset($this->keys[$this->currentKeyId])) {
            throw new \InvalidArgumentException("Current key ID '{$this->currentKeyId}' not found in keys");
        }
    }

    public function getKey(string $keyId): string
    {
        if (!isset($this->keys[$keyId])) {
            throw new \RuntimeException("Unknown encryption key ID: {$keyId}");
        }
        return $this->keys[$keyId];
    }

    public function getCurrentKeyId(): string
    {
        return $this->currentKeyId;
    }
}

/**
 * AES-256-GCM encryption codec using PHP's OpenSSL extension.
 *
 * Binary layout produced by encrypt():
 *   nonce (12 bytes) || ciphertext (variable) || tag (16 bytes)
 */
class EncryptionCodec
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    /**
     * Encrypt plaintext with AES-256-GCM.
     *
     * @param string $plaintext Raw plaintext bytes
     * @param string $key       Raw 32-byte encryption key
     * @return string Binary string: nonce (12) || ciphertext || tag (16)
     * @throws \RuntimeException On encryption failure
     */
    public function encrypt(string $plaintext, string $key): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed: ' . openssl_error_string());
        }

        return $nonce . $ciphertext . $tag;
    }

    /**
     * Decrypt data produced by encrypt().
     *
     * @param string $data Binary string: nonce (12) || ciphertext || tag (16)
     * @param string $key  Raw 32-byte encryption key
     * @return string Decrypted plaintext
     * @throws \RuntimeException On decryption failure or tampered data
     */
    public function decrypt(string $data, string $key): string
    {
        $minLen = self::NONCE_LEN + self::TAG_LEN;
        if (strlen($data) < $minLen) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $nonce      = substr($data, 0, self::NONCE_LEN);
        $tag        = substr($data, -self::TAG_LEN);
        $ciphertext = substr($data, self::NONCE_LEN, strlen($data) - $minLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('AES-256-GCM decryption failed (wrong key or tampered data)');
        }

        return $plaintext;
    }
}

/**
 * Worker-side middleware that transparently decrypts encrypted job args.
 *
 * When a job's meta contains the encrypted flag, this middleware:
 * 1. Reads the key ID and nonce from meta
 * 2. Base64-decodes args[0] to get the encrypted payload
 * 3. Decrypts and JSON-decodes to restore the original args
 * 4. Rebuilds the JobContext with a new Job carrying the decrypted args
 * 5. Passes the decrypted context to the next handler
 *
 * Non-encrypted jobs pass through unchanged.
 */
class EncryptionMiddleware implements Middleware
{
    public function __construct(
        private readonly EncryptionCodec $codec,
        private readonly KeyProvider $keys,
    ) {}

    public function handle(JobContext $ctx, callable $next): mixed
    {
        $meta = $ctx->job->meta;

        // Check spec-canonical ojs.codec.encodings for "binary/encrypted"
        $encodings = $meta[META_CODEC_ENCODINGS] ?? null;
        $isEncrypted = is_array($encodings) && in_array(ENCODING_BINARY_ENCRYPTED, $encodings, true);

        // Backward compat: check legacy ojs.encryption.encrypted flag
        if (!$isEncrypted) {
            $isEncrypted = !empty($meta[LEGACY_META_ENCRYPTED]);
        }

        if (!$isEncrypted) {
            return $next($ctx);
        }

        $keyId = $meta[META_CODEC_KEY_ID] ?? $meta[LEGACY_META_KEY_ID] ?? null;
        if ($keyId === null) {
            throw new \RuntimeException('Encrypted job is missing key ID in meta');
        }

        $key = $this->keys->getKey($keyId);

        $encoded = $ctx->job->args[0] ?? null;
        if (!is_string($encoded)) {
            throw new \RuntimeException('Encrypted job args[0] must be a base64 string');
        }

        $data = base64_decode($encoded, true);
        if ($data === false) {
            throw new \RuntimeException('Failed to base64-decode encrypted args');
        }

        $plaintext = $this->codec->decrypt($data, $key);

        /** @var array<mixed> $decryptedArgs */
        $decryptedArgs = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        // Strip encryption metadata (both canonical and legacy) and rebuild
        $cleanMeta = $meta;
        unset(
            $cleanMeta[META_CODEC_ENCODINGS],
            $cleanMeta[META_CODEC_KEY_ID],
            $cleanMeta[LEGACY_META_ENCRYPTED],
            $cleanMeta[LEGACY_META_ALGORITHM],
            $cleanMeta[LEGACY_META_KEY_ID],
            $cleanMeta[LEGACY_META_NONCE],
        );

        $decryptedJob = new Job(
            id: $ctx->job->id,
            type: $ctx->job->type,
            args: $decryptedArgs,
            queue: $ctx->job->queue,
            state: $ctx->job->state,
            attempt: $ctx->job->attempt,
            priority: $ctx->job->priority,
            meta: $cleanMeta,
            timeout: $ctx->job->timeout,
            scheduledAt: $ctx->job->scheduledAt,
            expiresAt: $ctx->job->expiresAt,
            createdAt: $ctx->job->createdAt,
            enqueuedAt: $ctx->job->enqueuedAt,
            startedAt: $ctx->job->startedAt,
            completedAt: $ctx->job->completedAt,
            error: $ctx->job->error,
            result: $ctx->job->result,
            retryPolicy: $ctx->job->retryPolicy,
            uniquePolicy: $ctx->job->uniquePolicy,
            schema: $ctx->job->schema,
            progress: $ctx->job->progress,
        );

        // Preserve the store from the original context
        $decryptedCtx = new JobContext($decryptedJob);
        $decryptedCtx->store = $ctx->store;

        return $next($decryptedCtx);
    }
}

/**
 * Encrypt a job's args for enqueue.
 *
 * Returns a new Job with:
 *   - args replaced by a single base64-encoded encrypted payload
 *   - meta augmented with ojs.codec.encodings and ojs.codec.key_id
 *
 * @param EncryptionCodec $codec The encryption codec
 * @param KeyProvider      $keys  Key provider (current key used for encryption)
 * @param Job              $job   The job to encrypt
 * @return Job A new Job instance with encrypted args and encryption meta
 */
function encryptJobArgs(EncryptionCodec $codec, KeyProvider $keys, Job $job): Job
{
    $keyId = $keys->getCurrentKeyId();
    $key = $keys->getKey($keyId);

    $plaintext = json_encode($job->args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $encrypted = $codec->encrypt($plaintext, $key);

    $meta = $job->meta;
    $meta[META_CODEC_ENCODINGS] = [ENCODING_BINARY_ENCRYPTED];
    $meta[META_CODEC_KEY_ID]    = $keyId;

    return new Job(
        id: $job->id,
        type: $job->type,
        args: [base64_encode($encrypted)],
        queue: $job->queue,
        state: $job->state,
        attempt: $job->attempt,
        priority: $job->priority,
        meta: $meta,
        timeout: $job->timeout,
        scheduledAt: $job->scheduledAt,
        expiresAt: $job->expiresAt,
        createdAt: $job->createdAt,
        enqueuedAt: $job->enqueuedAt,
        startedAt: $job->startedAt,
        completedAt: $job->completedAt,
        error: $job->error,
        result: $job->result,
        retryPolicy: $job->retryPolicy,
        uniquePolicy: $job->uniquePolicy,
        schema: $job->schema,
        progress: $job->progress,
    );
}
