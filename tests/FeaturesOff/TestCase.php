<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\FeaturesOff;

use SolutionForest\Collaboration\Tests\TestCase as BaseTestCase;

/** The package as an app sees it that turns no optional part on. */
abstract class TestCase extends BaseTestCase
{
    protected function features(): array
    {
        return [];
    }
}
