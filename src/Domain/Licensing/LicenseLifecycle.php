<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;

/**
 * Defines the only permitted persisted licence lifecycle transitions.
 *
 * Issuance creates an "issued" licence. Activation is a separate, explicit
 * transition so issuance can never be mistaken for successful installation.
 */
final class LicenseLifecycle
{
    public const ISSUED = 'issued';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::ISSUED => [self::ACTIVE, self::SUSPENDED, self::REVOKED, self::EXPIRED],
        self::ACTIVE => [self::SUSPENDED, self::REVOKED, self::EXPIRED],
        self::SUSPENDED => [self::ACTIVE, self::REVOKED, self::EXPIRED],
        self::REVOKED => [],
        self::EXPIRED => [],
    ];

    /** @return list<string> */
    public static function states(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isKnownState(string $state): bool
    {
        return array_key_exists($state, self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return isset(self::TRANSITIONS[$from])
            && in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! self::isKnownState($from) || ! self::isKnownState($to)) {
            throw new InvalidArgumentException('The licence lifecycle state is unknown.');
        }

        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException('The requested licence lifecycle transition is not permitted.');
        }
    }

    public static function isTerminal(string $state): bool
    {
        if (! self::isKnownState($state)) {
            throw new InvalidArgumentException('The licence lifecycle state is unknown.');
        }

        return self::TRANSITIONS[$state] === [];
    }
}
