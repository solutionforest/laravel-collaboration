<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Concerns\Collaborative;

final class Post extends Model
{
    use Collaborative;

    protected $guarded = [];

    /** @var array<int|string, string> */
    protected array $collaborative = ['title', 'summary', 'body' => 'rich', 'status' => 'value'];

    protected function casts(): array
    {
        return ['content' => 'array', 'featured' => 'boolean'];
    }
}
