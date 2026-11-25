<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Support\Data;

/** Somebody who changed a field in the shared draft. */
final readonly class Contributor
{
    /**
     * @param  class-string  $type  the user model
     * @param  int|string  $id  the user's `getAuthIdentifier()`
     * @param  int  $at  when they last changed the field
     */
    public function __construct(
        public string $type,
        public int|string $id,
        public string $name,
        public int $at,
    ) {}

    public static function of(Authenticatable $user): self
    {
        $id = $user->getAuthIdentifier();

        return new self($user::class, is_int($id) ? $id : Data::string($id), Identity::for($user)->name, now()->getTimestamp());
    }

    /** @param array<string, mixed> $data */
    public static function from(array $data): ?self
    {
        $type = Data::string($data['type'] ?? null);
        $id = $data['id'] ?? null;

        if (! class_exists($type) || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        return new self($type, $id, Data::string($data['name'] ?? null), Data::int($data['at'] ?? null));
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    /** The user, if they still exist. */
    public function user(): ?Authenticatable
    {
        $type = $this->type;
        $user = is_a($type, Model::class, true) ? $type::query()->find($this->id) : null;

        return $user instanceof Authenticatable ? $user : null;
    }

    /** @return array{type: class-string, id: int|string, name: string, at: int} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'name' => $this->name, 'at' => $this->at];
    }
}
