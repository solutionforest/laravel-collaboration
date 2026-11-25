<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * What changed in a room, for one audience: new operations, and how people
 * moved. Anything that does not fit tells the browser to sync instead.
 */
final class RoomChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $channel,
        public readonly array $payload,
    ) {}

    public static function channel(string $room): string
    {
        return 'collaboration.'.$room;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
