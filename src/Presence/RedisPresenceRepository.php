<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use SolutionForest\Collaboration\Broadcasting\Audience;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Enums\PresenceChange;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Support\Data;

/**
 * Presence in Redis, for many application servers and many people: no
 * database write per heartbeat. Per room, a hash of tabs and a sorted set
 * of when each was last seen; per resource, a sorted set of its tabs.
 *
 * Set `COLLABORATION_PRESENCE=redis`, and optionally
 * `COLLABORATION_REDIS_CONNECTION` (`default`).
 */
class RedisPresenceRepository implements PresenceRepository
{
    private const LIMIT = 500;

    private const PREFIX = 'collaboration:presence:';

    /** Keys of rooms nobody is in any more go away by themselves. */
    private const KEEP_FOR = 86_400;

    public function touch(Participant $participant): PresenceChange
    {
        $redis = $this->redis();
        $room = $participant->room;
        $kept = Data::decode($redis->command('hget', [self::key('room', $room), (string) $participant->client]));
        $now = now()->getTimestamp();
        $timeout = ParticipantRecord::timeout();
        $change = ParticipantRecord::change($participant, $kept, $now, $timeout);

        // An unchanged heartbeat only has to be written before it would time out.
        if ($change === PresenceChange::None && Data::int($kept['seen_at'] ?? null) > $now - intdiv($timeout, 4)) {
            return $change;
        }

        $record = [
            'room' => $room,
            'client' => $participant->client,
            ...ParticipantRecord::identity($participant),
            ...ParticipantRecord::state($participant),
            'seen_at' => $now,
            // The order people came in, as the database keeps it by row id.
            'joined' => isset($kept['joined']) && Data::int($kept['seen_at'] ?? null) >= $now - $timeout ? $kept['joined'] : microtime(true),
        ];
        $member = (string) $participant->client;
        $previousScope = Data::nullableString($kept['scope'] ?? null);

        // Only on a change, or every few seconds per tab: plain commands are fast enough.
        $redis->command('hset', [self::key('room', $room), $member, Data::encode($record)]);
        $redis->command('zadd', [self::key('seen', $room), $now, $member]);
        $redis->command('expire', [self::key('room', $room), self::KEEP_FOR]);
        $redis->command('expire', [self::key('seen', $room), self::KEEP_FOR]);

        if ($previousScope !== null && $previousScope !== $participant->scope) {
            $redis->command('zrem', [self::key('scope', $previousScope), $room.':'.$member]);
        }

        if ($participant->scope !== null) {
            $redis->command('zadd', [self::key('scope', $participant->scope), $now, $room.':'.$member]);
            $redis->command('expire', [self::key('scope', $participant->scope), self::KEEP_FOR]);
            $redis->command('zremrangebyscore', [self::key('scope', $participant->scope), '-inf', (string) ($now - $timeout * 3)]);
        }

        // Long enough for `hasRecentEditors()`, which keeps a busy draft from being compacted.
        $this->forgetGone($room, $now - max($timeout * 3, Data::int(Collaboration::config('compact_after'), 1800)));

        return $change;
    }

    public function leave(string $room, int $client): void
    {
        $redis = $this->redis();
        $kept = Data::decode($redis->command('hget', [self::key('room', $room), (string) $client]));
        $scope = Data::nullableString($kept['scope'] ?? null);

        $redis->command('hdel', [self::key('room', $room), (string) $client]);
        $redis->command('zrem', [self::key('seen', $room), (string) $client]);

        if ($scope !== null) {
            $redis->command('zrem', [self::key('scope', $scope), $room.':'.$client]);
        }
    }

    public function inRoom(string $room): array
    {
        return $this->present([$room])[$room];
    }

    public function inScope(string $scope): array
    {
        $since = now()->getTimestamp() - ParticipantRecord::timeout();
        $rooms = [];

        foreach (Data::strings($this->redis()->command('zrangebyscore', [self::key('scope', $scope), (string) $since, '+inf'])) as $member) {
            [$room, $client] = array_pad(explode(':', $member, 2), 2, '');
            $rooms[$room][] = $client;
        }

        $people = [];

        foreach ($rooms as $room => $clients) {
            foreach ($this->records((string) $room, $clients) as $record) {
                if (Data::nullableString($record['scope'] ?? null) === $scope && Data::int($record['seen_at'] ?? null) >= $since) {
                    $people[] = $record;
                }
            }
        }

        return self::participants($people, self::LIMIT);
    }

