<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonSerializable;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Presence\Participant;
use SolutionForest\Collaboration\Rooms\RoomAuthorizer;
use SolutionForest\Collaboration\Rooms\RoomResolver;
use SolutionForest\Collaboration\Support\Data;

/**
 * Who is on each record of a list or a table, kept up to date while the
 * page is open:
 *
 *     Inertia::render('Posts/Index', ['posts' => $posts, 'presence' => Collaboration::presence($posts)]);
 *
 * @implements Arrayable<string, mixed>
 */
final class RecordPresence implements Arrayable, JsonSerializable
{
    /** @param iterable<Model> $records */
    public function __construct(private readonly iterable $records) {}

    /**
     * The rooms of the records this user may view, by record key.
     *
     * @return array<string, string>
     */
    public function rooms(Authenticatable $user): array
    {
        $rooms = [];

        foreach ($this->records as $record) {
            if (RoomAuthorizer::allows($user, $record, 'view')) {
                $rooms[Data::string($record->getKey())] = app(RoomResolver::class)->forRecord($record);
            }
        }

        return $rooms;
    }

    /**
     * Everybody on each record now, leaving out this user.
     *
     * @param  array<string, string>  $rooms  record key => room
     * @return array<string, list<array<string, mixed>>>
     */
    public static function people(array $rooms, Identity $viewer): array
    {
        $present = app(PresenceRepository::class)->inRooms(array_values($rooms));
        $keys = array_flip($rooms);
        $people = [];

        foreach ($present as $room => $participants) {
            $others = array_values(array_filter($participants, static fn (Participant $participant): bool => $participant->userKey !== $viewer->key));
            $colors = Collaboration::palette()->assign(array_map(static fn (Participant $participant): string => $participant->userKey, $others));
            $seen = [];

            foreach ($others as $participant) {
                // One entry per person, however many tabs they have open.
                if (isset($seen[$participant->userKey])) {
                    continue;
                }

                $seen[$participant->userKey] = true;
                $people[$keys[$room]][] = [
                    'key' => $participant->userKey,
                    'name' => $participant->name,
                    'initials' => $participant->initials(),
                    'avatar' => $participant->avatar,
                    'color' => $colors[$participant->userKey],
                    'mode' => $participant->mode->value,
                    'idle' => $participant->idle,
                ];
            }
        }

        return $people;
    }

    public function toArray(): array
    {
        $user = Collaboration::user();

        if ($user === null) {
            return [];
        }

        $rooms = $this->rooms($user);

        return [
            'url' => route('collaboration.presence'),
            'csrf' => csrf_token(),
            'token' => Crypt::encryptString(Data::encode([
                'u' => Data::string($user->getAuthIdentifier()),
                'g' => auth()->getDefaultDriver(),
                'r' => $rooms,
                'e' => now()->getTimestamp() + max(1, Data::int(Collaboration::config('token_lifetime'), 900)),
            ])),
            'interval' => max(3000, Collaboration::$pollingInterval * 3),
            'people' => (object) self::people($rooms, Identity::for($user)),
            'strings' => trans('collaboration::collaboration.client'),
        ];
    }

    public function jsonSerialize(): mixed
    {
        $config = $this->toArray();

        return $config === [] ? null : $config;
    }
}
