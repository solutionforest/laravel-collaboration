<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Events\DraftEdited;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/**
 * With `audit()` on: who changed which field of a record, and in which
 * minute. Not every keystroke: one row per person, field and minute, saved
 * or not, and a `DraftEdited` event for each.
 */
final readonly class EditTrail
{
    private const MINUTE = 60;

    /** @param list<string> $fields */
    public function record(string $room, Model $record, Contributor $by, array $fields): void
    {
        $minute = intdiv($by->at, self::MINUTE) * self::MINUTE;

        foreach ($fields as $field) {
            $isNew = Database::table('edits')->insertOrIgnore([
                'room' => $room,
                'record_type' => $record->getMorphClass(),
                'record_key' => Data::string($record->getKey()),
                'user_type' => $by->type,
                'user_id' => (string) $by->id,
                'user_name' => $by->name,
                'field' => $field,
                'minute' => $minute,
            ]) > 0;

            if ($isNew) {
                event(new DraftEdited($record, $by, $field, $minute));
            }
        }
    }

    /**
     * The newest first.
     *
     * @return list<array{user_type: string, user_id: string, user_name: string, field: string, at: CarbonImmutable}>
     */
    public function for(Model $record, int $limit = 500): array
    {
        $rows = Database::table('edits')
            ->where('record_type', $record->getMorphClass())
            ->where('record_key', Data::string($record->getKey()))
            ->orderByDesc('minute')
            ->orderBy('field')
            ->limit($limit)
            ->get(['user_type', 'user_id', 'user_name', 'field', 'minute']);

        $trail = [];

        foreach ($rows as $row) {
            $row = Data::map($row);
            $trail[] = [
                'user_type' => Data::string($row['user_type'] ?? null),
                'user_id' => Data::string($row['user_id'] ?? null),
                'user_name' => Data::string($row['user_name'] ?? null),
                'field' => Data::string($row['field'] ?? null),
                'at' => CarbonImmutable::createFromTimestamp(Data::int($row['minute'] ?? null)),
            ];
        }

        return $trail;
    }
}
