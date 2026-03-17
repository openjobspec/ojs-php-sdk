<?php

declare(strict_types=1);

namespace OpenJobSpec\Attest;

/**
 * Quote type constants identifying the attestation envelope.
 */
final class QuoteType
{
    public const AWS_NITRO = 'aws-nitro-v1';
    public const INTEL_TDX = 'intel-tdx-v4';
    public const AMD_SEV_SNP = 'amd-sev-snp-v2';
    public const PQC_ONLY = 'pqc-only';
    public const NONE = 'none';
}

/**
 * Signature algorithm constants.
 */
final class SignatureAlgorithm
{
    public const ED25519 = 'ed25519';
    public const ML_DSA_65 = 'ml-dsa-65';
    public const HYBRID_ED_ML_DSA = 'hybrid:Ed25519+ML-DSA-65';
}

/**
 * Input envelope handed to an Attestor for signing.
 */
final class AttestInput
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $jobType,
        public readonly string $argsHash,
        public readonly string $resultHash,
        public readonly \DateTimeImmutable $timestamp,
    ) {}
}

/**
 * Result returned by a successful attestation.
 */
final class AttestResult
{
    public function __construct(
        public readonly ?Quote $quote,
        public readonly ?Jurisdiction $jurisdiction,
        public readonly ?ModelFingerprint $modelFingerprint,
        public readonly AttestSignature $signature,
    ) {}
}

/**
 * Attestation evidence produced by the TEE or software layer.
 */
final class Quote
{
    public function __construct(
        public readonly string $type,
        public readonly string $evidence,
        public readonly string $nonce,
        public readonly \DateTimeImmutable $issuedAt,
    ) {}
}

/**
 * Where the attestation was produced.
 */
final class Jurisdiction
{
    public function __construct(
        public readonly string $region,
        public readonly string $datacenter,
        public readonly string $prover,
    ) {}
}

/**
 * ML model identity for auditability.
 */
final class ModelFingerprint
{
    public function __construct(
        public readonly string $sha256,
        public readonly string $registryUrl,
    ) {}
}

/**
 * Cryptographic signature over the attestation.
 */
final class AttestSignature
{
    public function __construct(
        public readonly string $algorithm,
        public readonly string $value,
        public readonly string $keyId,
    ) {}
}

/**
 * Receipt bundles everything a verifier needs.
 */
final class Receipt
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?Quote $quote,
        public readonly ?Jurisdiction $jurisdiction,
        public readonly ?ModelFingerprint $modelFingerprint,
        public readonly AttestSignature $signature,
        public readonly \DateTimeImmutable $issuedAt,
    ) {}
}

/**
 * Interface that all attestation backends must implement.
 */
interface Attestor
{
    /** Returns a human-readable identifier for this attestor. */
    public function name(): string;

    /** Produces an attestation result for the given input. */
    public function attest(AttestInput $input): AttestResult;

    /** Checks a previously produced receipt. */
    public function verify(Receipt $receipt): void;
}

/**
 * Default no-op attestor that always succeeds.
 */
class NoneAttestor implements Attestor
{
    public function name(): string
    {
        return 'none';
    }

    public function attest(AttestInput $input): AttestResult
    {
        return new AttestResult(
            quote: new Quote(
                type: QuoteType::NONE,
                evidence: '',
                nonce: '',
                issuedAt: $input->timestamp,
            ),
            jurisdiction: null,
            modelFingerprint: null,
            signature: new AttestSignature(
                algorithm: SignatureAlgorithm::ED25519,
                value: '',
                keyId: '',
            ),
        );
    }

    public function verify(Receipt $receipt): void
    {
        // No-op: always succeeds.
    }
}

/**
 * Software-only post-quantum-ready attestor.
 * Signs with Ed25519; algorithm field distinguishes from future ML-DSA-65.
 */
class PqcOnlyAttestor implements Attestor
{
    private string $keyId;

    public function __construct(string $keyId)
    {
        $this->keyId = $keyId;
    }

    public function name(): string
    {
        return 'pqc-only';
    }

    public function attest(AttestInput $input): AttestResult
    {
        $digestInput = $input->argsHash . $input->resultHash . $input->timestamp->format(\DateTimeInterface::RFC3339);
        $digest = hash('sha256', $digestInput);
        $nonce = substr($digest, 0, 32);

        return new AttestResult(
            quote: new Quote(
                type: QuoteType::PQC_ONLY,
                evidence: hex2bin($digest) ?: '',
                nonce: $nonce,
                issuedAt: $input->timestamp,
            ),
            jurisdiction: null,
            modelFingerprint: null,
            signature: new AttestSignature(
                algorithm: SignatureAlgorithm::ED25519,
                value: '', // real signing requires Ed25519 key
                keyId: $this->keyId,
            ),
        );
    }

    public function verify(Receipt $receipt): void
    {
        if ($receipt->quote === null) {
            throw new \RuntimeException('receipt has no quote');
        }

        // Full Ed25519 verification would require the public key.
        // This stub validates structure only.
    }
}
