<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;
use JsonException;

final class LicenseIssuanceFingerprint
{
    /**
     * Hash a canonical, scope-bound issuance payload. Secret key material must never
     * be included in this payload; only normalized request semantics belong here.
     *
     * @param array<string, mixed> $payload
     */
    public static function create(string $vendorAccountId, string $actorUserId, array $payload): string
    {
        if (trim($vendorAccountId) === '' || trim($actorUserId) === '') {
            throw new InvalidArgumentException('Licence issuance fingerprint scope is invalid.');
        }

        $canonical = self::canonicalize($payload);

        try {
            $encoded = json_encode([
                'operation' => 'license.issue.v1',
                'vendor_account_id' => $vendorAccountId,
                'actor_user_id' => $actorUserId,
                'payload' => $canonical,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Licence issuance payload is not canonical JSON data.', previous: $exception);
        }

        return hash('sha256', $encoded);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::canonicalize(...), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('Licence issuance payload object keys must be strings.');
                }

                $normalized[$key] = self::canonicalize($item);
            }
            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            if (is_float($value) && (! is_finite($value))) {
                throw new InvalidArgumentException('Licence issuance payload contains a non-finite number.');
            }

            return $value;
        }

        throw new InvalidArgumentException('Licence issuance payload contains a non-JSON value.');
    }
}
