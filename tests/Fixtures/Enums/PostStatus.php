<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
