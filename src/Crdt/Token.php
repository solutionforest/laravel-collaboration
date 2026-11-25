<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

/** One visible item, independent of how it is stored. */
final readonly class Token
{
    /**
     * @param  string|array<string, mixed>  $value
     * @param  array<string, string>  $formatting
     */
    public function __construct(
        public string|array $value,
        public array $formatting = [],
        public ?Id $id = null,
    ) {}

    public function isBlock(): bool
    {
        return is_array($this->value) && array_key_exists('b', $this->value);
    }

    /** Block markers are equal whatever their type, so retyping a block keeps its text. */
    public function hasSameContent(self $other): bool
    {
        return $this->value === $other->value;
    }
}
