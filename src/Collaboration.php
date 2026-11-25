<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Presence\Palette;

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
    /** Whether changes are pushed over Laravel broadcasting as well as polled. */
    public static bool $broadcasts = false;

    /** How often, in milliseconds, an open page asks for changes when nothing is pushed. */
    public static int $pollingInterval = 1000;

    /** How many faces a presence bar shows before it says "+3". */
    public static int $avatarLimit = 5;

    /** Whether each person's edits are written to the per-minute edit trail. */
    public static bool $audits = false;

    /** Whether browsers report the editor errors they recovered from. */
    public static bool $reportsEditorErrors = false;

    /** Whether the package registers its sync route. */
    public static bool $registersRoutes = true;

    /** @var list<string> the colours people are told apart by */
    public static array $colors = Palette::DEFAULT;

    /** @var (Closure(Authenticatable): ?string)|null */
    public static ?Closure $userNameResolver = null;

    /** @var (Closure(Authenticatable): ?string)|null */
    public static ?Closure $userAvatarResolver = null;

    /** @var (Closure(Authenticatable, Model, string): bool)|null */
    public static ?Closure $authorizer = null;

    /** @var (Closure(Model|null): mixed)|null */
    public static ?Closure $roomScopeResolver = null;

    /** Start describing the room people share while they edit a record. */
    public static function room(Model $record): Room
    {
        return new Room($record);
    }

    /** Push changes as they happen, through the application's broadcaster. */
    public static function broadcast(bool $broadcasts = true): void
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

    /** @param list<string> $colors */
    public static function colors(array $colors): void
    {
        self::$colors = $colors;
    }

    public static function audit(bool $audits = true): void
    {
        self::$audits = $audits;
    }

    public static function reportEditorErrors(bool $reports = true): void
    {
        self::$reportsEditorErrors = $reports;
    }

    /** Register the sync route yourself, for example with other middleware. */
    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
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
     * @param  Closure(Authenticatable $user, Model $record, string $ability): bool  $callback
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

    public static function palette(): Palette
    {
        return new Palette(self::$colors);
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
        self::$colors = Palette::DEFAULT;
        self::$userNameResolver = null;
        self::$userAvatarResolver = null;
        self::$authorizer = null;
        self::$roomScopeResolver = null;
    }
}
