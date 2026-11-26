<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

/** @internal "Did you mean …?" for error messages about typos. */
final class Suggest
{
    /** @param list<string> $candidates */
    public static function didYouMean(string $typed, array $candidates): string
    {
        $closest = self::closest($typed, $candidates);

        return $closest === null ? '' : " Did you mean [{$closest}]?";
    }

    /** @param list<string> $candidates */
    public static function closest(string $typed, array $candidates): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            [$a, $b] = [strtolower($typed), strtolower($candidate)];

            // `richtext` for `rich`, `title_en` for `title`: one is the other, extended.
            $current = min(strlen($a), strlen($b)) >= 3 && (str_starts_with($a, $b) || str_starts_with($b, $a)) ? 1 : levenshtein($a, $b);

            if ($current < $distance) {
                [$best, $distance] = [$candidate, $current];
            }
        }

        // Close enough to be the same word mistyped, not merely another field.
        return $best !== null && $distance <= max(2, intdiv(strlen($typed), 3)) ? $best : null;
    }
}
