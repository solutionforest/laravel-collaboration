<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use SolutionForest\Collaboration\Comments\Comment;

/** Somebody commented on a record. `$comment->mentions` lists who they mentioned. */
final class CommentPosted
{
    use Dispatchable;

    public function __construct(
        public readonly Model $record,
        public readonly Authenticatable $by,
        public readonly Comment $comment,
        public readonly ?string $url = null,
    ) {}
}
