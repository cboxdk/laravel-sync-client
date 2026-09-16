<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

/**
 * Type-narrowing reads off a decoded response.
 *
 * Everything here works on stdClass, never associative arrays, and that is the
 * whole point: json_decode with assoc returns [] for {}, so an empty object in
 * a field value would arrive as an empty array and be stored as one. The
 * corruption is consistent, so nothing downstream ever notices.
 */
class Read
{
    public static function object(\stdClass $body, string $key): \stdClass
    {
        $value = $body->{$key} ?? null;

        return $value instanceof \stdClass ? $value : throw self::fail($key, 'an object');
    }

    /** @return list<\stdClass> */
    public static function objects(\stdClass $body, string $key): array
    {
        $value = $body->{$key} ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::fail($key, 'a list');
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $item instanceof \stdClass ? $item : throw self::fail($key, 'a list of objects');
        }

        return $items;
    }

    public static function string(\stdClass $body, string $key): string
    {
        $value = $body->{$key} ?? null;

        return is_string($value) && $value !== '' ? $value : throw self::fail($key, 'a non-empty string');
    }

    public static function optionalString(\stdClass $body, string $key): ?string
    {
        $value = $body->{$key} ?? null;
        if ($value === null) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : throw self::fail($key, 'a string or null');
    }

    public static function int(\stdClass $body, string $key): int
    {
        $value = $body->{$key} ?? null;

        return is_int($value) ? $value : throw self::fail($key, 'an integer');
    }

    public static function bool(\stdClass $body, string $key): bool
    {
        $value = $body->{$key} ?? null;

        return is_bool($value) ? $value : throw self::fail($key, 'a boolean');
    }

    private static function fail(string $key, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException('Sync response field "'.$key.'" should be '.$expected);
    }
}
