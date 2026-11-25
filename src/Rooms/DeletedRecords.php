<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use SolutionForest\Collaboration\Broadcasting\RoomNotifier;
use SolutionForest\Collaboration\Comments\RecordComments;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Documents\DraftHeads;
use SolutionForest\Collaboration\Documents\DraftVersions;
use SolutionForest\Collaboration\Documents\SharedUploads;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;
use Throwable;

/**
 * What happens to a room when its record is deleted.
 *
 * Everybody in the room is told at once, and learns who deleted it. A draft
 * of a record that is gone for good is deleted with its unsaved images; a
 * soft-deleted record keeps its draft, so restoring it loses nothing.
 */
final readonly class DeletedRecords
{
    private const KEY = 'collaboration:deleted:';

    private const REMEMBER_FOR = 86_400;

    public function __construct(
        private RoomResolver $rooms,
        private RoomNotifier $notifier,
        private PresenceRepository $presence,
        private SharedUploads $uploads,
        private DraftVersions $versions,
        private RecordComments $comments,
        private DraftHeads $heads,
    ) {}

    /**
     * Listens to `eloquent.deleted: *`.
     *
     * @param  array<mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        foreach ($payload as $record) {
            if ($record instanceof Model) {
                $this->deleted($record);
            }
        }
    }

    public function deleted(Model $record): void
    {
        try {
            $room = $this->rooms->forRecord($record);
        } catch (Throwable) {
            // A model that cannot have a room, such as one without a key.
            return;
        }

        // Every deleted model passes here; only those with a room do anything.
        $isSoft = self::isSoftDeleting($record);

        if (! Database::table('documents')->where('room', $room)->orWhere('parent_room', $room)->exists() && $this->presence->inRoom($room) === []) {
            // Nobody is on it and there is no draft, but there can be comments.
            if (! $isSoft) {
                $record->getConnection()->afterCommit(fn () => $this->comments->forget($room));
            }

            return;
        }

        self::cache()->put(self::KEY.$room, ['name' => Identity::current()?->name, 'soft' => $isSoft], self::REMEMBER_FOR);

        // After the commit, or a browser asking at once would still find the record.
        $record->getConnection()->afterCommit(function () use ($room, $isSoft): void {
            if (! $isSoft) {
                $this->forget($room);
            }

            $this->notifier->refresh($room);
        });
    }

    /**
     * Whether a room's record was deleted. Only called for a token that
     * proves its user had access to the record.
     *
     * @return array{name: string|null, restorable: bool}|null
     */
    public function lookup(RoomContext $context): ?array
    {
        $model = $context->model;

        if ($model === null || $context->key === null) {
            return null;
        }

        $remembered = Data::map(self::cache()->get(self::KEY.$context->room));
        // Without scopes: soft deletes and tenancy would hide it. The room check below stands in for them.
        $record = $model::query()->withoutGlobalScopes()->whereKey($context->key)->first();

        if ($record instanceof Model && ! self::isTrashed($record)) {
            return null;
        }

        if ($record instanceof Model && ! hash_equals($this->rooms->forRecord($record), $context->room)) {
            return null;
        }

        return [
            'name' => Data::nullableString($remembered['name'] ?? null),
            'restorable' => $record instanceof Model && $this->canRestore($context, $record),
        ];
    }

    /** Restores a soft-deleted record for someone who may. */
    public function restore(RoomContext $context): bool
    {
        $model = $context->model;

        if ($model === null || $context->key === null) {
            return false;
        }

        $record = $model::query()->withoutGlobalScopes()->whereKey($context->key)->first();

        if (! $record instanceof Model || ! self::isTrashed($record) || ! $this->canRestore($context, $record)) {
            return false;
        }

        if (! hash_equals($this->rooms->forRecord($record), $context->room) || ! method_exists($record, 'restore')) {
            return false;
        }

        $record->restore();
        self::cache()->forget(self::KEY.$context->room);
        $this->notifier->refresh($context->room);

        return true;
    }

    private function forget(string $room): void
    {
        // The record's draft and each of its languages', with the images only they had;
        // the record's own images are the application's to delete.
        $this->comments->forget($room);
        $documents = Database::table('documents')->where('room', $room)->orWhere('parent_room', $room)->get(['id', 'room']);

        foreach ($documents as $document) {
            $document = Data::map($document);
            $this->uploads->forget(Data::string($document['room'] ?? null));
            $this->versions->forget(Data::string($document['room'] ?? null));
            Database::table('updates')->where('document_id', $document['id'] ?? null)->delete();
            Database::table('documents')->where('id', $document['id'] ?? null)->delete();
            $this->heads->changed(Data::string($document['room'] ?? null));
        }
    }

    private function canRestore(RoomContext $context, Model $record): bool
    {
        $user = $context->currentUser();

        return $user !== null && RoomAuthorizer::allows($user, $record, 'restore');
    }

    private static function isSoftDeleting(Model $record): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($record), true)
            && method_exists($record, 'isForceDeleting')
            && ! $record->isForceDeleting();
    }

    private static function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed() === true;
    }

    private static function cache(): Repository
    {
        return Cache::store(Data::nullableString(config('collaboration.cache_store')));
    }
}
