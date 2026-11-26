<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Concerns;

use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Room;
use SolutionForest\Collaboration\Support\Data;

/**
 * Lets a model say once which of its fields people edit together:
 *
 *     protected array $collaborative = ['title', 'body' => 'rich', 'status' => 'value'];
 *
 * A name alone takes its kind from the model's casts: text, or a value for a
 * boolean, number, date or enum. Kinds may be FieldKind cases. Every room on
 * the model starts with these fields.
 */
trait Collaborative
{
    /**
     * The `$collaborative` property, or nothing. Override it to decide in code.
     *
     * @return array<int|string, string|FieldKind>
     */
    public function collaborativeFields(): array
    {
        $fields = [];

        foreach (property_exists($this, 'collaborative') && is_array($this->collaborative) ? $this->collaborative : [] as $name => $kind) {
            $fields[$name] = $kind instanceof FieldKind ? $kind : Data::string($kind);
        }

        return $fields;
    }

    public function collaboration(): Room
    {
        return Collaboration::room($this);
    }
}
