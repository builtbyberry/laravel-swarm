<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

/**
 * Declarative policy for what to capture into audit evidence and run history.
 *
 * Bind a custom implementation in the service container to make capture
 * decisions per-run rather than via the static swarm.capture.* booleans.
 * Implementations receive the RunContext and resolved Actor and return one of
 * CaptureDecision::Full | Redact | Skip per category.
 *
 * Policies never see the captured payload itself — by design, so policy
 * decisions cannot couple to payload shapes and cannot leak the unredacted
 * payload through their own code.
 *
 * The default binding (BooleanCapturePolicy) reads the existing
 * swarm.capture.* booleans and returns Full when true / Redact when false,
 * preserving the legacy boolean behavior exactly. A boolean policy never
 * returns Skip, so only a custom policy can omit a field.
 */
interface CapturePolicy
{
    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;
}
