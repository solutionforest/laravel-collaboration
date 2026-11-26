<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Exceptions;

use InvalidArgumentException;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Suggest;

/** A field was given a kind that does not exist, such as `richtext`. */
final class UnknownFieldKind extends InvalidArgumentException
{
    public static function named(string $kind, string $field): self
    {
        $kinds = array_map(static fn (FieldKind $case): string => $case->value, FieldKind::cases());

        return new self("[{$kind}] is not a kind of field, for [{$field}]. Use ".implode(', ', $kinds).', or a FieldKind case.'.Suggest::didYouMean($kind, $kinds));
    }
}
