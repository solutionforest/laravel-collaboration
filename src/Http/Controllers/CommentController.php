<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Comments\Comment;
use SolutionForest\Collaboration\Comments\Mention;
use SolutionForest\Collaboration\Comments\RecordComments;
use SolutionForest\Collaboration\Events\CommentPosted;
use SolutionForest\Collaboration\Http\AuthorizedRoom;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Support\Data;

/**
 * Comments on a record, or on one of its fields, with @mentions. Everybody
 * who may be in the room reads them; editors write and resolve them.
 */
final readonly class CommentController
{
    public function __construct(private RecordComments $comments) {}

    public function index(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);

        return $this->respond($room);
    }

    public function store(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);
        $body = trim(Data::string($request->input('body')));
        $field = Data::nullableString($request->input('field'));

        abort_unless($room->isEditing() && $body !== '' && mb_strlen($body) <= 5000, 422);
        abort_if($field !== null && ! in_array($field, $room->context->visible, true), 422);

        $record = $room->record();
        $comment = $this->comments->post($room->context->room, $field, $body, $room->user, RecordComments::mentionsIn($body, Collaboration::mentionable($record)));

        event(new CommentPosted($record, $room->user, $comment, Data::nullableString($request->headers->get('referer'))));

        return $this->respond($room);
    }

    public function resolve(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);

        abort_unless($room->isEditing(), 403);

        $this->comments->resolve($room->context->room, Data::int($request->input('id')), $room->identity->name);

        return $this->respond($room);
    }

    public function reopen(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);

        abort_unless($room->isEditing(), 403);

        $this->comments->reopen($room->context->room, Data::int($request->input('id')));

        return $this->respond($room);
    }

    private function respond(AuthorizedRoom $room): JsonResponse
    {
        $me = $room->identity;

        return new JsonResponse([
            'comments' => array_map(static fn (Comment $comment): array => [
                'id' => $comment->id,
                'field' => $comment->field,
                'body' => $comment->body,
                'html' => $comment->html(),
                'author' => $comment->authorName,
                'mine' => $comment->authorType === $room->user::class && $comment->authorId === Data::string($room->user->getAuthIdentifier()),
                'mentions' => array_map(static fn (Mention $mention): string => $mention->name, $comment->mentions),
                'at' => $comment->at->toIso8601String(),
                'resolved' => $comment->isResolved(),
                'resolvedBy' => $comment->resolvedBy,
            ], $this->comments->list($room->context->room)),
            'mentionable' => $room->record === null ? [] : array_values(array_unique(array_map(
                static fn (Authenticatable $person): string => Identity::for($person)->name,
                [...Collaboration::mentionable($room->record)],
            ))),
            'canWrite' => $room->isEditing(),
            'me' => $me->name,
        ]);
    }
}
