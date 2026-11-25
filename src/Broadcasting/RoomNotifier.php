<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Broadcasting;

use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Enums\PresenceChange;
use SolutionForest\Collaboration\Presence\Participant;
use SolutionForest\Collaboration\Support\Data;
use Throwable;

/**
 * Pushes room changes when `Collaboration::broadcast()` is on. Without it, or with the
 * WebSocket server down, browsers notice the same changes by polling.
 */
final class RoomNotifier
{
    /** Pusher and Reverb refuse messages over 10 KB by default. */
    private const MAX_BYTES = 9_000;

    /** @var array<string, RoomChanges>|null collected by `batch()` */
    private ?array $pending = null;

    public function __construct(
        private readonly BroadcastManager $broadcasting,
        private readonly PresenceRepository $presence,
    ) {}

    public static function isEnabled(): bool
    {
        return Collaboration::$broadcasts;
    }

    /**
     * @param  list<array{sequence: int, operations: list<array<string, mixed>>}>  $updates
     * @param  list<string>  $dirty
     */
    public function documentChanged(string $room, int $epoch, array $updates, array $dirty, ?string $parent = null): void
    {
        // One language's draft in a translated record's room: its tabs fetch it, the others ignore it.
        if ($parent !== null) {
            $this->collect($parent, static function (RoomChanges $changes): void {
                $changes->hasDocumentChanged = true;
            });

            return;
        }

        $this->collect($room, static function (RoomChanges $changes) use ($epoch, $updates, $dirty): void {
            $changes->epoch = $epoch;
            array_push($changes->updates, ...$updates);
            $changes->dirty = $dirty;
            $changes->hasDocumentChanged = true;
        });
    }

    /** Tells everybody in a room to fetch a new token now. */
    public function refresh(string $room): void
    {
        $this->collect($room, static function (RoomChanges $changes): void {
            $changes->refresh = true;
        });
    }

    /** Somebody changed the record's related records in a relation manager. */
    public function relationsChanged(string $room, int $version): void
    {
        $this->collect($room, static function (RoomChanges $changes) use ($version): void {
            $changes->relations = $version;
        });
    }

    public function presenceChanged(string $room, ?string $scope, PresenceChange $change, ?Participant $participant = null): void
    {
        if ($change === PresenceChange::None) {
            return;
        }

        $this->collect($room, static function (RoomChanges $changes) use ($change, $participant): void {
            $changes->presence = max($changes->presence, $change->value);
            $changes->participant = $participant ?? $changes->participant;
        });

        // A list or create page shows who is anywhere in the resource.
        if ($change === PresenceChange::People && $scope !== null && $scope !== $room) {
            $this->collect($scope, static function (RoomChanges $changes): void {
                $changes->presence = PresenceChange::People->value;
            });
        }
    }

    /**
     * One push per room for everything the callback changes.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function batch(Closure $callback): mixed
    {
        if ($this->pending !== null) {
            return $callback();
        }

        $this->pending = [];

        try {
            return $callback();
        } finally {
            $rooms = $this->pending ?? [];
            $this->pending = null;

            foreach ($rooms as $room => $changes) {
                $this->send((string) $room, $changes);
            }
        }
    }

    /** @param Closure(RoomChanges): void $update */
    private function collect(string $room, Closure $update): void
    {
        if ($room === '' || ! self::isEnabled()) {
            return;
        }

        if ($this->pending === null) {
            $changes = new RoomChanges;
            $update($changes);
            $this->send($room, $changes);

            return;
        }

        $this->pending[$room] ??= new RoomChanges;
        $update($this->pending[$room]);
    }

    private function send(string $room, RoomChanges $changes): void
    {
        try {
            $audiences = $this->presence->audiences($room);

            foreach ($audiences as $audience) {
                $event = new RoomChanged($audience->channel(), $this->payload($changes, $audience));

                // The browser that made the change already has it.
                $this->broadcasting->event($event->dontBroadcastToCurrentUser());
            }
        } catch (Throwable $exception) {
            // A WebSocket server that is down must not stop editing: polling still works.
            report($exception);
        }
    }

    /** @return array<string, mixed> */
    private function payload(RoomChanges $changes, Audience $audience): array
    {
        $payload = [];

        if ($changes->hasDocumentChanged && $changes->updates !== []) {
            $operations = [];

            foreach ($changes->updates as $update) {
                foreach ($update['operations'] as $operation) {
                    if (in_array(Data::string($operation['f'] ?? null), $audience->readable, true)) {
                        $operations[] = $operation;
                    }
                }
            }

            $payload = [
                'epoch' => $changes->epoch,
                'from' => $changes->updates[0]['sequence'] - 1,
                'to' => $changes->updates[count($changes->updates) - 1]['sequence'],
                'operations' => $operations,
                'dirty' => array_values(array_intersect($changes->dirty, $audience->readable)),
            ];
        } elseif ($changes->hasDocumentChanged) {
            $payload['sync'] = true;
        }

        if ($changes->refresh) {
            $payload['refresh'] = true;
        }

        if ($changes->relations !== null) {
            $payload['relations'] = $changes->relations;
        }

        if ($changes->presence === PresenceChange::People->value) {
            $payload['people'] = true;
        } elseif ($changes->presence === PresenceChange::State->value && $changes->participant !== null) {
            $person = $changes->participant;
            $field = in_array($person->field, $audience->visible, true) ? $person->field : null;

            $payload['person'] = [
                'client' => $person->client,
                'field' => $field,
                'cursor' => $field !== null && in_array($field, $audience->readable, true) ? $person->cursor : null,
                'idle' => $person->idle,
            ];
        }

        // Too large to push: the browser fetches it instead.
        if (strlen(Data::encode($payload)) > self::MAX_BYTES) {
            unset($payload['operations'], $payload['from'], $payload['dirty']);
            $payload['sync'] = true;
        }

        return $payload;
    }
}
