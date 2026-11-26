<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Concerns\Collaborative;
use SolutionForest\Collaboration\Tests\Fixtures\Enums\PostStatus;

/** A post whose status is an enum, and whose rooms start with no fields. */
final class TypedPost extends Model
{
    use Collaborative;

    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PostStatus::class];
    }
}
