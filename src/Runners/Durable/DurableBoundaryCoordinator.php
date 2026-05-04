<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Responses\DurableChildRun;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Foundation\Bus\PendingDispatch;
use ReflectionClass;

class DurableBoundaryCoordinator
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DurableSignalHandler $signals,
        protected DurableChildSwarmCoordinator $children,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     */
    public function enterDeclaredBoundary(array $run, Swarm $swarm, RunContext $context, callable $dispatchStep): bool
    {
        foreach ($this->declaredWaits($swarm, $context) as $wait) {
            $name = $wait['name'];

            if ($context->waitOutcome($name) !== null || $this->waitIsOpen($run['run_id'], $name)) {
                continue;
            }

            $this->signals->wait($run['run_id'], $name, $wait['reason'] ?? null, $wait['timeout'] ?? null, $wait['metadata'] ?? []);

            return true;
        }

        if (! $swarm instanceof DispatchesChildSwarms) {
            return false;
        }

        $dispatched = is_array($context->metadata['durable_dispatched_child_swarms'] ?? null) ? $context->metadata['durable_dispatched_child_swarms'] : [];

        foreach ($swarm->durableChildSwarms($context) as $index => $definition) {
            if (isset($dispatched[$index])) {
                continue;
            }

            $this->dispatchChildSwarm($run['run_id'], $definition['swarm'], $definition['task'], (string) $index, $dispatchStep);

            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{name: string, timeout?: int|null, reason?: string|null, metadata?: array<string, mixed>}>
     */
    protected function declaredWaits(Swarm $swarm, RunContext $context): array
    {
        if ($swarm instanceof RoutesDurableWaits) {
            return $swarm->durableWaits($context);
        }

        return array_map(
            static fn (\ReflectionAttribute $attribute): array => [
                'name' => $attribute->newInstance()->name,
                'timeout' => $attribute->newInstance()->timeout,
                'reason' => $attribute->newInstance()->reason,
                'metadata' => [],
            ],
            (new ReflectionClass($swarm))->getAttributes(DurableWait::class),
        );
    }

    protected function waitIsOpen(string $runId, string $name): bool
    {
        foreach ($this->durableRuns->waits($runId) as $wait) {
            if (($wait['name'] ?? null) === $name && ($wait['status'] ?? null) === 'waiting') {
                return true;
            }
        }

        return false;
    }

    protected function dispatchChildSwarm(string $parentRunId, string $childSwarmClass, string|array|RunContext $task, ?string $dedupeKey, callable $dispatchStep): DurableChildRun
    {
        return $this->children->dispatchChildSwarm(
            $parentRunId,
            $childSwarmClass,
            $task,
            $dedupeKey,
            fn (string $runId, int $stepIndex, ?string $connection = null, ?string $queue = null): PendingDispatch => $dispatchStep($runId, $stepIndex, $connection, $queue),
        );
    }
}
