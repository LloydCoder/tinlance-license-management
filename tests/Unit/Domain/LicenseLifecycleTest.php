<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseLifecycle;

final class LicenseLifecycleTest
{
    public function testIssuedLicenceCanBeActivatedSuspendedRevokedOrExpired(): void
    {
        foreach ([LicenseLifecycle::ACTIVE, LicenseLifecycle::SUSPENDED, LicenseLifecycle::REVOKED, LicenseLifecycle::EXPIRED] as $next) {
            expectTrue(LicenseLifecycle::canTransition(LicenseLifecycle::ISSUED, $next));
        }
    }

    public function testActiveLicenceCanBeSuspendedRevokedOrExpired(): void
    {
        foreach ([LicenseLifecycle::SUSPENDED, LicenseLifecycle::REVOKED, LicenseLifecycle::EXPIRED] as $next) {
            expectTrue(LicenseLifecycle::canTransition(LicenseLifecycle::ACTIVE, $next));
        }
    }

    public function testSuspendedLicenceCanBeReactivatedRevokedOrExpired(): void
    {
        foreach ([LicenseLifecycle::ACTIVE, LicenseLifecycle::REVOKED, LicenseLifecycle::EXPIRED] as $next) {
            expectTrue(LicenseLifecycle::canTransition(LicenseLifecycle::SUSPENDED, $next));
        }
    }

    public function testRevokedAndExpiredLicencesAreTerminal(): void
    {
        foreach ([LicenseLifecycle::REVOKED, LicenseLifecycle::EXPIRED] as $terminal) {
            expectTrue(LicenseLifecycle::isTerminal($terminal));
            foreach (LicenseLifecycle::states() as $next) {
                expectFalse(LicenseLifecycle::canTransition($terminal, $next));
            }
        }
    }

    public function testUnknownStatesCannotTransition(): void
    {
        expectFalse(LicenseLifecycle::canTransition('unknown', LicenseLifecycle::ACTIVE));
        expectFalse(LicenseLifecycle::canTransition(LicenseLifecycle::ACTIVE, 'unknown'));
        expectThrows(
            static fn () => LicenseLifecycle::assertTransition('unknown', LicenseLifecycle::ACTIVE),
            InvalidArgumentException::class,
        );
    }

    public function testIllegalTransitionsAreRejected(): void
    {
        expectThrows(
            static fn () => LicenseLifecycle::assertTransition(LicenseLifecycle::REVOKED, LicenseLifecycle::ACTIVE),
            InvalidArgumentException::class,
        );
        expectThrows(
            static fn () => LicenseLifecycle::assertTransition(LicenseLifecycle::EXPIRED, LicenseLifecycle::ACTIVE),
            InvalidArgumentException::class,
        );
        expectThrows(
            static fn () => LicenseLifecycle::assertTransition(LicenseLifecycle::ACTIVE, LicenseLifecycle::ISSUED),
            InvalidArgumentException::class,
        );
    }
}
