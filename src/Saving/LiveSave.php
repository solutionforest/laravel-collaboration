<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Saving;

use Illuminate\Contracts\Auth\Authenticatable;
use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Documents\DocumentRepository;
use SolutionForest\Collaboration\Documents\Fingerprint;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;

/** The part of a save that comes from the shared draft. */
final readonly class LiveSave
{
    /**
     * @param  list<string>  $fields
     * @param  array<string, FieldKind>  $kinds
     */
    public function __construct(
        public ?Document $snapshot = null,
        public array $fields = [],
        private array $kinds = [],
    ) {}

    /**
     * @param  string  $sync  what the browser reported
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed
     */
    public static function prepare(DocumentRepository $documents, string $room, int $client, string $sync, array $writable, array $allowed = [], ?Authenticatable $by = null): self
    {
        $sync = Data::decode($sync);
        $bound = Data::strings($sync['fields'] ?? null);

        if ($bound === [] || strlen(Data::encode($sync)) > 2_000_000) {
            return new self;
        }

        $snapshot = $documents->snapshot($room, $client, Data::int($sync['epoch'] ?? null), Data::list($sync['batches'] ?? null), $writable, $allowed, $by);

        if ($snapshot === null) {
            return new self;
        }

        $fields = [];

        foreach ($writable as $path => $kind) {
            $path = (string) $path;

            if (in_array($path, $bound, true) && $snapshot->kind($path) === $kind) {
                $fields[] = $path;
            }
        }

        return new self($snapshot, $fields, $writable);
    }

    /**
     * Only what really differs, so an unchanged value keeps its exact type and markup.
     *
     * @param  array<string, mixed>  $mine
     * @param  array<string, mixed>  $theirs
     * @return array<string, mixed>
     */
    public function valuesToPublish(array $mine, array $theirs): array
    {
        $values = [];

        foreach ($this->fields as $path) {
            $kind = $this->kinds[$path];
            $shared = $this->snapshot?->value($path);
            $hash = Fingerprint::value($kind, $shared);

            if (Fingerprint::value($kind, $theirs[$path] ?? null) === $hash) {
                $values[$path] = $theirs[$path] ?? null;
            } elseif (Fingerprint::value($kind, $mine[$path] ?? null) !== $hash) {
                $values[$path] = $shared;
            }
        }

        return $values;
    }

    /**
     * A temporary upload only exists in the uploader's page, so
     * only they can save it.
     *
     * @param  array<string, mixed>  $theirs
     * @param  array<string, mixed>  $uploads
     */
    public function hasSomeoneElsesUpload(array $theirs, array $uploads): bool
    {
        foreach ($this->fields as $path) {
            if ($this->kinds[$path] !== FieldKind::RichText) {
                continue;
            }

            $new = array_diff($this->temporaryImages($this->snapshot?->value($path)), $this->temporaryImages($theirs[$path] ?? null));

            foreach ($new as $id) {
                if (data_get($uploads, $path.'.'.$id) === null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function temporaryImages(mixed $node): array
    {
        $node = Data::map($node);
        $images = [];

        if (($node['type'] ?? null) === 'image') {
            $id = Data::string(Data::map($node['attrs'] ?? null)['id'] ?? null);

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) === 1) {
                $images[] = $id;
            }
        }

        foreach (Data::list($node['content'] ?? null) as $child) {
            array_push($images, ...$this->temporaryImages($child));
        }

        return $images;
    }
}
