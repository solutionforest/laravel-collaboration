<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Broadcasting\RoomNotifier;
use SolutionForest\Collaboration\Rooms\RoomResolver;
use SolutionForest\Collaboration\Support\Data;

/**
 * Call `Permissions::changed()` when who may edit which field changes, for
 * example after changing a role. Open tabs re-render and get a new token on
 * their next sync, instead of when the old one expires.
 */
final class Permissions
{
    private const KEY = 'collaboration:permissions';

    /** Pass a record to refresh only the people editing it. */
    public static function changed(?Model $record = null): void
    {
        $room = $record === null ? null : app(RoomResolver::class)->forRecord($record);
        $cache = self::cache();

        $cache->forever(self::key($room), Data::int($cache->get(self::key($room))) + 1);

        if ($room !== null) {
            app(RoomNotifier::class)->refresh($room);
        }
    }

    /**
     * Whether a user may edit this record live. Policies opt in by defining a
     * `collaborate` ability; without one, whoever may edit may collaborate.
     * Those who may not still edit, and their changes merge on save.
     */
    public static function mayCollaborate(Model $record, ?Authenticatable $user = null): bool
    {
        $user ??= Collaboration::user();

        if ($user === null) {
            return false;
        }

        $gate = Gate::forUser($user);
        $policy = $gate->getPolicyFor($record);

        if (! $gate->has('collaborate') && ! (is_object($policy) && method_exists($policy, 'collaborate'))) {
            return true;
        }

        return $gate->allows('collaborate', $record);
    }

    /** What a token issued now must still match. */
    public static function version(string $room): string
    {
        $cache = self::cache();

        return Data::int($cache->get(self::key(null))).'.'.Data::int($cache->get(self::key($room)));
    }

    private static function key(?string $room): string
    {
        return self::KEY.($room === null ? '' : ':'.$room);
    }

    private static function cache(): Repository
    {
        return Cache::store(Data::nullableString(Collaboration::config('cache_store')));
    }
}
