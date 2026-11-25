<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Comments;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Support\Data;

/** Somebody a comment mentions with `@Name`. */
final readonly class Mention
{
    /** @param class-string $type the user model */
    public function __construct(
        public string $type,
        public string $id,
        public string $name,
    ) {}

    public static function of(Authenticatable $user): self
    {
        return new self($user::class, Data::string($user->getAuthIdentifier()), Identity::for($user)->name);
    }

    /** @param array<string, mixed> $data */
    public static function from(array $data): ?self
    {
        $type = Data::string($data['type'] ?? null);
        $id = Data::string($data['id'] ?? null);

        return class_exists($type) && $id !== '' ? new self($type, $id, Data::string($data['name'] ?? null)) : null;
    }

    /** @return array{type: class-string, id: string, name: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'name' => $this->name];
    }

    /** The user, if they still exist. */
    public function user(): ?Authenticatable
    {
        $type = $this->type;
        $user = is_a($type, Model::class, true) ? $type::query()->find($this->id) : null;

        return $user instanceof Authenticatable ? $user : null;
    }
}
