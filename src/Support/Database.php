<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SolutionForest\Collaboration\Collaboration;

/** @internal */
final class Database
{
    public static function connection(): ConnectionInterface
    {
        return DB::connection(self::connectionName());
    }

    public static function connectionName(): ?string
    {
        return Data::nullableString(Collaboration::config('connection'));
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table(self::tableName($table));
    }

    /** `collaboration_documents` by default; Filament Collaboration keeps its `filament_collaboration_` tables. */
    public static function tableName(string $table): string
    {
        return Data::string(Collaboration::config('table_prefix'), 'collaboration_').$table;
    }
}
