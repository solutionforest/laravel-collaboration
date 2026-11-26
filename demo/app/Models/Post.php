<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Concerns\Collaborative;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Room;

class Post extends Model
{
    use Collaborative;

    protected $fillable = ['title', 'summary', 'body', 'status', 'featured', 'channels'];

    /**
     * The fields people edit together. A name alone takes its kind from the
     * casts: `featured` and `channels` are values, `title` and `summary` text.
     */
    protected array $collaborative = [
        'title',
        'summary',
        'body' => FieldKind::RichText,
        'status' => FieldKind::Value,
        'featured',
        'channels' => FieldKind::Value,
    ];

    public const STATUSES = ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'];

    public const CHANNELS = ['web' => 'Website', 'email' => 'Newsletter', 'sms' => 'SMS'];

    /** The status is decided by one person at a time. */
    public function collaboration(): Room
    {
        return Collaboration::room($this)->lock('status');
    }

    protected function casts(): array
    {
        return ['featured' => 'boolean', 'channels' => 'array'];
    }
}
