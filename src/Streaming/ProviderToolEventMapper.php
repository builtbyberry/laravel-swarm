<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;

/** @internal */
final class ProviderToolEventMapper
{
    public function __construct(private SwarmCapture $capture, private ProviderToolDataLimits $limits) {}

    public function map(ProviderToolEvent $event, RunContext $context, int $stepIndex, string $agentClass, int &$stepBytes): SwarmProviderToolEvent
    {
        $mapped = new SwarmProviderToolEvent($event->id, $context->runId, $stepIndex, $agentClass,
            $event->itemId, $event->type, $event->status, $event->provider, $event->timestamp,
            $this->capture->providerToolData($event->data, $context, $this->limits, $stepBytes));
        $mapped->invocationId = $event->invocationId;

        return $mapped;
    }
}
