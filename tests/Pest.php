<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrencyTestCase;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(ProcessConcurrencyTestCase::class)->in('ProcessConcurrency');
