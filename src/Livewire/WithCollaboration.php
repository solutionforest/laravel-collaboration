<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Livewire;

use Closure;
use Livewire\Attributes\Locked;
use SolutionForest\Collaboration\Room;

/**
 * Makes a Livewire component's form collaborative. Describe the room, put
 * `<x-collaboration::room :room="$this->room()" />` in the view, and save
 * through `saveCollaboratively()`:
 *
 *     public function room(): Room
 *     {
 *         return $this->post->collaboration();
 *     }
 *
 *     public function save(): void
 *     {
 *         $this->saveCollaboratively();
 *     }
 */
trait WithCollaboration
{
    /** What the browser typed since its last sync, set before every request. */
    public string $collaborationSync = '';

    /** This tab, kept across renders so a renewed token still names it. */
    #[Locked]
    public ?int $collaborationClient = null;

    abstract public function room(): Room;

    /** The room as this tab renders it. */
    public function collaborationRoom(): Room
    {
        $this->collaborationClient ??= random_int(1, 2 ** 31 - 1);

        return $this->room()->client($this->collaborationClient);
    }

    /**
     * Saves the component's values for the room's fields, with everybody's
     * latest edits in the live ones. Updates the record, unless you pass
     * what to do with the values instead.
     *
     * @param  (Closure(array<string, mixed>): mixed)|null  $persist
     * @param  string|null  $statePath  where the fields are, such as `form` for a Livewire form object
     */
    protected function saveCollaboratively(?Closure $persist = null, ?string $statePath = null): mixed
    {
        $room = $this->collaborationRoom();
        $values = [];

        foreach (array_keys($room->sharedFields()) as $field) {
            $values[$field] = data_get($this, $statePath === null ? $field : "{$statePath}.{$field}");
        }

        return $persist === null
            ? $room->update($values, $this->collaborationSync)
            : $room->save($values, $persist, $this->collaborationSync);
    }
}
