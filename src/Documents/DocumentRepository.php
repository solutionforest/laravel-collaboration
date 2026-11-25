<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use SolutionForest\Collaboration\Broadcasting\RoomNotifier;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Crdt\InvalidOperation;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/**
 * The only writer of shared drafts. Every change takes the document's row lock,
 * so the database provides the total order that all browsers replay.
 */
final class DocumentRepository
{
    private const KEEP_UPDATES = 500;

    private const COMPACT_AFTER_CHANGES = 200;

    private const MAX_OPERATIONS_PER_REQUEST = 20_000;

    public function __construct(
        private readonly PresenceRepository $presence,
        private readonly RoomNotifier $notifier,
        private readonly SharedUploads $uploads,
        private readonly DraftVersions $versions,
        private readonly DraftHeads $heads,
    ) {}

    /** Records that a draft is one language of a record's room. */
    public function link(string $draft, string $room): void
    {
        if ($draft !== $room) {
            Database::table('documents')->where('room', $draft)->whereNull('parent_room')->update(['parent_room' => $room]);
        }
    }

    public function exists(string $room): bool
    {
        return Database::table('documents')->where('room', $room)->exists();
    }

    /**
     * Brings the draft in line with the database. A change made outside the
     * draft (a modal, an import) is merged into what people are typing.
     *
     * Call this only while holding the room's save lock, so a half-finished
     * save is never mistaken for an outside change.
     *
     * @param  list<SharedField>  $fields
     */
    public function reconcile(string $room, array $fields): void
    {
        $this->transaction($room, function (SharedDraft $draft) use ($room, $fields): void {
            $this->compact($room, $draft);

            $operations = [];
            $touched = [];
            $hasStructureChanged = false;

            foreach ($fields as $field) {
                $name = $field->name;

                if (! $field->canBeShared()) {
                    if ($draft->document->has($name)) {
                        $draft->document = $this->without($draft->document, $name);
                        $draft->saved = $this->without($draft->saved, $name);
                        $hasStructureChanged = true;
                        $touched[] = $name;
                    }

                    continue;
                }

                $hash = Fingerprint::value($field->kind, $field->value);

                if ($draft->document->kind($name) !== $field->kind) {
                    $draft->document->create($name, $field->kind, $field->texts);
                    $draft->saved->create($name, $field->kind, $field->texts);
                    $seed = $draft->document->changes($name, $field->value);
                    $draft->document->apply($seed);
                    $draft->saved->apply($seed);
                    $hasStructureChanged = true;
                } elseif ($draft->document->texts($name) !== $field->texts) {
                    // The list's text sub-fields changed with a deploy: rebuild it, keeping unsaved edits.
                    $drafted = $draft->document->value($name);
                    $draft->saved->create($name, $field->kind, $field->texts);
                    $draft->saved->apply($draft->saved->changes($name, $field->value, $draft->document->clock()));
                    $draft->document->adopt($draft->saved, $name);
                    $draft->document->apply($draft->document->changes($name, $drafted));
                    $hasStructureChanged = true;
                } elseif (($draft->savedHashes[$name] ?? null) === $hash) {
                    continue;
                } elseif (Fingerprint::value($field->kind, $draft->document->value($name)) === $hash) {
                    $draft->saved->adopt($draft->document, $name);
                } else {
                    $changes = $draft->saved->changes($name, $field->value, $draft->document->clock());
                    $draft->document->apply($changes);
                    $draft->saved->apply($changes);
                    array_push($operations, ...$changes);
                }

                $draft->savedHashes[$name] = $hash;
                $touched[] = $name;
            }

            $this->append($draft, Document::SERVER, $operations);

            if ($hasStructureChanged) {
                $draft->floor = ++$draft->sequence;
            }

            if ($touched !== []) {
                $draft->refreshDirty(...$touched);
            }
        });
    }

    /**
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed
     * @param  list<mixed>  $batches
     * @return list<string> the fields that changed
     *
     * @throws InvalidOperation
     */
    public function receive(string $room, int $client, int $epoch, array $batches, array $writable, array $allowed = [], ?Authenticatable $by = null): array
    {
        if ($batches === []) {
            return [];
        }

        return $this->transaction($room, fn (SharedDraft $draft): array => $this->receiveInto($draft, $client, $epoch, $batches, $writable, $allowed, $by));
    }

