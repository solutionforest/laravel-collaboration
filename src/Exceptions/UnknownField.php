<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Exceptions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SolutionForest\Collaboration\Support\Suggest;

/** A room names a field its model does not have: most often a typo. */
final class UnknownField extends InvalidArgumentException
{
    /** @param list<string> $known */
    public static function on(Model $record, string $field, array $known): self
    {
        $model = class_basename($record);

        return new self("[{$model}] has no attribute [{$field}] to collaborate on.".Suggest::didYouMean($field, $known));
    }
}
