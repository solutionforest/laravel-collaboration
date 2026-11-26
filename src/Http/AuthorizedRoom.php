<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SolutionForest\Collaboration\Contracts\AuthorizesRooms;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Support\Data;

/**
 * A request about a room, from somebody who may still be in it: the token
 * names the room, and access is checked again, as every sync does.
 */
final readonly class AuthorizedRoom
{
    private function __construct(
        public RoomContext $context,
        public Authenticatable $user,
        public Identity $identity,
        public ?Model $record,
    ) {}

    /** Stops the request unless its token's user may still be in the room. */
    public static function from(Request $request): self
    {
        $context = RoomContext::fromToken(Data::string($request->input('token')));
        $user = $context?->currentUser();

        abort_if($context === null || $user === null || ! $context->belongsTo($user), 403);
        abort_if($context->hasExpired(), 410);

        $access = app(AuthorizesRooms::class)->authorize($context);

        abort_unless($access->isGranted, 404);

        return new self($context, $user, Identity::for($user), $access->record);
    }

    /** The record, for actions that need one. */
    public function record(): Model
    {
        abort_if($this->record === null, 404);

        return $this->record;
    }

    public function isEditing(): bool
    {
        return $this->context->isEditing();
    }
}
