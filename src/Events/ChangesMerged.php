<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ChangesMerged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public readonly Model $record,
        public readonly mixed $user,
        public readonly array $fields,
        public readonly string $theirName,
    ) {}
}
