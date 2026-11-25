<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

use Illuminate\Contracts\Support\Arrayable;
use stdClass;

/**
 * Narrows untyped JSON, cache, database and Livewire data.
 *
 * @internal
 */
final class Data
{
    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_filter(self::list($value), is_string(...)));
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    public static function decode(mixed $json): array
    {
        return is_string($json) ? self::map(json_decode($json, true)) : [];
    }

    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS,
        );
    }
}
