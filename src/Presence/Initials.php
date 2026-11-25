<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use SolutionForest\Collaboration\Support\Data;

final class Initials
{
    public static function for(string $name): string
    {
        $words = Data::strings(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY));

        if ($words === []) {
            return '?';
        }

        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper(mb_substr($words[0], 0, 1).$last);
    }
}
