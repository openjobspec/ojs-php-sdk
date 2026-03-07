<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{
    EncryptionCodec,
    EncryptionMiddleware,
    Job,
    JobContext,
    KeyProvider,
    MiddlewareChain,
    StaticKeyProvider,
};
use PHPUnit\Framework\TestCase;

use function OpenJobSpec\encryptJobArgs;

use const OpenJobSpec\META_CODEC_ENCODINGS;
use const OpenJobSpec\META_CODEC_KEY_ID;
use const OpenJobSpec\ENCODING_BINARY_ENCRYPTED;

class EncryptionMiddlewareTest extends TestCase
{
    private string $keyA;
    private string $keyB;
    private EncryptionCodec $codec;

    protected function setUp(): void
    {
        $this->keyA = random_bytes(32);
        $this->keyB = random_bytes(32);
        $this->codec = new EncryptionCodec();
    }

    private function makeJob(array $args = [], array $meta = []): Job
    {
        return Job::fromArray([
            'id' => 'enc-test-1',
            'type' => 'test.encrypt',
            'args' => $args,
            'queue' => 'default',
            'state' => 'active',
            'meta' => $meta,
        ]);
    }

    private function makeKeyProvider(?string $extraKeyId = null, ?string $extraKey = null): StaticKeyProvider
    {
        $keys = ['key-a' => $this->keyA];
        if ($extraKeyId !== null && $extraKey !== null) {
            $keys[$extraKeyId] = $extraKey;
        }
        return new StaticKeyProvider($keys, 'key-a');
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $provider = $this->makeKeyProvider();
        $originalArgs = ['hello', 42, ['nested' => true]];
        $job = $this->makeJob($originalArgs);

        $encrypted = encryptJobArgs($this->codec, $provider, $job);

        // The encrypted job should have a single base64 string in args
        $this->assertCount(1, $encrypted->args);
        $this->assertIsString($encrypted->args[0]);

        // Decrypt via middleware
        $ctx = new JobContext($encrypted);
        $middleware = new EncryptionMiddleware($this->codec, $provider);

        $decryptedCtx = null;
        $middleware->handle($ctx, function (JobContext $ctx) use (&$decryptedCtx) {
            $decryptedCtx = $ctx;
            return 'done';
        });

        $this->assertNotNull($decryptedCtx);
        $this->assertEquals($originalArgs, $decryptedCtx->job->args);
    }

    public function testDecryptWrongKey(): void
    {
        $providerA = $this->makeKeyProvider();
        $job = $this->makeJob(['secret data']);
        $encrypted = encryptJobArgs($this->codec, $providerA, $job);

        // Build a provider with a different key mapped to the same key ID
        $providerB = new StaticKeyProvider(['key-a' => $this->keyB], 'key-a');

        $ctx = new JobContext($encrypted);
        $middleware = new EncryptionMiddleware($this->codec, $providerB);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('decryption failed');

        $middleware->handle($ctx, fn(JobContext $ctx) => null);
    }

    public function testNonceUniqueness(): void
    {
        $provider = $this->makeKeyProvider();
        $job = $this->makeJob(['same payload']);

        $encrypted1 = encryptJobArgs($this->codec, $provider, $job);
        $encrypted2 = encryptJobArgs($this->codec, $provider, $job);

        // Two encryptions of the same plaintext must produce different ciphertexts
        $this->assertNotEquals($encrypted1->args[0], $encrypted2->args[0]);
    }

    public function testStaticKeyProvider(): void
    {
        $provider = new StaticKeyProvider(
            ['k1' => $this->keyA, 'k2' => $this->keyB],
            'k1',
        );

        $this->assertEquals('k1', $provider->getCurrentKeyId());
        $this->assertEquals($this->keyA, $provider->getKey('k1'));
        $this->assertEquals($this->keyB, $provider->getKey('k2'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown encryption key ID');
        $provider->getKey('nonexistent');
    }

    public function testStaticKeyProviderInvalidCurrentKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Current key ID');

        new StaticKeyProvider(['k1' => $this->keyA], 'missing');
    }

    public function testMetaKeysSet(): void
    {
        $provider = $this->makeKeyProvider();
        $job = $this->makeJob(['data']);

        $encrypted = encryptJobArgs($this->codec, $provider, $job);

        $this->assertArrayHasKey(META_CODEC_ENCODINGS, $encrypted->meta);
        $this->assertArrayHasKey(META_CODEC_KEY_ID, $encrypted->meta);
        $this->assertEquals([ENCODING_BINARY_ENCRYPTED], $encrypted->meta[META_CODEC_ENCODINGS]);
        $this->assertEquals('key-a', $encrypted->meta[META_CODEC_KEY_ID]);
    }

    public function testEmptyPlaintext(): void
    {
        $provider = $this->makeKeyProvider();
        $job = $this->makeJob([]);

        $encrypted = encryptJobArgs($this->codec, $provider, $job);
        $this->assertCount(1, $encrypted->args);

        // Decrypt via middleware
        $ctx = new JobContext($encrypted);
        $middleware = new EncryptionMiddleware($this->codec, $provider);

        $decryptedCtx = null;
        $middleware->handle($ctx, function (JobContext $ctx) use (&$decryptedCtx) {
            $decryptedCtx = $ctx;
            return 'ok';
        });

        $this->assertNotNull($decryptedCtx);
        $this->assertEquals([], $decryptedCtx->job->args);
    }

    public function testNonEncryptedJobPassesThrough(): void
    {
        $provider = $this->makeKeyProvider();
        $job = $this->makeJob(['plain', 'args']);

        $ctx = new JobContext($job);
        $middleware = new EncryptionMiddleware($this->codec, $provider);

        $passedCtx = null;
        $result = $middleware->handle($ctx, function (JobContext $ctx) use (&$passedCtx) {
            $passedCtx = $ctx;
            return 'pass';
        });

        $this->assertEquals('pass', $result);
        $this->assertEquals(['plain', 'args'], $passedCtx->job->args);
    }

    public function testMiddlewareStripsEncryptionMeta(): void
    {
        $provider = $this->makeKeyProvider();
        $job = $this->makeJob(['value'], ['custom_key' => 'preserved']);

        $encrypted = encryptJobArgs($this->codec, $provider, $job);

        // Verify encryption meta is present before decryption
        $this->assertArrayHasKey(META_CODEC_ENCODINGS, $encrypted->meta);

        $ctx = new JobContext($encrypted);
        $middleware = new EncryptionMiddleware($this->codec, $provider);

        $decryptedCtx = null;
        $middleware->handle($ctx, function (JobContext $ctx) use (&$decryptedCtx) {
            $decryptedCtx = $ctx;
            return null;
        });

        // Encryption meta should be stripped, custom meta preserved
        $this->assertArrayNotHasKey(META_CODEC_ENCODINGS, $decryptedCtx->job->meta);
        $this->assertArrayNotHasKey(META_CODEC_KEY_ID, $decryptedCtx->job->meta);
        $this->assertEquals('preserved', $decryptedCtx->job->meta['custom_key']);
    }
}
