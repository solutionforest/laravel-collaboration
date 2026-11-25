<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;
use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;
use Throwable;

/**
 * Images stored as soon as they are inserted into a shared draft, so that
 * anybody's save keeps them. Until a save does, they belong to nobody: a
 * discarded draft deletes them, and `prune()` deletes the ones left behind.
 * Once saved, an image belongs to the record, as any other Filament upload.
 */
final class SharedUploads
{
    /** Where a list of files keeps each item's stored path, next to the item. */
    public const FILES = "\0files";

    public function track(string $room, string $field, string $disk, string $path): void
    {
        Database::table('uploads')->insert([
            'room' => $room,
            'field' => $field,
            'disk' => $disk,
            'path' => $path,
            'created_at' => now()->getTimestamp(),
        ]);
    }

    /** After a save: what the record now holds is the record's. Nothing is deleted, as undo may bring an image back. */
    public function release(string $room): void
    {
        $this->settle($this->uploads()->where('room', $room)->get()->all(), deleteUnused: false);
    }

    /** A record deleted for good: the files only its drafts had, whatever drafts or history say. */
    public function forget(string $room): int
    {
        return $this->settle($this->uploads()->where('room', $room)->get()->all(), keepDrafts: false, keepHistory: false);
    }

    /**
     * After a discard: the files only the discarded fields had. Files still in
     * the draft, such as a colleague's in a field this person cannot edit, stay.
     *
     * @param  list<string>|null  $fields  the fields discarded; null for all
     */
    public function discard(string $room, ?array $fields = null): int
    {
        $uploads = $this->uploads()->where('room', $room);

        if ($fields !== null) {
            $uploads->whereIn('field', $fields === [] ? [''] : $fields);
        }

        return $this->settle($uploads->get()->all());
    }

    /** Deletes images inserted more than `$seconds` ago that neither a record nor a draft uses. */
    public function prune(int $seconds): int
    {
        $deleted = 0;

        $this->uploads()
            ->where('created_at', '<=', now()->getTimestamp() - $seconds)
            // By id: rows are deleted on the way, and offsets would skip others.
            ->chunkById(500, function ($uploads) use (&$deleted): void {
                $deleted += $this->settle($uploads->all());
            });

        return $deleted;
    }

    /**
     * The files stored for a draft's field, which its items may then be.
     *
     * @return list<string>
     */
    public function storedFor(string $room, string $field): array
    {
        return Data::strings($this->uploads()->where('room', $room)->where('field', $field)->pluck('path')->all());
    }

    /** Whether a file was stored for some shared draft, and not yet saved or deleted. */
    public function isStored(string $path): bool
    {
        return $this->uploads()->where('path', $path)->exists();
    }

    /**
     * The files a field of a draft refers to: a rich editor's images, or an upload field's files.
     *
     * @return list<string>
     */
    public static function filesIn(Document $document, string $field): array
    {
        if (! $document->has($field)) {
            return [];
        }

        return match ($document->kind($field)) {
            FieldKind::RichText => self::images($document->value($field)),
            FieldKind::Items => array_values(array_filter(Data::list(array_values(Data::map($document->value($field)))), is_string(...))),
            default => [],
        };
    }

    /**
     * The image ids in a rich editor's document.
     *
     * @return list<string>
     */
    public static function images(mixed $node): array
    {
        $node = Data::map($node);
        $id = Data::nullableString(Data::map($node['attrs'] ?? null)['id'] ?? null);
        $images = ($node['type'] ?? null) === 'image' && $id !== null ? [$id] : [];

        foreach (Data::list($node['content'] ?? null) as $child) {
            array_push($images, ...self::images($child));
        }

        return $images;
    }

    /**
     * @param  array<mixed>  $uploads
     * @return int how many files were deleted
     */
    private function settle(array $uploads, bool $keepDrafts = true, bool $deleteUnused = true, bool $keepHistory = true): int
    {
        $drafts = [];
        $forget = [];
        $deleted = 0;

        foreach ($uploads as $upload) {
            $upload = Data::map($upload);
            $room = Data::string($upload['room'] ?? null);
            $field = Data::string($upload['field'] ?? null);
            $path = Data::string($upload['path'] ?? null);

            $drafts[$room] ??= $this->draft($room);
            [$draft, $saved] = $drafts[$room] ?? [null, null];

            if ($saved !== null && in_array($path, self::filesIn($saved, $field), true)) {
                $forget[] = $upload['id'];
            } elseif ($keepDrafts && $draft !== null && in_array($path, self::filesIn($draft, $field), true)) {
                continue;
            } elseif ($keepHistory && $this->isInHistory($room, $path)) {
                // A kept version has it: restoring that version must find the file.
                continue;
            } elseif ($deleteUnused) {
                try {
                    Storage::disk(Data::string($upload['disk'] ?? null))->delete($path);
                    $deleted++;
                } catch (Throwable $exception) {
                    // A disk that is gone or failing: try again next time.
                    report($exception);

                    continue;
                }

                $forget[] = $upload['id'];
            }
        }

        foreach (array_chunk($forget, 500) as $ids) {
            $this->uploads()->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    /** @return array{Document, Document}|null the draft and the last saved state, if the room has any */
    private function draft(string $room): ?array
    {
        $row = Data::map(Database::table('documents')->where('room', $room)->first(['state', 'saved_state']));

        if ($row === []) {
            return null;
        }

        return [Document::from(Data::decode($row['state'] ?? null)), Document::from(Data::decode($row['saved_state'] ?? null))];
    }

    /** Whether one of the room's kept versions, or its languages', still has this file. */
    private function isInHistory(string $room, string $path): bool
    {
        $rooms = Database::table('documents')->where('room', $room)->orWhere('parent_room', $room)->pluck('room')->all();
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr(Data::encode($path), 1, -1)).'%';

        return Database::table('versions')->whereIn('room', [$room, ...$rooms])->where('values', 'like', $pattern)->exists();
    }

    private function uploads(): Builder
    {
        return Database::table('uploads');
    }
}
