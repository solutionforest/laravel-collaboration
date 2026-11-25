<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

final readonly class DraftUpdate
{
    /**
     * @param  array<string, mixed>|null  $state  present when the browser must start over
     * @param  list<array<string, mixed>>  $operations
     * @param  list<string>  $dirty
     */
    public function __construct(
        public int $epoch = 0,
        public int $sequence = 0,
        public int $version = 0,
        public ?array $state = null,
        public int $acknowledged = 0,
        public array $operations = [],
        public array $dirty = [],
        public ?string $savedByName = null,
        public int $savedByClient = 0,
    ) {}
}
