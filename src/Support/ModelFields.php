<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Exceptions\UnknownField;

/**
 * What a model's casts say about its fields, so a room needs to be told as
 * little as possible: a boolean, a date or an enum is a value, not text.
 *
 * @internal
 */
final class ModelFields
{
    private const VALUE_CASTS = [
        'bool', 'boolean', 'int', 'integer', 'real', 'float', 'double', 'decimal',
        'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp',
    ];

    /** How a field named without a kind merges: text, unless its cast makes it a value. */
    public static function kindOf(Model $record, string $field): FieldKind
    {
        $cast = self::castOf($record, $field);

        if (in_array(Str::before($field, '.'), $record->getDates(), true)) {
            return FieldKind::Value;
        }

        if ($cast === null) {
            return FieldKind::Text;
        }

        return in_array(Str::before($cast, ':'), self::VALUE_CASTS, true) || self::enumOf($record, $field) !== null
            ? FieldKind::Value
            : FieldKind::Text;
    }

    /** @return class-string<BackedEnum>|null the enum a field is cast to */
    public static function enumOf(Model $record, string $field): ?string
    {
        $cast = self::castOf($record, $field);

        return $cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) ? $cast : null;
    }

    /**
     * Fails loudly when a field is not the model's: a typo would otherwise
     * share an empty field that is never saved.
     *
     * @param  list<string>  $fields
     *
     * @throws UnknownField
     */
    public static function assertExist(Model $record, array $fields): void
    {
        $known = self::known($record);

        foreach ($fields as $field) {
            $attribute = Str::before($field, '.');

            if (! in_array($attribute, $known, true) && ! $record->hasGetMutator($attribute) && ! $record->hasAttributeMutator($attribute)) {
                throw UnknownField::on($record, $field, $known);
            }
        }
    }

    /** @return list<string> */
    private static function known(Model $record): array
    {
        return array_values(array_unique(array_map(strval(...), [
            ...array_keys($record->getAttributes()),
            ...$record->getFillable(),
            ...array_keys($record->getCasts()),
        ])));
    }

    private static function castOf(Model $record, string $field): ?string
    {
        $cast = $record->getCasts()[Str::before($field, '.')] ?? null;

        return is_string($cast) ? $cast : null;
    }
}
