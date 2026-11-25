<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Policies;

use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;

final class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return $user->name !== 'Viewer';
    }
}
