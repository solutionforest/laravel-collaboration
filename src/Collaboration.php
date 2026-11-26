<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Presence\Palette;
use SolutionForest\Collaboration\Support\Data;

/**
 * The one place an application tells the package how it works:
 *
 *     Collaboration::room($post)->text('title')->rich('body');
 *
 *     Collaboration::broadcast();
 *     Collaboration::nameUsersUsing(fn (User $user) => $user->display_name);
 */
final class Collaboration
{
    /** @var bool|(Closure(): bool) whether changes are pushed over Laravel broadcasting as well as polled */
    public static bool|Closure $broadcasts = false;

    /** How often, in milliseconds, an open page asks for changes when nothing is pushed. */
    public static int $pollingInterval = 1000;

    /** How many faces a presence bar shows before it says "+3". */
    public static int $avatarLimit = 5;

    /** @var bool|(Closure(): bool) whether each person's edits are written to the per-minute edit trail */
    public static bool|Closure $audits = false;

    /** @var bool|(Closure(): bool) whether browsers report the editor errors they recovered from */
    public static bool|Closure $reportsEditorErrors = false;

    /** @var list<string> the optional parts turned on, from `Features` */
    public static array $features = [];

    /** Whether the browser adds the package's small stylesheet. */
    public static bool $styles = true;

    /** Whether the package registers its sync route. */
    public static bool $registersRoutes = true;

    /** Whether the package's migrations run. */
    public static bool $runsMigrations = true;

    /** @var list<string>|(Closure(): list<string>) the colours people are told apart by */
    public static array|Closure $colors = Palette::DEFAULT;

    /** @var (Closure(): ?Authenticatable)|null */
    public static ?Closure $userResolver = null;

    /** @var (Closure(Model): iterable<Authenticatable>)|null */
    public static ?Closure $mentionableResolver = null;

    /** @var (Closure(string, mixed): mixed)|null */
    public static ?Closure $configResolver = null;

    /** @var (Closure(Authenticatable): ?string)|null */
    public static ?Closure $userNameResolver = null;

    /** @var (Closure(Authenticatable): ?string)|null */
    public static ?Closure $userAvatarResolver = null;

    /** @var (Closure(Authenticatable, Model|class-string<Model>, string): bool)|null */
    public static ?Closure $authorizer = null;

    /** @var (Closure(Model|null): mixed)|null */
    public static ?Closure $roomScopeResolver = null;

    /** Start describing the room people share while they edit a record. */
    public static function room(Model $record): Room
    {
        return new Room($record);
    }

    /**
     * Who is on each record of a list or table, kept up to date while the page is open.
     *
     * @param  iterable<Model>  $records
     */
    public static function presence(iterable $records): RecordPresence
    {
        return new RecordPresence($records);
    }

    /**
     * The room of everybody creating a record of this model: they see each
     * other, until each saves their own.
     *
     * @param  class-string<Model>  $model
     */
    public static function creating(string $model): Room
    {
        return new Room(new $model);
    }

    /**
     * Push changes as they happen, through the application's broadcaster.
     *
     * @param  bool|(Closure(): bool)  $broadcasts
     */
    public static function broadcast(bool|Closure $broadcasts = true): void
    {
        self::$broadcasts = $broadcasts;
    }

    public static function pollEvery(int $milliseconds): void
    {
        self::$pollingInterval = max(250, $milliseconds);
    }

    public static function showAvatars(int $limit): void
    {
        self::$avatarLimit = max(1, $limit);
    }

    /** @param list<string>|(Closure(): list<string>) $colors */
    public static function colors(array|Closure $colors): void
    {
        self::$colors = $colors;
    }

    /** @param bool|(Closure(): bool) $audits */
    public static function audit(bool|Closure $audits = true): void
    {
        self::$audits = $audits;
    }

    /** @param bool|(Closure(): bool) $reports */
    public static function reportEditorErrors(bool|Closure $reports = true): void
    {
        self::$reportsEditorErrors = $reports;
    }

    /**
     * Turns on optional parts: comments, history, uploads. Call it in a
     * service provider's `register()` or `boot()`.
     *
     *     Collaboration::features([Features::comments(), Features::history()]);
     *
     * @param  list<string>  $features
     */
    public static function features(array $features): void
    {
        self::$features = array_values(array_unique([...self::$features, ...$features]));
    }

    public static function hasFeature(string $feature): bool
    {
        return in_array($feature, self::$features, true);
    }

