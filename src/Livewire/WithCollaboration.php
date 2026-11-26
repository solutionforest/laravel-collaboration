<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Livewire;

use Closure;
use Livewire\Attributes\Locked;
use SolutionForest\Collaboration\Exceptions\SaveConflict;
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

    /**
     * What the record's other fields held when the page loaded or was last
     * saved, to tell this person's changes from somebody else's save.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $collaborationBase = [];

    /**
     * Fields somebody else saved while this person changed them too: their
     * value and name, until this person chooses. `<x-collaboration::conflicts>` shows them.
     *
     * @var array<string, array{theirs: mixed, name: string}>
     */
    #[Locked]
    public array $collaborationConflicts = [];

    abstract public function room(): Room;

    /** The room as this tab renders it. */
    public function collaborationRoom(): Room
    {
        $this->collaborationClient ??= random_int(1, 2 ** 31 - 1);
        $room = $this->room()->client($this->collaborationClient);

        if ($this->collaborationBase === []) {
            $this->collaborationBase = $this->collaborationValues($room);
        }

        return $room;
    }

    /**
     * Saves the component's values for the room's fields and the record's
     * other fields, with everybody's latest edits in the live ones. Updates
     * the record, unless you pass what to do with the values instead.
     *
     * A field somebody else saved while this person changed it too stops
     * the save, with a message on the field; `keepMyVersion()` or
     * `useTheirVersion()` settles it.
     *
     * @param  (Closure(array<string, mixed>): mixed)|null  $persist
     * @param  string|null  $statePath  where the fields are, such as `form` for a Livewire form object
     */
    protected function saveCollaboratively(?Closure $persist = null, ?string $statePath = null): mixed
    {
        $room = $this->collaborationRoom();
        $values = [];

        foreach ([...array_keys($room->sharedFields()), ...array_keys($this->collaborationBase)] as $field) {
            $values[$field] = data_get($this, $statePath === null ? $field : "{$statePath}.{$field}");
        }

        try {
            $result = $persist === null
                ? $room->update($values, $this->collaborationSync, $this->collaborationBase)
                : $room->save($values, $persist, $this->collaborationSync, $this->collaborationBase);
        } catch (SaveConflict $conflict) {
            foreach ($conflict->theirs as $field => $theirs) {
                $this->collaborationConflicts[$field] = ['theirs' => $theirs, 'name' => $conflict->name];
            }

            throw $conflict;
        }

        // Saved: what the fields hold now is the new base.
        $this->collaborationConflicts = [];
        $this->collaborationBase = array_merge($this->collaborationBase, array_intersect_key($values, $this->collaborationBase));

        return $result;
    }

    /** Keep this person's value of a conflicting field: saving again overwrites theirs. */
    public function keepMyVersion(string $field): void
    {
        $conflict = $this->collaborationConflicts[$field] ?? null;

        if ($conflict !== null) {
            $this->collaborationBase[$field] = $conflict['theirs'];
            unset($this->collaborationConflicts[$field]);
            $this->resetErrorBag($field);
        }
    }

    /** Take the other person's value of a conflicting field instead. */
    public function useTheirVersion(string $field): void
    {
        $conflict = $this->collaborationConflicts[$field] ?? null;

        if ($conflict !== null) {
            $this->{$field} = $conflict['theirs'];
            $this->collaborationBase[$field] = $conflict['theirs'];
            unset($this->collaborationConflicts[$field]);
            $this->resetErrorBag($field);
        }
    }

    /**
     * Somebody else saved: their values go into the fields this person left
     * alone. Live fields are already up to date. The script calls this.
     */
    public function pullCollaboration(): void
    {
        $room = $this->collaborationRoom();
        $theirs = $room->record()->fresh()?->toArray() ?? [];

        foreach ($this->collaborationBase as $property => $base) {
            if (! array_key_exists($property, $theirs) || $theirs[$property] == $base) {
                continue;
            }

            // Changed here too: the next save decides, as `saveCollaboratively()` merges.
            if ($this->{$property} == $base) {
                $this->{$property} = $theirs[$property];
                $this->collaborationBase[$property] = $theirs[$property];
            }
        }
    }

    /**
     * The component's public properties that are the record's attributes,
     * and not live: those merge in the room.
     *
     * @return array<string, mixed>
     */
    private function collaborationValues(Room $room): array
    {
        $attributes = $room->record()->exists ? $room->record()->toArray() : [];
        $values = [];

        foreach (get_object_vars($this) as $property => $value) {
            if (array_key_exists($property, $attributes) && ! isset($room->sharedFields()[$property]) && ($value === null || is_scalar($value))) {
                $values[$property] = $value;
            }
        }

        return $values;
    }
}
