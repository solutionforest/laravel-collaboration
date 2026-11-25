<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Concerns\Collaborative;

class Post extends Model
{
    use Collaborative;

    protected $fillable = ['title', 'summary', 'body', 'status', 'featured'];

    /** The fields people edit together. A name alone merges as text. */
    protected array $collaborative = [
        'title',
        'summary',
        'body' => 'rich',
        'status' => 'value',
        'featured' => 'value',
    ];

    public const STATUSES = ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'];

    protected function casts(): array
    {
        return ['featured' => 'boolean'];
    }
}
