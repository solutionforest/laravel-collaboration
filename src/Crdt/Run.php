<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use SolutionForest\Collaboration\Support\Data;

/**
 * Consecutive items typed by one editor, stored together. An item is either a
 * code point, an inline embed or the marker that starts a block.
 */
final class Run
{
    public int $length;

    /**
     * @param  string|array<string, mixed>  $value
     * @param  array<string, array{?string, int, int}>  $attributes  value, clock and client of the last write
     */
    public function __construct(
        public Id $id,
        public string|array $value,
        public bool $deleted = false,
        public array $attributes = [],
    ) {
        $this->length = is_string($value) ? mb_strlen($value, 'UTF-8') : 1;
    }

    public static function from(mixed $data): self
    {
        $data = Data::list($data);
        $value = $data[2] ?? '';
        $attributes = [];

        foreach (Data::map($data[4] ?? null) as $key => $write) {
            $write = Data::list($write);
            $attributes[$key] = [Data::nullableString($write[0] ?? null), Data::int($write[1] ?? 0), Data::int($write[2] ?? 0)];
        }

        return new self(
            Id::from($data),
            is_string($value) ? $value : Data::map($value),
            ($data[3] ?? 0) === 1,
            $attributes,
        );
    }

    public function contains(Id $id): bool
    {
        return $id->client === $this->id->client
            && $id->clock >= $this->id->clock
            && $id->clock < $this->id->clock + $this->length;
    }

    public function split(int $offset): self
    {
        $value = Data::string($this->value);
        $tail = new self($this->id->plus($offset), mb_substr($value, $offset, null, 'UTF-8'), $this->deleted, $this->attributes);

        $this->value = mb_substr($value, 0, $offset, 'UTF-8');
        $this->length = $offset;

        return $tail;
    }

    public function join(self $next): bool
    {
        if (! is_string($this->value) || ! is_string($next->value)
            || $next->id->client !== $this->id->client
            || $next->id->clock !== $this->id->clock + $this->length
            || $next->deleted !== $this->deleted
            || $next->attributes != $this->attributes) {
            return false;
        }

        $this->value .= $next->value;
        $this->length += $next->length;

        return true;
    }

    public function format(string $key, ?string $value, Id $at): void
    {
        $current = $this->attributes[$key] ?? null;

        if ($current === null || $at->isAfter(new Id($current[1], $current[2]))) {
            $this->attributes[$key] = [$value, $at->clock, $at->client];
        }
    }

    /** @return array<string, string> */
    public function formatting(): array
    {
        $formatting = [];

        foreach ($this->attributes as $key => [$value]) {
            if ($value !== null) {
                $formatting[$key] = $value;
            }
        }

        ksort($formatting, SORT_STRING);

        return $formatting;
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        $data = [$this->id->clock, $this->id->client, $this->value, $this->deleted ? 1 : 0];

        if ($this->attributes !== []) {
            $data[] = $this->attributes;
        }

        return $data;
    }
}
