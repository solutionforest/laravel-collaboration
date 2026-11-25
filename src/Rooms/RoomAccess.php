<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Database\Eloquent\Model;

final readonly class RoomAccess
{
    private function __construct(
        public bool $isGranted,
        public ?Model $record = null,
    ) {}

    public static function granted(?Model $record = null): self
    {
        return new self(true, $record);
    }

    public static function denied(): self
    {
        return new self(false);
    }
}
