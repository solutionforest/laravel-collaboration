<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/**
 * Keeps each draft's head (its epoch, sequence, version and dirty fields) in a
 * shared cache, so a poll that has nothing new to fetch makes no database query.
 * Most polls are like that: most people on a record are reading, not typing.
 *
 * Every committed change gives the draft a new random token, and a head is
 * cached under the token that was current before it was read. A head is
 * therefore never older than the change that made its token, and a lost or
 * evicted key only means one more database read.
 *
 * On when the cache store is Redis or Memcached, or when the `draft_cache`
 * setting says so: a store each server keeps for itself would serve stale heads.
 */
final class DraftHeads
{
    private const TOKEN = 'collaboration:draft-token:';

    private const HEAD = 'collaboration:draft-head:';

    private const TOKEN_TTL = 86_400;

    private const HEAD_TTL = 600;

    /**
     * The head row of a room's draft: the cached one when its token is current.
     *
     * @param  Closure(): array<string, mixed>  $read  reads the head from the database
     * @return array<string, mixed>
     */
    public function read(string $room, Closure $read): array
    {
        if (! self::enabled()) {
            return $read();
        }

        $cache = self::cache();
        $token = $cache->get(self::TOKEN.$room);

        // No token yet: nothing has changed since the cache was emptied, so there is nothing to cache under.
        if (! is_string($token)) {
            return $read();
        }

        $head = $cache->get(self::HEAD.$room.':'.$token);

        if (is_array($head)) {
            return Data::map($head);
        }

        $head = $read();
        $cache->put(self::HEAD.$room.':'.$token, $head, self::HEAD_TTL);

        return $head;
    }

    /** The draft changed: once the transaction commits, cached heads are out of date. */
    public function changed(string $room): void
    {
        if (! self::enabled()) {
            return;
        }

        $renew = static fn () => self::cache()->put(self::TOKEN.$room, Str::random(16), self::TOKEN_TTL);
        $connection = Database::connection();

        // After the commit: a head read before it would otherwise be cached under the new token.
        if ($connection instanceof Connection) {
            $connection->afterCommit($renew);
        } else {
            $renew();
        }
    }

    public static function enabled(): bool
    {
        $setting = config('collaboration.draft_cache');

        if ($setting !== null && $setting !== '') {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        $store = Data::nullableString(config('collaboration.cache_store')) ?? Data::nullableString(config('cache.default'));
        $driver = $store === null ? null : config("cache.stores.{$store}.driver");

        return in_array($driver, ['redis', 'memcached'], true);
    }

    private static function cache(): Repository
    {
        return Cache::store(Data::nullableString(config('collaboration.cache_store')));
    }
}
