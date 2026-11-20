<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;

final readonly class LicenseKeyPepperRing
{
    /**
     * @param  array<string, string>  $peppers  Pepper material indexed by immutable key ID.
     */
    public function __construct(
        private array $peppers,
        private string $activePepperKeyId,
    ) {
        if ($peppers === [] || ! array_key_exists($activePepperKeyId, $peppers)) {
            throw new InvalidArgumentException('The active licence-key pepper must exist in the configured key ring.');
        }

        foreach ($peppers as $keyId => $pepper) {
            if (! is_string($keyId) || ! is_string($pepper)) {
                throw new InvalidArgumentException('The licence-key pepper ring has an invalid entry.');
            }

            LicenseKey::validatePepperConfiguration($pepper, $keyId);
        }
    }

    public function verifyAndUpgrade(
        string $plaintext,
        string $storedVerifier,
        string $storedPepperKeyId,
    ): LicenseKeyVerificationResult {
        if (! array_key_exists($storedPepperKeyId, $this->peppers)) {
            return LicenseKeyVerificationResult::invalid();
        }

        $storedPepper = $this->peppers[$storedPepperKeyId];

        if (! LicenseKey::verify($plaintext, $storedVerifier, $storedPepper, $storedPepperKeyId)) {
            return LicenseKeyVerificationResult::invalid();
        }

        if ($storedPepperKeyId === $this->activePepperKeyId) {
            return LicenseKeyVerificationResult::verified($this->activePepperKeyId);
        }

        $replacementVerifier = LicenseKey::createVerifierForExistingKey(
            $plaintext,
            $this->peppers[$this->activePepperKeyId],
            $this->activePepperKeyId,
        );

        return LicenseKeyVerificationResult::verified($this->activePepperKeyId, $replacementVerifier);
    }
}
