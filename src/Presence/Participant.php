<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use SolutionForest\Collaboration\Broadcasting\Audience;
use SolutionForest\Collaboration\Enums\PresenceMode;

/** One open tab. A person may be several participants. */
final readonly class Participant
{
    /** @param array<string, mixed>|null $cursor */
    public function __construct(
        public string $room,
        public ?string $scope,
        public int $client,
        public string $userKey,
        public string $name,
        public ?string $avatar = null,
        public PresenceMode $mode = PresenceMode::Viewing,
        public ?string $field = null,
        public ?array $cursor = null,
        public bool $idle = false,
        public ?Audience $audience = null,
    ) {}

    public function initials(): string
    {
        return Initials::for($this->name);
    }
}