    /**
     * Whether comments are stored, so a deleted record's must go too: with
     * the feature on, or in an integration such as Filament that keeps its own tables.
     */
    public static function storesComments(): bool
    {
        return self::hasFeature(Features::COMMENTS) || ! self::$runsMigrations;
    }

    /**
     * Style everything yourself: the browser adds no stylesheet, and the
     * elements keep their `collab-` class names.
     */
    public static function withoutStyles(): void
    {
        self::$styles = false;
    }

    /** Register the sync route yourself, for example with other middleware. */
    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
    }

    /**
     * Where deployment settings come from, when not `config/collaboration.php`.
     *
     * @param  Closure(string $key, mixed $default): mixed  $callback
     */
    public static function readConfigUsing(Closure $callback): void
    {
        self::$configResolver = $callback;
    }

    /** A deployment setting, such as `cache_store`. */
    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$configResolver === null ? config("collaboration.{$key}", $default) : (self::$configResolver)($key, $default);
    }

    /**
     * Who may be @mentioned in a record's comments. Nobody, until you say:
     *
     *     Collaboration::mentionUsersUsing(fn (Post $post) => $post->team->users);
     *
     * Mentions arrive in the `CommentPosted` event, for your notifications.
     *
     * @param  Closure(Model $record): iterable<Authenticatable>  $callback
     */
    public static function mentionUsersUsing(Closure $callback): void
    {
        self::$mentionableResolver = $callback;
    }

    /** @return iterable<Authenticatable> */
    public static function mentionable(Model $record): iterable
    {
        return self::$mentionableResolver === null ? [] : (self::$mentionableResolver)($record);
    }

    /** Keep your own tables, for example after publishing and changing the migration. */
    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    /**
     * Who is signed in, where the default guard does not say, such as in a
     * Filament panel with a guard of its own.
     *
     * @param  Closure(): ?Authenticatable  $callback
     */
    public static function resolveUsersUsing(Closure $callback): void
    {
        self::$userResolver = $callback;
    }

    public static function user(): ?Authenticatable
    {
        return self::$userResolver === null ? auth()->user() : (self::$userResolver)();
    }

    /** @param Closure(Authenticatable): ?string $callback */
    public static function nameUsersUsing(Closure $callback): void
    {
        self::$userNameResolver = $callback;
    }

    /** @param Closure(Authenticatable): ?string $callback */
    public static function giveUsersAvatarsUsing(Closure $callback): void
    {
        self::$userAvatarResolver = $callback;
    }

    /**
     * Decide who may join a record's room. By default, editing needs the
     * policy's `update` ability and viewing its `view` ability.
     *
     * @param  Closure(Authenticatable $user, Model|class-string<Model> $record, string $ability): bool  $callback  a record, or a model class for `create`
     */
    public static function authorizeUsing(Closure $callback): void
    {
        self::$authorizer = $callback;
    }

    /**
     * Keep rooms apart that would otherwise be the same, such as the same
     * record in two tenants, or in two panels.
     *
     * @param  Closure(Model|null $record): mixed  $callback
     */
    public static function scopeRoomsUsing(Closure $callback): void
    {
        self::$roomScopeResolver = $callback;
    }

    public static function broadcasts(): bool
    {
        return (bool) value(self::$broadcasts);
    }

    public static function audits(): bool
    {
        return (bool) value(self::$audits);
    }

    public static function reportsEditorErrors(): bool
    {
        return (bool) value(self::$reportsEditorErrors);
    }

    public static function palette(): Palette
    {
        return new Palette(Data::strings(value(self::$colors)));
    }

    public static function roomScope(?Model $record = null): mixed
    {
        return self::$roomScopeResolver === null ? null : (self::$roomScopeResolver)($record);
    }

    /** Back to the defaults. For tests. */
    public static function flush(): void
    {
        self::$broadcasts = false;
        self::$pollingInterval = 1000;
        self::$avatarLimit = 5;
        self::$audits = false;
        self::$reportsEditorErrors = false;
        self::$registersRoutes = true;
        self::$styles = true;
        self::$features = [];
        self::$runsMigrations = true;
        self::$userResolver = null;
        self::$configResolver = null;
        self::$mentionableResolver = null;
        self::$colors = Palette::DEFAULT;
        self::$userNameResolver = null;
        self::$userAvatarResolver = null;
        self::$authorizer = null;
        self::$roomScopeResolver = null;
    }
}
