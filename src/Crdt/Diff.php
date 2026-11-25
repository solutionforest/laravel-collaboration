<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

/**
 * Finds the smallest practical set of operations between two token lists.
 *
 * Keep in step with `resources/js/crdt/diff.js`.
 */
final class Diff
{
    private const MATRIX_LIMIT = 250_000;

    /**
     * @param  list<Token>  $old  tokens that carry ids
     * @param  list<Token>  $new
     * @param  int  $clock  the highest clock in the document
     * @return list<array<string, mixed>>
     */
    public static function operations(string $field, array $old, array $new, int $clock, int $client): array
    {
        $operations = [];
        $formats = [];
        $previous = null;
        $inserting = [];
        $deleting = [];

        // The trailing empty pair flushes whatever changed at the end.
        foreach ([...self::align($old, $new), [null, null]] as [$from, $to]) {
            if ($from !== null && $to === null) {
                if ($from->id !== null) {
                    $deleting[] = $from->id;
                }

                continue;
            }

            if ($from === null && $to !== null) {
                $inserting[] = $to;

                continue;
            }

            if ($deleting !== []) {
                $operations[] = ['o' => 'del', 'f' => $field, 'ids' => self::ranges($deleting)];
                $deleting = [];
            }

            foreach (self::segments($inserting) as [$value, $formatting, $length]) {
                $id = new Id($clock + 1, $client);
                $operation = ['o' => 'ins', 'f' => $field, 'id' => $id->toArray(), 'after' => $previous?->toArray(), 'v' => $value];

                if ($formatting !== []) {
                    $operation['a'] = $formatting;
                }

                $operations[] = $operation;
                $clock += $length;
                $previous = $id->plus($length - 1);
            }

            $inserting = [];

            if ($from?->id === null) {
                continue;
            }

            $previous = $from->id;

            foreach (array_keys($from->formatting + $to->formatting) as $key) {
                $value = $to->formatting[$key] ?? null;

                if (($from->formatting[$key] ?? null) !== $value) {
                    $group = $key."\0".($value ?? "\0");
                    $formats[$group] ??= [$key, $value, []];
                    $formats[$group][2][] = $from->id;
                }
            }
        }

        foreach ($formats as [$key, $value, $ids]) {
            $operations[] = ['o' => 'fmt', 'f' => $field, 'id' => [++$clock, $client], 'ids' => self::ranges($ids), 'k' => $key, 'v' => $value];
        }

        return $operations;
    }

    /**
     * @param  list<Token>  $old
     * @param  list<Token>  $new
     * @return list<array{?Token, ?Token}>
     */
    private static function align(array $old, array $new): array
    {
        $start = 0;
        $shortest = min(count($old), count($new));

        while ($start < $shortest && $old[$start]->hasSameContent($new[$start])) {
            $start++;
        }

        $oldEnd = count($old);
        $newEnd = count($new);

        while ($oldEnd > $start && $newEnd > $start && $old[$oldEnd - 1]->hasSameContent($new[$newEnd - 1])) {
            $oldEnd--;
            $newEnd--;
        }

        $pairs = [];

        for ($index = 0; $index < $start; $index++) {
            $pairs[] = [$old[$index], $new[$index]];
        }

        array_push($pairs, ...self::middle(
            array_slice($old, $start, $oldEnd - $start),
            array_slice($new, $start, $newEnd - $start),
        ));

        for ($offset = 0; $oldEnd + $offset < count($old); $offset++) {
            $pairs[] = [$old[$oldEnd + $offset], $new[$newEnd + $offset]];
        }

        return $pairs;
    }

    /**
     * @param  list<Token>  $old
     * @param  list<Token>  $new
     * @return list<array{?Token, ?Token}>
     */
    private static function middle(array $old, array $new): array
    {
        [$m, $n] = [count($old), count($new)];
        $lengths = [];

        if ($m > 0 && $n > 0 && $m * $n <= self::MATRIX_LIMIT) {
            $lengths[$m] = array_fill(0, $n + 1, 0);

            for ($i = $m - 1; $i >= 0; $i--) {
                $row = array_fill(0, $n + 1, 0);

                for ($j = $n - 1; $j >= 0; $j--) {
                    $row[$j] = $old[$i]->hasSameContent($new[$j])
                        ? $lengths[$i + 1][$j + 1] + 1
                        : max($lengths[$i + 1][$j], $row[$j + 1]);
                }

                $lengths[$i] = $row;
            }
        }

        $pairs = [];
        [$i, $j] = [0, 0];

        if ($lengths !== []) {
            while ($i < $m && $j < $n) {
                if ($old[$i]->hasSameContent($new[$j])) {
                    $pairs[] = [$old[$i++], $new[$j++]];
                } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                    $pairs[] = [$old[$i++], null];
                } else {
                    $pairs[] = [null, $new[$j++]];
                }
            }
        }

        while ($i < $m) {
            $pairs[] = [$old[$i++], null];
        }

        while ($j < $n) {
            $pairs[] = [null, $new[$j++]];
        }

        return $pairs;
    }

    /**
     * @param  list<Token>  $tokens
     * @return list<array{string|array<string, mixed>, array<string, string>, int}>
     */
    private static function segments(array $tokens): array
    {
        $segments = [];
        $text = '';
        $formatting = [];
        $length = 0;

        foreach ($tokens as $token) {
            if ($length > 0 && (! is_string($token->value) || $token->formatting !== $formatting)) {
                $segments[] = [$text, $formatting, $length];
                [$text, $length] = ['', 0];
            }

            if (is_string($token->value)) {
                $text .= $token->value;
                $length++;
                $formatting = $token->formatting;
            } else {
                $segments[] = [$token->value, $token->formatting, 1];
            }
        }

        if ($length > 0) {
            $segments[] = [$text, $formatting, $length];
        }

        return $segments;
    }

    /**
     * @param  list<Id>  $ids
     * @return list<array{int, int, int}>
     */
    private static function ranges(array $ids): array
    {
        $ranges = [];

        foreach ($ids as $id) {
            $last = count($ranges) - 1;

            if ($last >= 0 && $ranges[$last][1] === $id->client && $ranges[$last][0] + $ranges[$last][2] === $id->clock) {
                $ranges[$last] = [$ranges[$last][0], $id->client, $ranges[$last][2] + 1];
            } else {
                $ranges[] = [$id->clock, $id->client, 1];
            }
        }

        return $ranges;
    }
}
