<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Carbon\CarbonImmutable;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/**
 * The shared draft as it was: every few minutes while people edit, at each
 * save and around each restore. Kept per draft, the newest `KEEP`.
 */
final class DraftVersions
{
    public const EVERY = 300;

    public const KEEP = 100;

    /** Keeps the draft as it is now, unless it was kept moments ago or has not changed. */
    public function capture(SharedDraft $draft, string $reason, ?string $name = null, bool $always = false): void
    {
        $values = [];

        foreach ($draft->document->names() as $field) {
            $values[$field] = $draft->document->value($field);
        }

        $encoded = Data::encode($values);
        $latest = Data::map(Database::table('versions')->where('room', $draft->room)->orderByDesc('id')->first(['values', 'created_at']));
        $now = now()->getTimestamp();

        if ($latest !== [] && ($latest['values'] ?? null) === $encoded && $reason === 'edit') {
            return;
        }

        if (! $always && $latest !== [] && $now - Data::int($latest['created_at'] ?? null) < self::EVERY) {
            return;
        }

        Database::table('versions')->insert([
            'room' => $draft->room,
            'reason' => $reason,
            'user_name' => $name,
            'values' => $encoded,
            'created_at' => $now,
        ]);

        $cutoff = Database::table('versions')->where('room', $draft->room)->orderByDesc('id')->skip(self::KEEP)->value('id');

        if ($cutoff !== null) {
            Database::table('versions')->where('room', $draft->room)->where('id', '<=', $cutoff)->delete();
        }
    }

    /**
     * Newest first, with the fields each changed against the one before it.
     *
     * @param  array<string, string>  $labels  the fields this user may see, by name
     * @return list<array{id: int, reason: string, name: string|null, at: CarbonImmutable, changed: list<string>}>
     */
    public function list(string $room, array $labels, int $limit = 50): array
    {
        $rows = Database::table('versions')->where('room', $room)->orderByDesc('id')->limit($limit + 1)->get(['id', 'reason', 'user_name', 'values', 'created_at'])->all();
        $versions = [];

        foreach ($rows as $index => $row) {
            if ($index >= $limit) {
                break;
            }

            $row = Data::map($row);
            $values = array_intersect_key(Data::decode($row['values'] ?? null), $labels);
            $changed = [];

            // The first version kept has nothing before it to compare with: it is where the history starts.
            if (! isset($rows[$index + 1])) {
                $versions[] = self::version($row, []);

                continue;
            }

            $older = array_intersect_key(Data::decode(Data::map($rows[$index + 1])['values'] ?? null), $labels);

            foreach ($values as $field => $value) {
                if (Data::encode($value) !== Data::encode($older[$field] ?? null)) {
                    $changed[] = (string) $field;
                }
            }

            $versions[] = self::version($row, $changed);
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $changed
     * @return array{id: int, reason: string, name: string|null, at: CarbonImmutable, changed: list<string>}
     */
    private static function version(array $row, array $changed): array
    {
        return [
            'id' => Data::int($row['id'] ?? null),
            'reason' => Data::string($row['reason'] ?? null),
            'name' => Data::nullableString($row['user_name'] ?? null),
            'at' => CarbonImmutable::createFromTimestamp(Data::int($row['created_at'] ?? null)),
            'changed' => $changed,
        ];
    }

    /** @return array<string, mixed>|null the draft's values in a version */
    public function values(string $room, int $id): ?array
    {
        $values = Database::table('versions')->where('room', $room)->where('id', $id)->value('values');

        return $values === null ? null : Data::decode($values);
    }

    /** A record that is gone for good takes its versions with it. */
    public function forget(string $room): void
    {
        Database::table('versions')->where('room', $room)->delete();
    }
}
