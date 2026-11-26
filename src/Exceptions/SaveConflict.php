<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Somebody else saved fields this person changed too. Like a validation
 * error, it puts a message on each field; the form shows both versions, and
 * the next save keeps the one chosen.
 */
final class SaveConflict extends ValidationException
{
    /** @var array<string, mixed> their saved value of each field */
    public array $theirs = [];

    /** Who saved them. */
    public string $name = '';

    /**
     * @param  array<string, mixed>  $theirs
     * @param  array<string, string>  $labels
     */
    public static function on(array $theirs, array $labels, string $name): self
    {
        $messages = [];

        foreach (array_keys($theirs) as $field) {
            $messages[$field] = (string) __('collaboration::collaboration.conflict', ['name' => $name, 'field' => $labels[$field] ?? $field]);
        }

        $exception = self::withMessages($messages);
        $exception->theirs = $theirs;
        $exception->name = $name;

        return $exception;
    }
}
