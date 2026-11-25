<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Merge;

final class MergeResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $taken
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public readonly array $data,
        public readonly array $taken,
        public readonly array $conflicts,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
