<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\NativeApprovalRecovery;

final class CrashParticipant
{
    public function __construct(public int $id = 417) {}
}
