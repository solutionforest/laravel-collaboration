<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use SolutionForest\Collaboration\Enums\PresenceChange;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Support\Data;

/** A tab as presence stores keep it, and what changed since it was last kept. */
final class ParticipantRecord
{
    /** @return array{scope: string|null, user_key: string, name: string, avatar: string|null, mode: string, audience: string|null, readable: string|null} who the tab is, and what it may read */
    public static function identity(Participant $participant): array
    {
        return [
            'scope' => $participant->scope,
            'user_key' => $participant->userKey,
            'name' => mb_substr($participant->name, 0, 255),
            'avatar' => $participant->avatar,
            'mode' => $participant->mode->value,
            'audience' => $participant->audience?->key(),
            'readable' => $participant->audience === null ? null : Data::encode($participant->audience->toArray()),
        ];
    }

    /** @return array{field: string|null, cursor: string|null, idle: bool} where the tab is */
    public static function state(Participant $participant): array
    {
        return [
            'field' => $participant->field,
            'cursor' => $participant->cursor === null ? null : Data::encode($participant->cursor),
            'idle' => $participant->idle,
        ];
    }

    /**
     * What a heartbeat changes for everybody else, against what was kept.
     *
     * @param  array<string, mixed>  $kept  `[]` when nothing was
     */
    public static function change(Participant $participant, array $kept, int $now, int $timeout): PresenceChange
    {
        return match (true) {
            $kept === [] || Data::int($kept['seen_at'] ?? null) < $now - $timeout || self::differs($kept, self::identity($participant)) => PresenceChange::People,
            self::differs($kept, self::state($participant)) => PresenceChange::State,
            default => PresenceChange::None,
        };
    }

    /** @param array<string, mixed> $record */
    public static function participant(array $record): Participant
    {
        $cursor = Data::decode($record['cursor'] ?? null);

        return new Participant(
            room: Data::string($record['room'] ?? null),
            scope: Data::nullableString($record['scope'] ?? null),
            client: Data::int($record['client'] ?? null),
            userKey: Data::string($record['user_key'] ?? null),
            name: Data::string($record['name'] ?? null),
            avatar: Data::nullableString($record['avatar'] ?? null),
            mode: PresenceMode::tryFrom(Data::string($record['mode'] ?? null)) ?? PresenceMode::Viewing,
            field: Data::nullableString($record['field'] ?? null),
            cursor: $cursor === [] ? null : $cursor,
            idle: (bool) ($record['idle'] ?? false),
        );
    }

    public static function timeout(): int
    {
        return max(8, Data::int(config('collaboration.presence_timeout'), 20));
    }

    /**
     * @param  array<string, mixed>  $kept
     * @param  array<string, mixed>  $values
     */
    private static function differs(array $kept, array $values): bool
    {
        foreach ($values as $column => $value) {
            $stored = $kept[$column] ?? null;

            if (is_bool($value) ? (bool) $stored !== $value : $stored !== $value) {
                return true;
            }
        }

        return false;
    }
}
