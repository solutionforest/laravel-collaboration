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

        $this->app->bindIf(PresenceRepository::class, static fn (): PresenceRepository => config('collaboration.presence') === 'redis'
            ? new RedisPresenceRepository
            : new DatabasePresenceRepository);

        $this->app->bindIf(AuthorizesRooms::class, RoomAuthorizer::class);

        // One per request, so everything a request changes is pushed once.
        $this->app->scoped(RoomNotifier::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'collaboration');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'collaboration');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'collaboration');

        $this->registerRoutes();
        $this->registerChannel();
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
            $limit = max(60, Data::int(config('collaboration.rate_limit'), 2400));

            return Limit::perMinute($limit)->by(Data::string($request->user()?->getAuthIdentifier(), (string) $request->ip()));
        });

        if (Collaboration::$registersRoutes && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/collaboration.php');
        }
    }

    /**
     * Only people syncing in a room, in the same audience, may hear its pushes:
     * they passed the room's authorisation to get there.
     */
    private function registerChannel(): void
    {
        Broadcast::channel(
            RoomChanged::channel('{room}').'.{audience}',
            static fn (Authenticatable $user, string $room, string $audience): bool => app(PresenceRepository::class)->isPresent($room, Identity::for($user)->key, $audience),
        );
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneUploadsCommand::class]);

        // Images inserted into drafts that were never saved.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (config('collaboration.prune_uploads_after') !== null) {
                $schedule->command(PruneUploadsCommand::class)->daily()->withoutOverlapping()->onOneServer();
            }
        });
    }
}
