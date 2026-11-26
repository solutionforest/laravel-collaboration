<?php

declare(strict_types=1);

use SolutionForest\Collaboration\Tests\FeaturesOff\TestCase as FeaturesOffTestCase;
use SolutionForest\Collaboration\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(FeaturesOffTestCase::class)->in('FeaturesOff');
