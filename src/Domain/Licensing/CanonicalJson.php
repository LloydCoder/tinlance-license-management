<?php

declare(strict_types=1);

namespace Tlms\\Domain\\Licensing;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class CanonicalJson
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS;

    private const MAX_DEPTH = 32;

    /**
     * Encode a JSON object using the TLMS signed-payload canonicalization profile.
     *
     * Object keys are restricted to stable ASCII identifiers and sorted bytewise.
     * Lists preserve order. Floating-point values are rejected; signed claims use
     * safe integers for numeric values. Empty objects must be supplied as stdClass.
     *
     * @param array<string, mixed> $value
     */
    public static function encode(array $value): string
    {
        return self::encodeObject(self::normalizeObject($value));
    }

    public static function encodeObject(stdClass $value): string
    {
        try {
            return json_encode(self::normalizeObject(get_object_vars($value)), self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The signed payload cannot be encoded as canonical JSON.', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function normalizeObject(array $value): stdClass
    {
        return self::normalizeObjectAtDepth($value, 0);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function normalizeObjectAtDepth(array $value, int $depth): stdClass
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('The signed payload exceeds the maximum canonical JSON nesting depth.');
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || preg_match('/\\A[A-Za-z0-9_.-]+\\z/D', $key) !== 1) {
                throw new InvalidArgumentException('Canonical JSON object keys must be stable ASCII identifiers.');
            }
        }

        ksort($value, SORT_STRING);
        $object = new stdClass;

        foreach ($value as $key => $item) {
            $object->{$key} = self::normalizeValue($item, $depth + 1);
        }

        return $object;
    }

    private static function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('The signed payload exceeds the maximum canonical JSON nesting depth.');
        }

        if ($value instanceof stdClass) {
            return self::normalizeObjectAtDepth(get_object_vars($value), $depth);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    static fn (mixed $item): mixed => self::normalizeValue($item, $depth + 1),
                    $value,
                );
            }

            return self::normalizeObjectAtDepth($value, $depth);
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Floating-point values are not permitted in TLMS signed payloads.');
        }

        if (is_int($value) && ($value < -9007199254740991 || $value > 9007199254740991)) {
            throw new InvalidArgumentException('Canonical JSON integers must remain within the cross-language safe integer range.');
        }

        if (is_string($value) && preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Canonical JSON strings must contain valid UTF-8.');
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('The signed payload contains an unsupported JSON value.');
    }
}
