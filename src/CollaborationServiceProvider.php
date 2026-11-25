<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SolutionForest\Collaboration\Broadcasting\RoomChanged;
use SolutionForest\Collaboration\Broadcasting\RoomNotifier;
use SolutionForest\Collaboration\Commands\PruneUploadsCommand;
use SolutionForest\Collaboration\Contracts\AuthorizesRooms;
use SolutionForest\Collaboration\Contracts\PresenceRepository;
use SolutionForest\Collaboration\Presence\DatabasePresenceRepository;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Presence\RedisPresenceRepository;
use SolutionForest\Collaboration\Rooms\DeletedRecords;
use SolutionForest\Collaboration\Rooms\RoomAuthorizer;
use SolutionForest\Collaboration\Support\Data;

final class CollaborationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/collaboration.php', 'collaboration');

        $this->app->bindIf(PresenceRepository::class, static fn (): PresenceRepository => Collaboration::config('presence') === 'redis'
            ? new RedisPresenceRepository
            : new DatabasePresenceRepository);

        $this->app->bindIf(AuthorizesRooms::class, RoomAuthorizer::class);

        // One per request, so everything a request changes is pushed once.
        $this->app->scoped(RoomNotifier::class);
    }

    public function boot(): void
    {
        if (Collaboration::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'collaboration-migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'collaboration');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'collaboration');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'collaboration');

        $this->registerRoutes();
        self::registerChannel();
        $this->registerCommands();

        // Tells a room at once when its record is deleted, and cleans up after it.
        Event::listen('eloquent.deleted: *', static function (string $event, array $payload): void {
            app(DeletedRecords::class)->handle($event, $payload);
        });

        $this->publishes([__DIR__.'/../config/collaboration.php' => config_path('collaboration.php')], 'collaboration-config');
        $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/collaboration')], 'collaboration-translations');
    }

    private function registerRoutes(): void
    {
        RateLimiter::for('collaboration', static function (Request $request): Limit {
            $limit = max(60, Data::int(Collaboration::config('rate_limit'), 2400));

            return Limit::perMinute($limit)->by(Data::string($request->user()?->getAuthIdentifier(), (string) $request->ip()));
        });

        if (Collaboration::$registersRoutes && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/collaboration.php');
        }
    }

    /**
     * Only people syncing in a room, in the same audience, may hear its pushes:
     * they passed the room's authorisation to get there. Registered on the
     * default broadcaster, so call this again if you switch broadcaster at runtime.
     *
     * People may be signed in with any session guard, such as a Filament
     * panel's own, so every one of them is asked.
     */
    public static function registerChannel(): void
    {
        $guards = self::sessionGuards();

        Broadcast::channel(
            RoomChanged::channel('{room}').'.{audience}',
            static function (Authenticatable $user, string $room, string $audience) use ($guards): bool {
                $presence = app(PresenceRepository::class);
                $people = [$user];

                foreach ($guards as $guard) {
                    $signedIn = request()->user($guard);

                    if ($signedIn instanceof Authenticatable) {
                        $people[] = $signedIn;
                    }
                }

                foreach ($people as $person) {
                    if ($presence->isPresent($room, Identity::for($person)->key, $audience)) {
                        return true;
                    }
                }

                return false;
            },
            ['guards' => $guards],
        );
    }

    /**
     * The session guards, the default one first.
     *
     * @return list<string>
     */
    private static function sessionGuards(): array
    {
        $guards = [];

        foreach (Data::map(config('auth.guards')) as $name => $guard) {
            if ((Data::map($guard)['driver'] ?? null) === 'session') {
                $guards[] = (string) $name;
            }
        }

        $default = Data::nullableString(config('auth.defaults.guard'));

        if ($default !== null && in_array($default, $guards, true)) {
            $guards = [$default, ...array_values(array_diff($guards, [$default]))];
        }

        return $guards === [] && $default !== null ? [$default] : $guards;
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneUploadsCommand::class]);

        // Images inserted into drafts that were never saved.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (Collaboration::config('prune_uploads_after') !== null) {
                $schedule->command(PruneUploadsCommand::class)->daily()->withoutOverlapping()->onOneServer();
            }
        });
    }
}
