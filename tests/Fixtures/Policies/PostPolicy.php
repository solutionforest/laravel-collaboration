<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;

final class PostPolicy
{
    public function view(User $user, Model $post): bool
    {
        return true;
    }

    public function update(User $user, Model $post): bool
    {
        return $user->name !== 'Viewer';
    }

    public function create(User $user): bool
    {
        return $user->name !== 'Viewer';
    }
}