    /**
     * Accepts what the saving browser had not sent yet and returns the draft
     * exactly as it is being saved. Null means this browser cannot be merged.
     *
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed
     * @param  list<mixed>  $batches
     */
    public function snapshot(string $room, int $client, int $epoch, array $batches, array $writable, array $allowed = [], ?Authenticatable $by = null): ?Document
    {
        try {
            return $this->transaction($room, function (SharedDraft $draft) use ($client, $epoch, $batches, $writable, $allowed, $by): Document {
                $this->receiveInto($draft, $client, $epoch, $batches, $writable, $allowed, $by);

                return clone $draft->document;
            });
        } catch (InvalidOperation) {
            return null;
        }
    }

    /**
     * Records that the database now holds `$fields`.
     *
     * @param  list<SharedField>  $fields
     * @param  list<string>  $published  fields that were saved from `$snapshot`
     * @param  list<string>  $overwritten  fields saved by an editor that was not live, whose value wins
     * @return array<string, list<Contributor>> who changed each saved field since it was last saved
     */
    public function markSaved(string $room, array $fields, ?Document $snapshot, array $published, array $overwritten, string $name, int $client, ?Authenticatable $by = null): array
    {
        return $this->transaction($room, function (SharedDraft $draft) use ($fields, $snapshot, $published, $overwritten, $name, $client, $by): array {
            $operations = [];
            $touched = [];

            foreach ($fields as $field) {
                $path = $field->name;

                if ($draft->document->kind($path) !== $field->kind || ! $field->canBeShared()) {
                    continue;
                }

                if ($snapshot !== null && in_array($path, $published, true)) {
                    $draft->saved->adopt($snapshot, $path);

                    // Saving turns a temporary upload into a stored path, which everybody needs.
                    if ($field->kind === FieldKind::RichText && SharedUploads::images($snapshot->value($path)) !== SharedUploads::images($field->value)) {
                        $changes = $draft->saved->changes($path, $field->value, $draft->document->clock());
                        $draft->document->apply($changes);
                        $draft->saved->apply($changes);
                        array_push($operations, ...$changes);
                    }
                } elseif (in_array($path, $overwritten, true)) {
                    $changes = $draft->document->changes($path, $field->value);
                    $draft->document->apply($changes);
                    $draft->saved->adopt($draft->document, $path);
                    array_push($operations, ...$changes);
                } else {
                    continue;
                }

                $draft->savedHashes[$path] = Fingerprint::value($field->kind, $field->value);
                $touched[] = $path;
            }

            $this->append($draft, Document::SERVER, $operations);
            $draft->refreshDirty(...$touched);
            $draft->version++;
            $draft->savedBy = Data::encode(['name' => $name, 'client' => $client]);
            $this->versions->capture($draft, 'save', $name, always: true);

            // Whoever overwrote a field without live editing changed it too.
            if ($by !== null) {
                $draft->contributed(Contributor::of($by), ...array_values(array_intersect($overwritten, $touched)));
            }

            return $draft->takeContributors(...$touched);
        });
    }

    /** Tells everybody on the record that it was saved from outside the draft. */
    public function announceSave(string $room, string $name): void
    {
        if (! $this->exists($room)) {
            return;
        }

        $this->transaction($room, function (SharedDraft $draft) use ($name): void {
            $draft->version++;
            $draft->savedBy = Data::encode(['name' => $name, 'client' => Document::SERVER]);
        });
    }

    /**
     * Turns these fields of the draft back into a version's values, for
     * everybody. The draft as it was is kept first, so a restore can be undone.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $fields  the fields this user may write
     * @return list<string> the fields that changed
     */
    public function restore(string $room, array $values, array $fields, string $name): array
    {
        return $this->transaction($room, function (SharedDraft $draft) use ($values, $fields, $name): array {
            $this->versions->capture($draft, 'before', $name, always: true);

            $operations = [];
            $touched = [];

            foreach ($fields as $field) {
                if (! $draft->document->has($field) || ! array_key_exists($field, $values)) {
                    continue;
                }

                $changes = $draft->document->changes($field, $values[$field]);

                if ($changes !== []) {
                    $draft->document->apply($changes);
                    array_push($operations, ...$changes);
                    $touched[] = $field;
                }
            }

            $this->append($draft, Document::SERVER, $operations);
            $draft->refreshDirty(...$touched);
            $this->versions->capture($draft, 'restore', $name, always: true);

            return $touched;
        });
    }

