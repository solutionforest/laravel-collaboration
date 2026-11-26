<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

/**
 * The parts of the package an app turns on when it wants them. Everything
 * else, editing together, is always there:
 *
 *     Collaboration::features([Features::comments(), Features::history()]);
 *
 * A feature that is off registers no routes, creates no table, and ships no
 * script to the browser.
 */
final class Features
{
    public const COMMENTS = 'comments';

    public const HISTORY = 'history';

    public const UPLOADS = 'uploads';

    /** Comments on a record or one of its fields, with @mentions. */
    public static function comments(): string
    {
        return self::COMMENTS;
    }

    /** The draft's versions, to look back at and restore. */
    public static function history(): string
    {
        return self::HISTORY;
    }

    /** Images uploaded into rich text while editing. */
    public static function uploads(): string
    {
        return self::UPLOADS;
    }
}
