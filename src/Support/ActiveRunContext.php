<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Audit\DefaultActorResolver;
use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Conversational;
use Throwable;

/**
 * Ambient handle to the swarm run currently executing an agent.
 *
 * Runners call {@see enter()} immediately before invoking an agent and
 * {@see exit()} in a finally afterward, so code with no handle to the runner —
 * notably an agent's {@see Conversational::messages()} via
 * the {@see RemembersRunContext} trait — can
 * discover the active run id, swarm class, and {@see RunContext}.
 *
 * Backed by {@see Context} (the same mechanism {@see DefaultActorResolver}
 * uses for `swarm:actor`): the stored payload is plain, serializable data, so it
 * survives queue and concurrency-process boundaries when the runner forwards it
 * into a worker closure. No live Swarm/SwarmMemory objects are stored.
 *
 * @internal
 */
final class ActiveRunContext
{
    public const KEY = 'swarm:active_run';

    public static function enter(string $runId, string $swarmClass, RunContext $context): void
    {
        try {
            Context::add(self::KEY, [
                'run_id' => $runId,
                'swarm_class' => $swarmClass,
                'context' => $context->toQueuePayload(),
            ]);
        } catch (Throwable) {
            // Best-effort: an unbooted container (e.g. POPO-style test setup)
            // has no Context store. The trait falls back to a no-op there.
        }
    }

    public static function exit(): void
    {
        try {
            Context::forget(self::KEY);
        } catch (Throwable) {
            // Best-effort — see enter().
        }
    }

    public static function current(): ?ActiveRunRecord
    {
        try {
            $value = Context::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $runId = $value['run_id'] ?? null;
        $swarmClass = $value['swarm_class'] ?? null;
        $payload = $value['context'] ?? null;

        if (! is_string($runId) || ! is_string($swarmClass) || ! is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return new ActiveRunRecord($runId, $swarmClass, $payload);
    }
}
