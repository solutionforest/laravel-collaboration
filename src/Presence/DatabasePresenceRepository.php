<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Lottery;
use SolutionForest\Collaboration\Broadcasting\Audience;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Enums\PresenceChange;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/** One row per tab, so many heartbeats at once never overwrite each other. */
class DatabasePresenceRepository implements PresenceRepository
{
    private const LIMIT = 500;

    public function touch(Participant $participant): PresenceChange
    {
        $row = Data::map(Database::table('participants')
            ->where('room', $participant->room)
            ->where('client', $participant->client)
            ->first());

        $now = now()->getTimestamp();
        $change = ParticipantRecord::change($participant, $row, $now, $this->timeout());

        // An unchanged heartbeat only has to be written before it would time out.
        if ($change === PresenceChange::None && Data::int($row['seen_at'] ?? null) > $now - intdiv($this->timeout(), 4)) {
            return $change;
        }

        $values = [...ParticipantRecord::identity($participant), ...ParticipantRecord::state($participant)];

        Database::table('participants')->upsert(
            [['room' => $participant->room, 'client' => $participant->client, ...$values, 'seen_at' => $now]],
            ['room', 'client'],
            [...array_keys($values), 'seen_at'],
        );

        Lottery::odds(1, 500)->winner(function () use ($now): void {
            Database::table('participants')->where('seen_at', '<', $now - 86400)->delete();
        })->choose();

        return $change;
    }

    public function leave(string $room, int $client): void
    {
        Database::table('participants')->where('room', $room)->where('client', $client)->delete();
    }

    public function inRoom(string $room): array
    {
        return $this->get($this->present()->where('room', $room));
    }

    public function inScope(string $scope): array
    {
        return $this->get($this->present()->where('scope', $scope));
    }

    public function inRooms(array $rooms): array
    {
        $people = array_fill_keys($rooms, []);

        if ($rooms === []) {
            return $people;
        }

        foreach ($this->get($this->present()->whereIn('room', $rooms), self::LIMIT * 4) as $participant) {
            $people[$participant->room][] = $participant;
        }

        return $people;
    }

    public function hasRecentEditors(string $room, int $seconds): bool
    {
        return Database::table('participants')
            ->where('room', $room)
            ->where('mode', PresenceMode::Editing->value)
            ->where('seen_at', '>=', now()->getTimestamp() - $seconds)
            ->exists();
    }

    public function isPresent(string $room, string $userKey, string $audience): bool
    {
        return $this->present()->where('room', $room)->where('user_key', $userKey)->where('audience', $audience)->exists();
    }

    public function audiences(string $room): array
    {
        $audiences = [];

        foreach ($this->present()->where('room', $room)->whereNotNull('audience')->distinct()->pluck('readable') as $encoded) {
            $lists = Data::decode($encoded);
            $audience = new Audience($room, Data::strings($lists['readable'] ?? null), Data::strings($lists['visible'] ?? null));
            $audiences[$audience->key()] = $audience;
        }

        return array_values($audiences);
    }

    private function present(): Builder
    {
        return Database::table('participants')->where('seen_at', '>=', now()->getTimestamp() - $this->timeout());
    }

    private function timeout(): int
    {
        return ParticipantRecord::timeout();
    }

    /** @return list<Participant> */
    private function get(Builder $query, int $limit = self::LIMIT): array
    {
        $people = [];

        foreach ($query->orderBy('id')->limit($limit)->get() as $row) {
            $people[] = ParticipantRecord::participant(Data::map($row));
        }

        return $people;
    }
}
