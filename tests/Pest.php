<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrencyTestCase;
use BuiltByBerry\LaravelSwarm\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(ProcessConcurrencyTestCase::class)->in('ProcessConcurrency');

// Installer tests bind their own base case via `uses(InstallerTestCase::class)`
// inside each file so each test gets an isolated host-app skeleton — see
// tests/Installer/README.md.
