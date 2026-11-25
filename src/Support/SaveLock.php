<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Held from reading the database until the draft records what was saved, so
 * nobody mistakes a save in progress for an outside change.
 */
final class SaveLock
{
    private const SECONDS = 60;

    private const WAIT = 15;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws LockTimeoutException
     */
    public function block(string $room, Closure $callback): mixed
    {
        $lock = $this->lock($room);
        $lock->block(self::WAIT);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** Runs the callback only if nobody is saving right now. */
    public function attempt(string $room, Closure $callback): bool
    {
        $lock = $this->lock($room);

        if (! (bool) $lock->get()) {
            return false;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }

        return true;
    }

    private function lock(string $room): Lock
    {
        $store = Cache::store(Data::nullableString(config('collaboration.cache_store')))->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Collaboration needs a cache store that supports atomic locks.');
        }

        return $store->lock('collaboration:save:'.$room, self::SECONDS);
    }
}
