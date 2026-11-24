<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use LogicException;
use Tlms\Domain\Licensing\CanonicalJson;
use Tlms\Domain\Licensing\Ed25519Envelope;
use Tlms\Domain\Licensing\Ed25519KeyPair;

final class Ed25519EnvelopeTest
{
    private const RFC8032_SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';

    private const RFC8032_PUBLIC_KEY = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';

    private const RFC8032_EMPTY_MESSAGE_SIGNATURE = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155'
        .'5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';

    public function testRfc8032Ed25519TestVectorMatchesThePublishedSeedPublicKeyAndSignature(): void
    {
        $seed = hex2bin(self::RFC8032_SEED);
        if (! is_string($seed)) {
            throw new InvalidArgumentException('The RFC 8032 test seed is invalid.');
        }

        $keyPair = Ed25519KeyPair::fromSeed($seed);

        expectSame(self::RFC8032_PUBLIC_KEY, bin2hex($keyPair->publicKey()));
        expectSame(self::RFC8032_EMPTY_MESSAGE_SIGNATURE, bin2hex($keyPair->sign('')));
    }

    public function testCanonicalJsonSortsObjectKeysRecursivelyAndPreservesListOrder(): void
    {
        $payload = [
            'zeta' => 1,
            'alpha' => [
                'second' => 2,
                'first' => 1,
            ],
            'items' => ['b', 'a'],
        ];

        expectSame(
            '{"alpha":{"first":1,"second":2},"items":["b","a"],"zeta":1}',
            CanonicalJson::encode($payload),
        );
    }

    public function testCanonicalJsonRejectsFloatingPointValues(): void
    {
        expectThrows(
            static fn () => CanonicalJson::encode(['amount' => 1.25]),
            InvalidArgumentException::class,
        );
    }

    public function testSignedEnvelopeVerifiesAgainstItsTrustedPublicKey(): void
    {
        $keyPair = $this->keyPair();
        $payload = [
            'schema_version' => 1,
            'license_id' => '01JTLMSLICENSE00000000000000',
            'entitlements' => ['seats' => 5, 'offline' => true],
        ];

        $envelope = Ed25519Envelope::sign($payload, $keyPair);

        expectTrue(Ed25519Envelope::verify(
            $envelope,
            [$keyPair->keyId() => $keyPair->publicKey()],
        ));
    }

    public function testSignedEnvelopeRejectsPayloadTampering(): void
    {
        $keyPair = $this->keyPair();
        $envelope = Ed25519Envelope::sign(['license_id' => 'license-original'], $keyPair);
        $envelope['signed']->license_id = 'license-tampered';

        expectFalse(Ed25519Envelope::verify(
            $envelope,
            [$keyPair->keyId() => $keyPair->publicKey()],
        ));
    }

    public function testSignatureThresholdCountsDistinctTrustedKeysOnly(): void
    {
        $keyPair = $this->keyPair();
        $envelope = Ed25519Envelope::sign(['license_id' => 'license-threshold'], $keyPair);
        $envelope['signatures'][] = $envelope['signatures'][0];

        expectTrue(Ed25519Envelope::verify(
            $envelope,
            [$keyPair->keyId() => $keyPair->publicKey()],
            1,
        ));
        expectFalse(Ed25519Envelope::verify(
            $envelope,
            [$keyPair->keyId() => $keyPair->publicKey()],
            2,
        ));
    }

    public function testUnknownOrMismatchedTrustedKeyCannotSatisfyTheSignatureThreshold(): void
    {
        $keyPair = $this->keyPair();
        $otherKeyPair = Ed25519KeyPair::generate();
        $envelope = Ed25519Envelope::sign(['license_id' => 'license-trust'], $keyPair);

        expectFalse(Ed25519Envelope::verify(
            $envelope,
            [$otherKeyPair->keyId() => $otherKeyPair->publicKey()],
        ));
    }

    public function testMalformedSeedIsRejected(): void
    {
        expectThrows(
            static fn () => Ed25519KeyPair::fromSeed('too-short'),
            InvalidArgumentException::class,
        );
    }

    public function testKeyPairDebugOutputNeverExposesTheSecretKey(): void
    {
        $keyPair = $this->keyPair();
        $debug = json_encode($keyPair->__debugInfo(), JSON_THROW_ON_ERROR);

        expectTrue(str_contains($debug, '[REDACTED]'));
    }

    public function testCanonicalJsonRejectsIntegersOutsideTheCrossLanguageSafeRange(): void
    {
        expectThrows(
            static fn () => CanonicalJson::encode(['counter' => 9007199254740992]),
            InvalidArgumentException::class,
        );
    }

    public function testSignatureThresholdAcceptsSignaturesFromDistinctTrustedKeys(): void
    {
        $first = $this->keyPair();
        $second = Ed25519KeyPair::generate();
        $payload = ['license_id' => 'license-two-person-threshold'];
        $canonical = CanonicalJson::encode($payload);
        $envelope = Ed25519Envelope::sign($payload, $first);
        $secondSignature = $second->sign($canonical);
        $envelope['signatures'][] = [
            'keyid' => $second->keyId(),
            'sig' => rtrim(strtr(base64_encode($secondSignature), '+/', '-_'), '='),
        ];

        expectTrue(Ed25519Envelope::verify(
            $envelope,
            [
                $first->keyId() => $first->publicKey(),
                $second->keyId() => $second->publicKey(),
            ],
            2,
        ));
    }

    public function testPrivateKeyPairCannotBeSerializedAndDestroyedKeysCannotSign(): void
    {
        $keyPair = $this->keyPair();

        expectThrows(static fn () => serialize($keyPair), LogicException::class);

        $keyPair->destroy();

        expectThrows(static fn () => $keyPair->sign('after-destroy'), LogicException::class);
    }

    public function testCanonicalJsonRejectsCyclicAndExcessivelyNestedObjects(): void
    {
        $cycle = new \\stdClass;
        $cycle->self = $cycle;

        expectThrows(
            static fn () => CanonicalJson::encode(['cycle' => $cycle]),
            InvalidArgumentException::class,
        );
    }

    private function keyPair(): Ed25519KeyPair
    {
        $seed = hex2bin(self::RFC8032_SEED);
        if (! is_string($seed)) {
            throw new InvalidArgumentException('The RFC 8032 test seed is invalid.');
        }

        return Ed25519KeyPair::fromSeed($seed);
    }
}
