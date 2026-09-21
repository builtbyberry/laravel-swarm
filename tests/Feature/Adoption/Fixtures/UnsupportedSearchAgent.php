<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;

#[Provider('gemini')]
#[Model('gemini-2.5-flash')]
class UnsupportedSearchAgent extends WorkflowAgent {}
