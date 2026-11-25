<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Broadcasting;

use SolutionForest\Collaboration\Presence\Participant;

/** @internal what one request changed in one room */
final class RoomChanges
{
    public int $epoch = 0;

    /** @var list<array{sequence: int, operations: list<array<string, mixed>>}> */
    public array $updates = [];

    /** @var list<string> */
    public array $dirty = [];

    public bool $hasDocumentChanged = false;

    public bool $refresh = false;

    /** The room's relation version, when a relation manager changed something. */
    public ?int $relations = null;

    public int $presence = 0;

    public ?Participant $participant = null;
}
