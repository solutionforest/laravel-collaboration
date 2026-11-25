<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Permissions;
use SolutionForest\Collaboration\Support\Data;

/**
 * Everything a sync request is allowed to do. Issued by the server while it
 * renders an authorised page, encrypted, and rechecked on every request.
 */
final readonly class RoomContext
{
    /**
     * @param  array<string, FieldKind>  $fields  the fields this tab may read and write
     * @param  list<string>  $visible  fields whose names this user may learn
     * @param  array<string, list<string>>  $allowed  the values each picked field may take
     * @param  class-string<Model>|null  $model
     * @param  array<string, string|null>  $meta  what an integration needs to know, such as a Filament panel
     */
    public function __construct(
        public string $room,
        public ?string $scope,
        public PresenceMode $mode,
        public int $client,
        public string $guard,
        public string $user,
        public array $fields = [],
        public array $visible = [],
        public ?string $model = null,
        public ?string $key = null,
        public int $expiresAt = 0,
        public array $allowed = [],
        public string $permissions = '',
        public ?string $locale = null,
        public array $meta = [],
    ) {}

    /**
     * Where the shared draft is kept. A translated page has one per language,
     * in the same room: everybody sees everybody, and edits stay in their language.
     */
    public function draft(): string
    {
        return self::draftFor($this->room, $this->locale);
    }

    public static function draftFor(string $room, ?string $locale): string
    {
        return $locale === null ? $room : hash('sha256', $room."\0".$locale);
    }

    public function meta(string $key): ?string
    {
        return $this->meta[$key] ?? null;
    }

    public function isEditing(): bool
    {
        return $this->mode === PresenceMode::Editing;
    }

    public function toToken(): string
    {
        return Crypt::encryptString(Data::encode([
            'r' => $this->room,
            's' => $this->scope,
            'm' => $this->mode->value,
            'c' => $this->client,
            'g' => $this->guard,
            'u' => $this->user,
            'f' => array_map(static fn (FieldKind $kind): string => $kind->value, $this->fields),
            'v' => $this->visible,
            'mo' => $this->model,
            'k' => $this->key,
            'e' => $this->expiresAt,
            'o' => $this->allowed,
            'l' => $this->locale,
            'a' => $this->permissions,
            'x' => $this->meta,
        ]));
    }

    public static function fromToken(string $token): ?self
    {
        try {
            $data = Data::decode(Crypt::decryptString($token));
        } catch (DecryptException) {
            return null;
        }

        $mode = PresenceMode::tryFrom(Data::string($data['m'] ?? null));

        if ($mode === null) {
            return null;
        }

        $fields = [];

        foreach (Data::map($data['f'] ?? null) as $path => $value) {
            if (($kind = FieldKind::tryFrom(Data::string($value))) !== null) {
                $fields[(string) $path] = $kind;
            }
        }

        $model = Data::nullableString($data['mo'] ?? null);

        return new self(
            room: Data::string($data['r'] ?? null),
            scope: Data::nullableString($data['s'] ?? null),
            mode: $mode,
            client: Data::int($data['c'] ?? null),
            guard: Data::string($data['g'] ?? null),
            user: Data::string($data['u'] ?? null),
            fields: $fields,
            visible: Data::strings($data['v'] ?? null),
            model: $model !== null && is_subclass_of($model, Model::class) ? $model : null,
            key: Data::nullableString($data['k'] ?? null),
            expiresAt: Data::int($data['e'] ?? null),
            allowed: array_map(Data::strings(...), Data::map($data['o'] ?? null)),
            permissions: Data::string($data['a'] ?? null),
            locale: Data::nullableString($data['l'] ?? null),
            meta: array_map(Data::nullableString(...), Data::map($data['x'] ?? null)),
        );
    }

    /** The signed-in user of the guard the token was issued for. */
    public function currentUser(): ?Authenticatable
    {
        return $this->guard === '' ? null : auth()->guard($this->guard)->user();
    }

    public function belongsTo(?Authenticatable $user): bool
    {
        return $user !== null && Data::string($user->getAuthIdentifier()) === $this->user;
    }

    /** Whether `$earlier` is the same person's previous tab on the same record. */
    public function canResume(self $earlier): bool
    {
        return $earlier->room === $this->room
            && $earlier->locale === $this->locale
            && $earlier->user === $this->user
            && $earlier->guard === $this->guard
            && $earlier->meta === $this->meta
            && $earlier->isEditing()
            && $this->isEditing();
    }

    /** Too old, or issued before `Permissions::changed()`: the page must render a new one. */
    public function hasExpired(): bool
    {
        return $this->expiresAt < now()->getTimestamp() || ! hash_equals(Permissions::version($this->room), $this->permissions);
    }
}
