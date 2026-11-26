<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Rooms;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Contracts\AuthorizesRooms;

/**
 * Repeats the page's authorisation on every sync, so losing access takes
 * effect within a second instead of when the page is reloaded.
 */
final readonly class RoomAuthorizer implements AuthorizesRooms
{
    public function __construct(private RoomResolver $rooms) {}

    public function authorize(RoomContext $context): RoomAccess
    {
        $user = $context->currentUser();

        if ($user === null || ! $context->belongsTo($user) || $context->model === null || $context->key === null) {
            return RoomAccess::denied();
        }

        $record = $context->model::query()->whereKey($context->key)->first();

        if (! $record instanceof Model || ! hash_equals($this->rooms->forRecord($record), $context->room)) {
            return RoomAccess::denied();
        }

        return self::allows($user, $record, $context->isEditing() ? 'update' : 'view')
            ? RoomAccess::granted($record)
            : RoomAccess::denied();
    }

    public static function allows(Authenticatable $user, Model $record, string $ability): bool
    {
        if (Collaboration::$authorizer !== null) {
            return (Collaboration::$authorizer)($user, $record, $ability);
        }

        return Gate::forUser($user)->allows($ability, $record);
    }
}
