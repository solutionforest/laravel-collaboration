<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use SolutionForest\Collaboration\Broadcasting\RoomNotifier;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Support\Data;

/**
 * A counter per room, raised whenever somebody changes the record's related
 * records in a relation manager. Browsers that see it change refresh theirs.
 */
final class RelationVersions
{
    private const KEY = 'collaboration:relations:';

    private const REMEMBER_FOR = 86_400;

    public function __construct(private readonly RoomNotifier $notifier) {}

    public function version(string $room): int
    {
        return Data::int(self::cache()->get(self::KEY.$room));
    }

    public function touch(string $room): int
    {
        $cache = self::cache();

        $cache->add(self::KEY.$room, 0, self::REMEMBER_FOR);
        $version = Data::int($cache->increment(self::KEY.$room));

        $this->notifier->relationsChanged($room, $version);

        return $version;
    }

    private static function cache(): Repository
    {
        return Cache::store(Data::nullableString(Collaboration::config('cache_store')));
    }
}
