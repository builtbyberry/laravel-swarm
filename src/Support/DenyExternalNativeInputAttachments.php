<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\AuthorizesNativeInputAttachment;
use Laravel\Ai\Files\File;

/** @internal */
final class DenyExternalNativeInputAttachments implements AuthorizesNativeInputAttachment
{
    public function authorize(File $attachment, RunContext $context): bool
    {
        return false;
    }
}
