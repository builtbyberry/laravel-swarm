<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Laravel\Ai\Files\File;

/**
 * Authorizes application-owned stored paths and provider file identifiers for
 * the actor and tenant bound to a swarm run.
 */
interface AuthorizesNativeInputAttachment
{
    public function authorize(File $attachment, RunContext $context): bool;
}
