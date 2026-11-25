<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Support\Data;

/** A locked row of the documents table, hydrated. */
final class SharedDraft
{
    /** @var list<array{sequence: int, operations: list<array<string, mixed>>}> appended in this transaction */
    public array $appended = [];

    /** @var array<string, array<string, Contributor>> by field, then by user, since the field was last saved */
    public array $contributors = [];

    public string $room = '';

    /** For one language's draft: the record's room. */
    public ?string $parentRoom = null;

    /**
     * @param  array<string, string>  $savedHashes
     * @param  list<string>  $dirty
     */
    public function __construct(
        public readonly int $id,
        public int $epoch,
        public int $sequence,
        public int $floor,
        public int $compactedAt,
        public int $version,
        public Document $document,
        public Document $saved,
        public array $savedHashes,
        public array $dirty,
        public ?string $savedBy,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $draft = new self(
            id: Data::int($row['id'] ?? null),
            epoch: Data::int($row['epoch'] ?? null),
            sequence: Data::int($row['sequence'] ?? null),
            floor: Data::int($row['floor'] ?? null),
            compactedAt: Data::int($row['compacted_at_sequence'] ?? null),
            version: Data::int($row['version'] ?? null),
            document: Document::from(Data::decode($row['state'] ?? null)),
            saved: Document::from(Data::decode($row['saved_state'] ?? null)),
            savedHashes: array_map(Data::string(...), Data::decode($row['saved_hashes'] ?? null)),
            dirty: Data::strings(Data::decode($row['dirty'] ?? null)),
            savedBy: Data::nullableString($row['saved_by'] ?? null),
        );

        $draft->room = Data::string($row['room'] ?? null);
        $draft->parentRoom = Data::nullableString($row['parent_room'] ?? null);

        foreach (Data::decode($row['contributors'] ?? null) as $field => $people) {
            foreach (Data::list($people) as $person) {
                $contributor = Contributor::from(Data::map($person));

                if ($contributor !== null) {
                    $draft->contributors[$field][$contributor->key()] = $contributor;
                }
            }
        }

        return $draft;
    }

    /** Records that somebody changed these fields. */
    public function contributed(Contributor $contributor, string ...$fields): void
    {
        foreach ($fields as $field) {
            $this->contributors[$field][$contributor->key()] = $contributor;
        }
    }

    /**
     * Who changed these fields since they were last saved, forgetting them.
     *
     * @return array<string, list<Contributor>>
     */
    public function takeContributors(string ...$fields): array
    {
        $taken = [];

        foreach ($fields as $field) {
            if (isset($this->contributors[$field])) {
                $taken[$field] = array_values($this->contributors[$field]);
                unset($this->contributors[$field]);
            }
        }

        return $taken;
    }

    public function refreshDirty(string ...$fields): void
    {
        $dirty = array_fill_keys($this->dirty, true);

        foreach ($fields as $field) {
            unset($dirty[$field]);

            if ($this->document->has($field) && ! $this->document->contentEquals($this->saved, $field)) {
                $dirty[$field] = true;
            }
        }

        $this->dirty = array_map(strval(...), array_keys($dirty));
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'epoch' => $this->epoch,
            'sequence' => $this->sequence,
            'floor' => $this->floor,
            'compacted_at_sequence' => $this->compactedAt,
            'version' => $this->version,
            'state' => Data::encode($this->document->toArray()),
            'saved_state' => Data::encode($this->saved->toArray()),
            'saved_hashes' => Data::encode($this->savedHashes),
            'dirty' => Data::encode($this->dirty),
            'saved_by' => $this->savedBy,
            'contributors' => $this->contributors === [] ? null : Data::encode(array_map(
                static fn (array $people): array => array_values(array_map(static fn (Contributor $person): array => $person->toArray(), $people)),
                $this->contributors,
            )),
        ];
    }
}
