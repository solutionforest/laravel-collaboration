<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use SolutionForest\Collaboration\Support\Data;

/**
 * A replicated growable array (RGA), stored as runs.
 *
 * An item is placed after the item its author saw on its left. Concurrent items
 * at that position are ordered by id, newest first. Deleting leaves a tombstone
 * so late operations can still find their position.
 */
final class Sequence
{
    /** @param list<Run> $runs */
    public function __construct(private array $runs = []) {}

    public static function from(mixed $data): self
    {
        return new self(array_map(Run::from(...), Data::list($data)));
    }

    public function __clone()
    {
        $this->runs = array_map(static fn (Run $run): Run => clone $run, $this->runs);
    }

    /**
     * @param  string|array<string, mixed>  $value
     * @param  array<string, string>  $formatting
     */
    public function insert(Id $id, ?Id $after, string|array $value, array $formatting): void
    {
        if ($this->find($id) !== null) {
            return;
        }

        $position = 0;

        if ($after !== null) {
            $found = $this->find($after) ?? throw new InvalidOperation('The insertion position does not exist.');
            $run = $this->runs[$found];
            $offset = $after->clock - $run->id->clock + 1;

            if ($offset < $run->length) {
                array_splice($this->runs, $found + 1, 0, [$run->split($offset)]);
            }

            $position = $found + 1;
        }

        while (isset($this->runs[$position]) && $this->runs[$position]->id->isAfter($id)) {
            $position++;
        }

        $attributes = array_map(static fn (string $item): array => [$item, $id->clock, $id->client], $formatting);

        array_splice($this->runs, $position, 0, [new Run($id, $value, false, $attributes)]);
        $this->compact();
    }

    public function delete(Id $from, int $length): void
    {
        $this->each($from, $length, static function (Run $run): void {
            $run->deleted = true;
        });
    }

    public function format(Id $from, int $length, string $key, ?string $value, Id $at): void
    {
        $this->each($from, $length, static fn (Run $run) => $run->format($key, $value, $at));
    }

    public function length(): int
    {
        return array_sum(array_map(static fn (Run $run): int => $run->length, $this->runs));
    }

    /** @return list<Token> */
    public function tokens(bool $includeDeleted = false): array
    {
        $tokens = [];

        foreach ($this->runs as $run) {
            if ($run->deleted && ! $includeDeleted) {
                continue;
            }

            $formatting = $run->formatting();
            $values = is_string($run->value) ? mb_str_split($run->value, 1, 'UTF-8') : [$run->value];

            foreach ($values as $offset => $value) {
                $tokens[] = new Token($value, $formatting, $run->id->plus($offset));
            }
        }

        return $tokens;
    }

    public function text(): string
    {
        $text = '';

        foreach ($this->runs as $run) {
            if (! $run->deleted && is_string($run->value)) {
                $text .= $run->value;
            }
        }

        return $text;
    }

    /**
     * The visible content without history, for cheap equality checks.
     *
     * @return list<array{string|array<string, mixed>, array<string, string>}>
     */
    public function signature(): array
    {
        $segments = [];

        foreach ($this->runs as $run) {
            if ($run->deleted) {
                continue;
            }

            $formatting = $run->formatting();
            $last = array_key_last($segments);

            if ($last !== null && is_string($run->value) && is_string($segments[$last][0]) && $segments[$last][1] === $formatting) {
                $segments[$last] = [$segments[$last][0].$run->value, $formatting];
            } else {
                $segments[] = [$run->value, $formatting];
            }
        }

        return $segments;
    }

    /** @return list<list<mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Run $run): array => $run->toArray(), $this->runs);
    }

    private function find(Id $id): ?int
    {
        foreach ($this->runs as $index => $run) {
            if ($run->contains($id)) {
                return $index;
            }
        }

        return null;
    }

    /** @param callable(Run): void $callback */
    private function each(Id $from, int $length, callable $callback): void
    {
        $end = $from->clock + $length;
        $runs = [];

        foreach ($this->runs as $run) {
            $start = $run->id->clock;
            $finish = $start + $run->length;

            if ($run->id->client !== $from->client || $start >= $end || $finish <= $from->clock) {
                $runs[] = $run;

                continue;
            }

            $tail = $end < $finish ? $run->split($end - $start) : null;

            if ($from->clock > $start) {
                $runs[] = $run;
                $run = $run->split($from->clock - $start);
            }

            $callback($run);
            $runs[] = $run;

            if ($tail !== null) {
                $runs[] = $tail;
            }
        }

        $this->runs = $runs;
        $this->compact();
    }

    private function compact(): void
    {
        $runs = [];
        $last = null;

        foreach ($this->runs as $run) {
            if ($last?->join($run) === true) {
                continue;
            }

            $runs[] = $last = $run;
        }

        $this->runs = $runs;
    }
}
