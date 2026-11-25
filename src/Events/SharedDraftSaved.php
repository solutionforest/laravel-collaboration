<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SolutionForest\Collaboration\Documents\Contributor;

/**
 * A save published the shared draft. `$user` pressed Save; `$contributors`
 * says who actually changed each field since it was last saved, so an audit
 * can credit everybody.
 */
final class SharedDraftSaved
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $fields  the fields published from the shared draft
     * @param  array<string, list<Contributor>>  $contributors  by field
     */
    public function __construct(
        public readonly Model $record,
        public readonly ?Authenticatable $user,
        public readonly array $fields,
        public readonly array $contributors = [],
    ) {}

    /**
     * Everybody who changed a saved field, once each.
     *
     * @return list<Contributor>
     */
    public function contributors(): array
    {
        $people = [];

        foreach ($this->contributors as $fieldContributors) {
            foreach ($fieldContributors as $contributor) {
                $people[$contributor->key()] = $contributor;
            }
        }

        return array_values($people);
    }

    /**
     * For an audit log: `['title' => ['Alice Chen', 'Bob Martinez'], ...]`.
     *
     * @return array<string, list<string>>
     */
    public function contributorNames(): array
    {
        return array_map(
            static fn (array $people): array => array_values(array_unique(array_map(static fn (Contributor $person): string => $person->name, $people))),
            $this->contributors,
        );
    }
}
