<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Support\Data;

/** Room ids are opaque and include the database, so tenant databases cannot collide. */
final class RoomResolver
{
    public function forRecord(Model $record): string
    {
        return hash('sha256', Data::encode([
            'record',
            Collaboration::roomScope($record),
            $record->getConnection()->getDatabaseName(),
            $record->getTable(),
            $record->getMorphClass(),
            Data::string($record->getKey()),
        ]));
    }

    /** A room that is not one record, such as everybody on a "create" page. */
    public function forScope(string $subject): string
    {
        return hash('sha256', Data::encode([
            'scope',
            Collaboration::roomScope(),
            $subject,
        ]));
    }
}