    public function inRooms(array $rooms): array
    {
        return $rooms === [] ? [] : $this->present($rooms, self::LIMIT * 4);
    }

    public function hasRecentEditors(string $room, int $seconds): bool
    {
        $since = now()->getTimestamp() - $seconds;
        $clients = Data::strings($this->redis()->command('zrangebyscore', [self::key('seen', $room), (string) $since, '+inf']));

        foreach ($this->records($room, $clients) as $record) {
            if (($record['mode'] ?? null) === PresenceMode::Editing->value) {
                return true;
            }
        }

        return false;
    }

    public function isPresent(string $room, string $userKey, string $audience): bool
    {
        foreach ($this->presentRecords($room) as $record) {
            if (($record['user_key'] ?? null) === $userKey && ($record['audience'] ?? null) === $audience) {
                return true;
            }
        }

        return false;
    }

    public function audiences(string $room): array
    {
        $audiences = [];

        foreach ($this->presentRecords($room) as $record) {
            if (($record['audience'] ?? null) === null) {
                continue;
            }

            $lists = Data::decode($record['readable'] ?? null);
            $audience = new Audience($room, Data::strings($lists['readable'] ?? null), Data::strings($lists['visible'] ?? null));
            $audiences[$audience->key()] = $audience;
        }

        return array_values($audiences);
    }

    /**
     * @param  list<string>  $rooms
     * @return array<string, list<Participant>>
     */
    private function present(array $rooms, int $limit = self::LIMIT): array
    {
        $people = array_fill_keys($rooms, []);
        $records = [];

        foreach ($rooms as $room) {
            array_push($records, ...$this->presentRecords($room));
        }

        foreach (self::participants($records, $limit) as $participant) {
            $people[$participant->room][] = $participant;
        }

        return $people;
    }

    /** @return list<array<string, mixed>> the tabs seen within the timeout */
    private function presentRecords(string $room): array
    {
        $since = now()->getTimestamp() - ParticipantRecord::timeout();
        $clients = Data::strings($this->redis()->command('zrangebyscore', [self::key('seen', $room), (string) $since, '+inf']));

        return array_values(array_filter(
            $this->records($room, $clients),
            static fn (array $record): bool => Data::int($record['seen_at'] ?? null) >= $since,
        ));
    }

    /**
     * @param  list<string>  $clients
     * @return list<array<string, mixed>>
     */
    private function records(string $room, array $clients): array
    {
        if ($clients === []) {
            return [];
        }

        $records = [];

        foreach (Data::list($this->redis()->command('hmget', [self::key('room', $room), $clients])) as $encoded) {
            $record = Data::decode($encoded);

            if ($record !== []) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * In the order they came in, as many as `$limit`.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<Participant>
     */
    private static function participants(array $records, int $limit): array
    {
        usort($records, static fn (array $a, array $b): int => (float) Data::string($a['joined'] ?? null, '0') <=> (float) Data::string($b['joined'] ?? null, '0'));

        return array_map(ParticipantRecord::participant(...), array_slice($records, 0, $limit));
    }

    /** Tabs not seen for a long time: their records go, so rooms never grow without end. */
    private function forgetGone(string $room, int $before): void
    {
        $redis = $this->redis();
        $gone = Data::strings($redis->command('zrangebyscore', [self::key('seen', $room), '-inf', (string) $before]));

        if ($gone === []) {
            return;
        }

        $redis->command('hdel', [self::key('room', $room), ...$gone]);
        $redis->command('zrem', [self::key('seen', $room), ...$gone]);
    }

    private function redis(): Connection
    {
        return Redis::connection(Data::nullableString(Collaboration::config('redis_connection')));
    }

    private static function key(string $kind, string $name): string
    {
        return self::PREFIX.$kind.':'.$name;
    }
}
