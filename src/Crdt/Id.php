<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

/**
 * A Lamport clock paired with the editor that created it. Ids are totally
 * ordered, so every replica resolves concurrent changes the same way.
 */
final readonly class Id
{
    public const MAX = 9007199254740991; // Number.MAX_SAFE_INTEGER

    public function __construct(
        public int $clock,
        public int $client,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value) || count($value) < 2) {
            throw new InvalidOperation('An id must be a [clock, client] pair.');
        }

        $clock = $value[0] ?? null;
        $client = $value[1] ?? null;

        if (! is_int($clock) || ! is_int($client) || $clock < 0 || $client < 0 || $clock > self::MAX || $client > self::MAX) {
            throw new InvalidOperation('An id must contain two safe integers.');
        }

        return new self($clock, $client);
    }

    public function isAfter(self $other): bool
    {
        return $this->clock === $other->clock ? $this->client > $other->client : $this->clock > $other->clock;
    }

    public function plus(int $offset): self
    {
        return new self($this->clock + $offset, $this->client);
    }

    /** @return array{int, int} */
    public function toArray(): array
    {
        return [$this->clock, $this->client];
    }
}
