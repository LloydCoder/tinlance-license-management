<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

final readonly class IssuedLicenseKey
{
    private function __construct(
        public string $plaintext,
        public string $verifier,
        public string $pepperKeyId,
    ) {
    }

    public static function fromParts(string $plaintext, string $verifier, string $pepperKeyId): self
    {
        return new self($plaintext, $verifier, $pepperKeyId);
    }
}
