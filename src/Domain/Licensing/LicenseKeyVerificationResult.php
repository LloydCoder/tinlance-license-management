<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

final readonly class LicenseKeyVerificationResult
{
    private function __construct(
        public bool $valid,
        public ?string $pepperKeyId,
        public ?string $replacementVerifier,
    ) {}

    public static function invalid(): self
    {
        return new self(false, null, null);
    }

    public static function verified(string $pepperKeyId, ?string $replacementVerifier = null): self
    {
        return new self(true, $pepperKeyId, $replacementVerifier);
    }

    public function requiresUpgrade(): bool
    {
        return $this->replacementVerifier !== null;
    }
}