    /** @param list<string> $fields */
    public function discard(string $room, array $fields): void
    {
        $this->transaction($room, function (SharedDraft $draft) use ($fields): void {
            // Colleagues' unsaved typing goes too: keep it in the history, as a restore does.
            $this->versions->capture($draft, 'discard', null, always: true);

            $operations = [];

            foreach ($fields as $field) {
                if ($draft->document->has($field)) {
                    $changes = $draft->document->changes($field, $draft->saved->value($field));
                    $draft->document->apply($changes);
                    array_push($operations, ...$changes);
                }
            }

            $this->append($draft, Document::SERVER, $operations);
            $draft->dirty = array_values(array_diff($draft->dirty, $fields));
            $draft->takeContributors(...$fields);
        });
    }

    /**
     * What a browser is missing. Polling does not decode the document.
     *
     * @param  list<string>  $readable
     */
    public function changesSince(string $room, int $client, int $epoch, int $since, array $readable): DraftUpdate
    {
        $row = $this->heads->read($room, static fn (): array => Data::map(Database::table('documents')->where('room', $room)
            ->first(['id', 'epoch', 'sequence', 'floor', 'version', 'dirty', 'saved_by'])));

        if ($row === []) {
            return new DraftUpdate;
        }

        $currentEpoch = Data::int($row['epoch'] ?? null);
        $sequence = Data::int($row['sequence'] ?? null);
        $state = null;
        $acknowledged = 0;
        $operations = [];

        if ($readable !== [] && ($epoch !== $currentEpoch || $since < Data::int($row['floor'] ?? null) || $since > $sequence)) {
            $row = Data::map(Database::table('documents')->where('room', $room)->first());
            $document = Document::from(Data::decode($row['state'] ?? null));
            $state = $document->toArray($readable);
            $acknowledged = $document->lastBatch($client);
            $currentEpoch = Data::int($row['epoch'] ?? null);
            $sequence = Data::int($row['sequence'] ?? null);
        } elseif ($readable !== [] && $since < $sequence) {
            $updates = Database::table('updates')
                ->where('document_id', $row['id'] ?? null)
                ->where('sequence', '>', $since)
                ->where('sequence', '<=', $sequence)
                ->where('client', '!=', $client)
                ->orderBy('sequence')
                ->pluck('operations');

            // Old updates may have been pruned between the two reads.
            if ($since < Data::int(Database::table('documents')->where('id', $row['id'] ?? null)->value('floor'))) {
                return $this->changesSince($room, $client, 0, -1, $readable);
            }

            foreach ($updates as $encoded) {
                foreach (Data::list(Data::decode($encoded)) as $operation) {
                    $operation = Data::map($operation);

                    if (in_array(Data::string($operation['f'] ?? null), $readable, true)) {
                        $operations[] = $operation;
                    }
                }
            }
        }

        $savedBy = Data::decode($row['saved_by'] ?? null);

        return new DraftUpdate(
            epoch: $currentEpoch,
            sequence: $sequence,
            version: Data::int($row['version'] ?? null),
            state: $state,
            acknowledged: $acknowledged,
            operations: $operations,
            dirty: array_values(array_intersect(Data::strings(Data::decode($row['dirty'] ?? null)), $readable)),
            savedByName: Data::nullableString($savedBy['name'] ?? null),
            savedByClient: Data::int($savedBy['client'] ?? null),
        );
    }

    /**
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed
     * @param  list<mixed>  $batches
     * @return list<string> the fields that changed
     */
    private function receiveInto(SharedDraft $draft, int $client, int $epoch, array $batches, array $writable, array $allowed = [], ?Authenticatable $by = null): array
    {
        if ($batches === []) {
            return [];
        }

        if ($draft->epoch !== $epoch) {
            throw new InvalidOperation('The shared draft was compacted.');
        }

        if (count($batches) > self::MAX_OPERATIONS_PER_REQUEST) {
            throw new InvalidOperation('Too many batches.');
        }

        $accepted = [];
        $touched = [];
        $count = 0;

        foreach ($batches as $batch) {
            $batch = Data::map($batch);
            $count += count(Data::list($batch['ops'] ?? null));

            if ($count > self::MAX_OPERATIONS_PER_REQUEST) {
                throw new InvalidOperation('Too many operations.');
            }

            $operations = $draft->document->receive($client, Data::int($batch['n'] ?? null), $batch['ops'] ?? null, $writable, $this->withFiles($draft, $allowed));

            foreach ($operations as $operation) {
                $accepted[] = $operation;
                $touched[Data::string($operation['f'] ?? null)] = true;
            }
        }

        $this->append($draft, $client, $accepted);

        if ($touched !== []) {
            $draft->refreshDirty(...array_map(strval(...), array_keys($touched)));

            if ($by !== null) {
                $draft->contributed(Contributor::of($by), ...array_map(strval(...), array_keys($touched)));
            }

            $this->versions->capture($draft, 'edit', $by === null ? null : Contributor::of($by)->name);
        }

        return array_map(strval(...), array_keys($touched));
    }

