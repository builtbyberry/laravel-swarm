<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

interface ConsumesNativeInputMessages
{
    /** @param list<string> $configurationIds */
    public function consumeMessages(string $id, string $runId, array $configurationIds): void;
}
