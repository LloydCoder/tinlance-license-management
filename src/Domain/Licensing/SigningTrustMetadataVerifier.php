<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use stdClass;

final class SigningTrustMetadataVerifier
{
    /**
     * Verify a root metadata rotation against both the previously trusted root
     * threshold and the candidate root's self-signature threshold.
     *
     * The currently trusted root may already be expired; callers may continue
     * the sequential root update chain, but the candidate root must be current.
     *
     * @param array<string, mixed> $candidateEnvelope
     */
    public static function verifyRootRotation(
        array $candidateEnvelope,
        SigningTrustMetadata $current,
    ): ?SigningTrustMetadata {
        $candidateSigned = $candidateEnvelope['signed'] ?? null;
        if (! ($candidateSigned instanceof stdClass)) {
            return null;
        }

        try {
            $candidate = SigningTrustMetadata::fromSigned($candidateSigned);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (
            $current->version >= 9007199254740991
            || $candidate->version !== $current->version + 1
        ) {
            return null;
        }

        if (! Ed25519Envelope::verify(
            $candidateEnvelope,
            $current->publicKeysForRole('root'),
            $current->thresholdForRole('root'),
        )) {
            return null;
        }

        if (! Ed25519Envelope::verify(
            $candidateEnvelope,
            $candidate->publicKeysForRole('root'),
            $candidate->thresholdForRole('root'),
        )) {
            return null;
        }

        return $candidate;
    }
}
