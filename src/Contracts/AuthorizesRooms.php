<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Contracts;

use SolutionForest\Collaboration\Rooms\RoomAccess;
use SolutionForest\Collaboration\Rooms\RoomContext;

/** Decides, on every sync, whether a token's user may still be in its room. */
interface AuthorizesRooms
{
    public function authorize(RoomContext $context): RoomAccess;
}
