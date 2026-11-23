<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseResponseCipherRing;

final class LicenseResponseCipherRingTest
{
    public function testEncryptedResponseCanBeDecryptedWithTheSameContext(): void
    {
        $ring = new LicenseResponseCipherRing(['response-2026-01' => random_bytes(32)], 'response-2026-01');
        $plaintext = bin2hex(random_bytes(24));
        $envelope = $ring->encrypt($plaintext, 'vendor-1:request-1');

        expectSame($plaintext, $ring->decrypt($envelope, 'vendor-1:request-1'));
        expectFalse(str_contains($envelope, $plaintext));
    }

    public function testCiphertextCannotBeOpenedUnderAnotherContext(): void
    {
        $ring = new LicenseResponseCipherRing(['response-2026-01' => random_bytes(32)], 'response-2026-01');
        $envelope = $ring->encrypt(bin2hex(random_bytes(24)), 'vendor-1:request-1');

        expectThrows(
            static fn () => $ring->decrypt($envelope, 'vendor-2:request-1'),
            InvalidArgumentException::class,
        );
    }

    public function testCiphertextCannotBeOpenedAfterTampering(): void
    {
        $ring = new LicenseResponseCipherRing(['response-2026-01' => random_bytes(32)], 'response-2026-01');
        $envelope = $ring->encrypt(bin2hex(random_bytes(24)), 'vendor-1:request-1');
        $tampered = substr($envelope, 0, -1).($envelope[strlen($envelope) - 1] === 'A' ? 'B' : 'A');

        expectThrows(
            static fn () => $ring->decrypt($tampered, 'vendor-1:request-1'),
            InvalidArgumentException::class,
        );
    }

    public function testOldEncryptionKeyRemainsAvailableForRotation(): void
    {
        $oldKey = random_bytes(32);
        $old = new LicenseResponseCipherRing(['response-2026-01' => $oldKey], 'response-2026-01');
        $plaintext = bin2hex(random_bytes(24));
        $envelope = $old->encrypt($plaintext, 'vendor-1:request-1');
        $rotated = new LicenseResponseCipherRing([
            'response-2026-01' => $oldKey,
            'response-2026-02' => random_bytes(32),
        ], 'response-2026-02');

        expectSame($plaintext, $rotated->decrypt($envelope, 'vendor-1:request-1'));
        $replacement = $rotated->encrypt($plaintext, 'vendor-1:request-1');
        expectSame($plaintext, $rotated->decrypt($replacement, 'vendor-1:request-1'));
        expectFalse($envelope === $replacement);
    }

    public function testUnknownKeyIdentifierIsRejected(): void
    {
        $ring = new LicenseResponseCipherRing(['response-2026-01' => random_bytes(32)], 'response-2026-01');
        $envelope = $ring->encrypt(bin2hex(random_bytes(24)), 'vendor-1:request-1');
        $other = new LicenseResponseCipherRing(['response-2026-02' => random_bytes(32)], 'response-2026-02');

        expectThrows(
            static fn () => $other->decrypt($envelope, 'vendor-1:request-1'),
            InvalidArgumentException::class,
        );
    }

    public function testWeakEncryptionKeyIsRejected(): void
    {
        expectThrows(
            static fn () => new LicenseResponseCipherRing(['response-2026-01' => 'short'], 'response-2026-01'),
            InvalidArgumentException::class,
        );
    }

    public function testMissingActiveKeyIsRejected(): void
    {
        expectThrows(
            static fn () => new LicenseResponseCipherRing(['response-2026-01' => random_bytes(32)], 'response-2026-02'),
            InvalidArgumentException::class,
        );
    }
}
