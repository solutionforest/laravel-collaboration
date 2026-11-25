<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Merge;

use BackedEnum;
use DateTimeInterface;
use SolutionForest\Collaboration\Crdt\InvalidOperation;
use SolutionForest\Collaboration\Crdt\RichText;
use SolutionForest\Collaboration\Crdt\Token;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Field-by-field three-way merge of form state.
 *
 * `base` is the form as loaded (or last saved), `mine` is the form being saved,
 * and `theirs` is the current database record filled through the same form.
 */
final class ThreeWayMerge
{
    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $mine
     * @param  array<string, mixed>  $theirs
     */
    public static function merge(array $base, array $mine, array $theirs): MergeResult
    {
        $merged = $mine;
        $taken = [];
        $conflicts = [];

        foreach (array_unique([...array_keys($base), ...array_keys($theirs)]) as $field) {
            if (self::same($theirs[$field] ?? null, $base[$field] ?? null, exact: true)) {
                continue;
            }

            if (self::untouched($base[$field] ?? null, $mine[$field] ?? null)) {
                $merged[$field] = $theirs[$field] ?? null;
                $taken[] = $field;

                continue;
            }

            if (! self::same($mine[$field] ?? null, $theirs[$field] ?? null)) {
                $conflicts[] = $field;
            }
        }

        return new MergeResult($merged, $taken, $conflicts);
    }

    public static function untouched(mixed $base, mixed $mine): bool
    {
        return self::same($base, $mine, exact: is_string($mine) || is_array($mine));
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changed(array $from, array $to, bool $exact = false): array
    {
        return array_values(array_filter(
            array_unique([...array_keys($from), ...array_keys($to)]),
            fn (string $field): bool => ! self::same($from[$field] ?? null, $to[$field] ?? null, $exact),
        ));
    }

    public static function same(mixed $left, mixed $right, bool $exact = false): bool
    {
        return self::normalize($left, $exact) === self::normalize($right, $exact);
    }

    public static function normalize(mixed $value, bool $exact = false): mixed
    {
        if ($value instanceof BackedEnum) {
            return self::normalize($value->value, $exact);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        if ($value instanceof Stringable) {
            return self::normalize((string) $value, $exact);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $exact ? $value : (string) $value;
        }

        if (is_string($value)) {
            if (! $exact && is_numeric($value) && ! preg_match('/^0\d/', $value) && ! str_contains($value, 'e') && ! str_contains($value, 'E')) {
                return str_contains($value, '.') ? (string) (float) $value : (string) (int) $value;
            }

            return $value;
        }

        if (is_array($value)) {
            if (($value['type'] ?? null) === 'doc') {
                try {
                    // The browser adds default attributes that PHP leaves out.
                    return array_map(static fn (Token $token): array => [$token->value, $token->formatting], RichText::tokens($value));
                } catch (InvalidOperation) {
                    // Content that cannot be live is compared as it is.
                }
            }

            $keys = array_keys($value);
            $normalized = [];

            // Repeaters and uploads get new keys on every fill, so their order is their identity.
            $hasTemporaryKeys = array_filter($keys, static fn (int|string $key): bool => ! self::isTemporaryKey($key)) === [];
            $hasRecordKeys = array_filter($keys, static fn (int|string $key): bool => ! self::isTemporaryKey($key) && ! self::isRecordKey($key)) === [];

            foreach ($value as $key => $item) {
                $item = self::normalize($item, $exact || is_string($item) || is_array($item) || $item instanceof stdClass);

                if ($hasTemporaryKeys) {
                    $normalized[] = $item;
                } elseif ($hasRecordKeys) {
                    $normalized[] = [self::isRecordKey($key) ? $key : null, $item];
                } else {
                    $normalized[$key] = $item;
                }
            }

            if (! $hasRecordKeys && ! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value;
    }

    private static function isTemporaryKey(int|string $key): bool
    {
        return is_string($key) && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-7][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            $key,
        ) === 1;
    }

    private static function isRecordKey(int|string $key): bool
    {
        return is_string($key) && str_starts_with($key, 'record-');
    }
}