    /** @param list<array<string, mixed>> $operations */
    private function append(SharedDraft $draft, int $client, array $operations): void
    {
        if ($operations === []) {
            return;
        }

        Database::table('updates')->insert([
            'document_id' => $draft->id,
            'sequence' => ++$draft->sequence,
            'client' => $client,
            'operations' => Data::encode($operations),
        ]);

        $draft->appended[] = ['sequence' => $draft->sequence, 'operations' => $operations];

        if ($draft->sequence % 50 === 0 && $draft->sequence > self::KEEP_UPDATES) {
            $oldest = $draft->sequence - self::KEEP_UPDATES;
            Database::table('updates')->where('document_id', $draft->id)->where('sequence', '<=', $oldest)->delete();
            $draft->floor = max($draft->floor, $oldest);
        }
    }

    private function compact(string $room, SharedDraft $draft): void
    {
        if ($draft->dirty !== [] || $draft->sequence - $draft->compactedAt < self::COMPACT_AFTER_CHANGES) {
            return;
        }

        // A language's draft: people are present in the record's room, not the draft's.
        if ($this->presence->hasRecentEditors($draft->parentRoom ?? $room, Data::int(Collaboration::config('compact_after'), 1800))) {
            return;
        }

        Database::table('updates')->where('document_id', $draft->id)->delete();

        $draft->document = new Document;
        $draft->saved = new Document;
        $draft->savedHashes = [];
        $draft->epoch++;
        $draft->floor = $draft->compactedAt = ++$draft->sequence;
    }

    private function without(Document $document, string $field): Document
    {
        $state = $document->toArray();
        unset($state['fields'][$field]);

        return Document::from($state);
    }

    /**
     * For upload fields: the files their items may be, which are those stored
     * for this draft and those the record or the draft already has.
     *
     * @param  array<string, list<string>>  $allowed
     * @return array<string, list<string>>
     */
    private function withFiles(SharedDraft $draft, array $allowed): array
    {
        foreach (array_keys($allowed) as $key) {
            $key = (string) $key;

            if (! str_ends_with($key, SharedUploads::FILES)) {
                continue;
            }

            $field = substr($key, 0, -strlen(SharedUploads::FILES));
            $allowed[$key] = array_values(array_unique([
                ...$this->uploads->storedFor($draft->room, $field),
                ...SharedUploads::filesIn($draft->saved, $field),
                ...SharedUploads::filesIn($draft->document, $field),
            ]));
        }

        return $allowed;
    }

    /**
     * @template TResult
     *
     * @param  Closure(SharedDraft): TResult  $callback
     * @return TResult
     */
    private function transaction(string $room, Closure $callback): mixed
    {
        $changed = null;

        $result = Database::connection()->transaction(function () use ($room, $callback, &$changed) {
            $empty = Data::encode((new Document)->toArray());

            // Both statements write, so SQLite takes its write lock before anything is read.
            $created = Database::table('documents')->insertOrIgnore([
                'room' => $room,
                'state' => $empty,
                'saved_state' => $empty,
                'saved_hashes' => '{}',
                'dirty' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Database::table('documents')->where('room', $room)->update(['updated_at' => now()]);

            $draft = SharedDraft::fromRow(Data::map(Database::table('documents')->where('room', $room)->lockForUpdate()->first()));
            $before = $draft->toRow();
            $result = $callback($draft);
            $after = $draft->toRow();

            if ($after !== $before) {
                Database::table('documents')->where('id', $draft->id)->update([...$after, 'updated_at' => now()]);
                $changed = $draft;
            }

            if ($created > 0 || $after !== $before) {
                $this->heads->changed($room);
            }

            return $result;
        }, attempts: 3);

        // After the commit, so whoever is told can already read the change.
        if ($changed !== null) {
            $this->notifier->documentChanged($room, $changed->epoch, $changed->appended, $changed->dirty, $changed->parentRoom);
        }

        return $result;
    }
}
