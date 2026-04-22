<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps as MaxAgentStepsAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout as TimeoutAttribute;
use BuiltByBerry\LaravelSwarm\Attributes\Topology as TopologyAttribute;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReflectionClass;

class SwarmRunner
{
    public function __construct(
        protected ConfigRepository $config,
        protected CacheFactory $cacheFactory,
        protected SequentialRunner $sequential,
        protected ParallelRunner $parallel,
        protected HierarchicalRunner $hierarchical,
    ) {}

    /**
     * Run the swarm synchronously.
     */
    public function run(Swarm $swarm, string $task): SwarmResponse
    {
        $topology = $this->resolveTopology($swarm);
        $timeoutSeconds = $this->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolveMaxAgentExecutions($swarm);
        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);
        $contextKey = $this->contextKey($swarm, $task);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);

        $payload = match ($topology) {
            Topology::Sequential => $this->sequential->run(
                $swarm,
                $task,
                $deadline,
                $maxAgentExecutions,
                $contextKey,
                $this->contextRepository(),
                $contextTtl,
            ),
            Topology::Parallel => $this->parallel->run(
                $swarm,
                $task,
                $deadline,
                $maxAgentExecutions,
                $contextKey,
                $this->contextRepository(),
                $contextTtl,
            ),
            Topology::Hierarchical => $this->hierarchical->run(
                $swarm,
                $task,
                $deadline,
                $maxAgentExecutions,
                $contextKey,
                $this->contextRepository(),
                $contextTtl,
            ),
        };

        return $payload['response'];
    }

    /**
     * Queue the swarm to run in the background.
     */
    public function queue(Swarm $swarm, string $task): QueuedSwarmResponse
    {
        $pending = InvokeSwarm::dispatch($swarm, $task);

        if ($connection = $this->config->get('swarm.queue.connection')) {
            $pending->onConnection($connection);
        }

        if ($name = $this->config->get('swarm.queue.name')) {
            $pending->onQueue($name);
        }

        return new QueuedSwarmResponse($pending);
    }

    /**
     * Resolve topology from the swarm attribute or configuration.
     */
    public function resolveTopology(Swarm $swarm): Topology
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TopologyAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->topology;
        }

        return Topology::from((string) $this->config->get('swarm.topology', Topology::Sequential->value));
    }

    /**
     * Resolve the wall-clock timeout in seconds for the swarm run.
     */
    public function resolveTimeoutSeconds(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->seconds;
        }

        return (int) $this->config->get('swarm.timeout', 300);
    }

    /**
     * Resolve the maximum number of agent executions for a single swarm run.
     */
    public function resolveMaxAgentExecutions(Swarm $swarm): int
    {
        $reflection = new ReflectionClass($swarm);
        $attributes = $reflection->getAttributes(MaxAgentStepsAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->steps;
        }

        return (int) $this->config->get('swarm.max_agent_steps', 10);
    }

    /**
     * Build a cache key for optional shared swarm context.
     */
    protected function contextKey(Swarm $swarm, string $task): string
    {
        return 'swarm:context:'.sha1($swarm::class.'|'.$task.'|'.spl_object_id($swarm));
    }

    /**
     * Resolve the cache repository used for swarm context persistence.
     */
    protected function contextRepository(): CacheRepository
    {
        $driver = (string) $this->config->get('swarm.context.driver', 'cache');
        $store = $this->config->get('swarm.context.store');

        if ($driver === 'database') {
            return $this->cacheFactory->store('database');
        }

        return $store !== null && $store !== ''
            ? $this->cacheFactory->store((string) $store)
            : $this->cacheFactory->store();
    }
}
