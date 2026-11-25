<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    protected $guarded = [];
}
