<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

/** Type-narrowing reads off a decoded response. A malformed one is a bug in the server, and says so. */
class Read
{
    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function array(array $body, string $key): array
    {
        $value = $body[$key] ?? null;

        return is_array($value) ? $value : throw self::fail($key, 'an object');
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return list<array<array-key, mixed>>
     */
    public static function list(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if (! is_array($value)) {
            throw self::fail($key, 'a list');
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = is_array($item) ? $item : throw self::fail($key, 'a list of objects');
        }

        return $items;
    }

    /** @param array<array-key, mixed> $body */
    public static function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : throw self::fail($key, 'a non-empty string');
    }

    /** @param array<array-key, mixed> $body */
    public static function optionalString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : throw self::fail($key, 'a string or null');
    }

    /** @param array<array-key, mixed> $body */
    public static function int(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        return is_int($value) ? $value : throw self::fail($key, 'an integer');
    }

    /** @param array<array-key, mixed> $body */
    public static function bool(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;

        return is_bool($value) ? $value : throw self::fail($key, 'a boolean');
    }

    private static function fail(string $key, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException('Sync response field "'.$key.'" should be '.$expected);
    }
}
