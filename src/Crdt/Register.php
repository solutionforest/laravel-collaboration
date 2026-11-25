<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use BackedEnum;
use DateTimeInterface;
use SolutionForest\Collaboration\Support\Data;

/** A value where the most recent write wins. */
final class Register
{
    public function __construct(
        public mixed $value = null,
        private Id $at = new Id(0, 0),
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        return new self($data['v'] ?? null, isset($data['at']) ? Id::from($data['at']) : new Id(0, 0));
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === '' => null,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => array_map(self::normalize(...), Data::list($value)),
            default => $value,
        };
    }

    public function set(mixed $value, Id $at): void
    {
        if ($at->isAfter($this->at)) {
            $this->value = $value;
            $this->at = $at;
        }
    }

    /** @return array{v: mixed, at: array{int, int}} */
    public function toArray(): array
    {
        return ['v' => $this->value, 'at' => $this->at->toArray()];
    }
}
