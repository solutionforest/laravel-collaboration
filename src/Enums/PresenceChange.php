<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Enums;

/** What others would notice about a heartbeat. */
enum PresenceChange: int
{
    case None = 0;

    /** The field, caret or idleness moved: others can update that person in place. */
    case State = 1;

    /** Somebody arrived, left or changed mode: others need the list again. */
    case People = 2;
}
