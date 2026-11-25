<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use SolutionForest\Collaboration\Documents\Contributor;

/**
 * With `audit()` on: somebody changed a field of the shared draft, at most
 * once per person, field and minute.
 */
final class DraftEdited
{
    use Dispatchable;

    /** @param int $minute the start of the minute, as a Unix timestamp */
    public function __construct(
        public readonly Model $record,
        public readonly Contributor $by,
        public readonly string $field,
        public readonly int $minute,
    ) {}
}
