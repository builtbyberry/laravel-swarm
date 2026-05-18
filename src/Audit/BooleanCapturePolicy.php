<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Default CapturePolicy: reads the swarm.capture.* booleans and returns
 * CaptureDecision::Full when true / CaptureDecision::Redact when false.
 *
 * Preserves the v0.3 capture behavior exactly. The boolean config keys are
 * deprecated in v0.4 and scheduled for removal in v0.5; bind a custom
 * CapturePolicy to make decisions per-run with context and actor visibility.
 */
class BooleanCapturePolicy implements CapturePolicy
{
    public function __construct(
        protected ConfigRepository $config,
    ) {}

    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->fromBoolean('swarm.capture.inputs');
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->fromBoolean('swarm.capture.outputs');
    }

    /**
     * Artifacts capture remains gated on outputs capture, matching the v0.3
     * SwarmCapture::capturesArtifacts() contract.
     */
    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        if ($this->outputs($context, $actor) !== CaptureDecision::Full) {
            return CaptureDecision::Redact;
        }

        return $this->fromBoolean('swarm.capture.artifacts');
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $this->fromBoolean('swarm.capture.active_context');
    }

    protected function fromBoolean(string $key): CaptureDecision
    {
        return (bool) $this->config->get($key, false)
            ? CaptureDecision::Full
            : CaptureDecision::Redact;
    }
}
