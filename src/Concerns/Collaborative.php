<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Concerns;

use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Room;

/**
 * Lets a model say once which of its fields people edit together:
 *
 *     protected array $collaborative = ['title', 'body' => 'rich', 'status' => 'value'];
 *
 * A name alone merges as text. Every room on the model starts with these fields.
 */
trait Collaborative
{
    /** @return array<int|string, string|FieldKind> */
    public function collaborativeFields(): array
    {
        return property_exists($this, 'collaborative') ? $this->collaborative : [];
    }

    public function collaboration(): Room
    {
        return Collaboration::room($this);
    }
}
