<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Support\Data;

/** The signed-in user, as other editors see them. */
final readonly class Identity
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $avatar,
    ) {}

    public static function current(): ?self
    {
        $user = auth()->user();

        return $user === null ? null : self::for($user);
    }

    public static function for(Authenticatable $user): self
    {
        // Others learn who is here, never the user's id.
        $key = substr(hash_hmac('sha256', $user::class.':'.Data::string($user->getAuthIdentifier()), Data::string(config('app.key'))), 0, 20);

        $name = trim(Data::string(rescue(fn (): ?string => self::nameOf($user), '', report: false)));

        return new self($key, $name !== '' ? $name : Data::string(__('collaboration::collaboration.someone')), rescue(fn (): ?string => self::avatarOf($user), null, report: false));
    }

    private static function nameOf(Authenticatable $user): ?string
    {
        if (Collaboration::$userNameResolver !== null) {
            return (Collaboration::$userNameResolver)($user);
        }

        return $user instanceof Model ? Data::nullableString($user->getAttribute('name')) : null;
    }

    private static function avatarOf(Authenticatable $user): ?string
    {
        $avatar = match (true) {
            Collaboration::$userAvatarResolver !== null => (Collaboration::$userAvatarResolver)($user),
            $user instanceof Model => Data::nullableString($user->getAttribute('avatar_url') ?? $user->getAttribute('profile_photo_url')),
            default => null,
        };

        // Only images: an avatar is put into every other editor's page.
        return $avatar !== null && preg_match('#^(https?://|/(?!/)|data:image/(png|jpeg|gif|webp);)#i', $avatar) === 1 ? $avatar : null;
    }
}
