<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;

/** How global and parent guardrails apply to child/nested swarm runs. */
enum GuardrailChildInheritance: string
{
    /** Global config guardrails plus this swarm's {@see DefinesGuardrails::guardrails()}. */
    case OwnAndGlobal = 'own_and_global';

    /** Also merge parent swarm guardrails when {@see RunContext} carries parent_run_id and parent can be resolved. */
    case OwnGlobalAndParent = 'own_global_and_parent';
}
