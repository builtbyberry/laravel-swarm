<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ChecksCitationStorage;
use BuiltByBerry\LaravelSwarm\Contracts\ChecksNativeStepResultStorage;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;

/** Uses the actual bound stores; custom implementations opt in. @internal */
final class StepEvidenceStorageReadiness
{
    public function __construct(
        private RunHistoryStore $history,
        private DurableRunStore $durable,
        private StreamStepCheckpointStore $checkpoints,
    ) {}

    public function check(bool $durable = false, bool $checkpoints = false): void
    {
        $stores = [$this->history];
        if ($durable) {
            $stores[] = $this->durable;
        }
        if ($checkpoints) {
            $stores[] = $this->checkpoints;
        }
        foreach ($stores as $store) {
            if ($store instanceof ChecksCitationStorage) {
                $store->assertCitationStorageReady();
            }
            if ($store instanceof ChecksNativeStepResultStorage) {
                $store->assertNativeStepResultStorageReady();
            }
        }
    }
}
