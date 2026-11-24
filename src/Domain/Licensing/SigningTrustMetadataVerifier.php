<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use stdClass;

final class SigningTrustMetadataVerifier
{
    /**
     * Verify a complete sequential root update chain using one fixed update-start
     * time, then enforce expiry on the final trusted root only.
     *
     * @param  list<array<string, mixed>>  $candidateEnvelopes
     */
    public static function verifyRootRotationChain(
        array $candidateEnvelopes,
        SigningTrustMetadata $current,
        DateTimeImmutable $updateStartedAt,
    ): ?SigningTrustMetadata {
        if (! array_is_list($candidateEnvelopes)) {
            return null;
        }

        foreach ($candidateEnvelopes as $candidateEnvelope) {
            $next = self::verifyRootRotation($candidateEnvelope, $current);
            if (! ($next instanceof SigningTrustMetadata)) {
                return null;
            }

            $current = $next;
        }

        return $current->isCurrentAt($updateStartedAt) ? $current : null;
    }

    /**
     * Verify a root metadata rotation against both the previously trusted root
     * threshold and the candidate root's self-signature threshold.
     *
     * Expiry is checked after the sequential root-update chain, not on each
     * intermediate root. Role verification requires the final metadata to be current.
     *
     * @param  array<string, mixed>  $candidateEnvelope
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
