<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\DurableStreamingDigest\StreamingDigestSwarm;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the durable-streaming-digest starter example.
 *
 * Usage:
 *   php artisan swarm:example:streaming run "Weekly engineering digest"
 *
 * What it does, in one process:
 *   1. Dispatches the swarm **durably** — `dispatchDurable()`, never `->run()`
 *      or `->stream()`. Only a durable dispatch persists per-node token deltas
 *      to the causal log; a live run carrying `#[DurableStreaming]` would stream
 *      to the caller but write zero durable rows, leaving nothing to replay.
 *   2. Drains the run to completion in-process by handling each
 *      `AdvanceDurableSwarm` job directly.
 *   3. Reads the persisted deltas back per node via `CausalLogView` and prints
 *      the reconstructed text for each streamed node.
 *
 * The in-process drain (step 2) is a DEMO-ONLY shortcut so the example runs in a
 * single `php artisan` invocation with no queue worker. In a real app the run is
 * advanced by a queue worker on the durable connection — never hand-drive the
 * advance job in production code.
 */
#[AsCommand(name: 'swarm:example:streaming')]
class SwarmExampleStreamingCommand extends Command
{
    protected $signature = 'swarm:example:streaming
        {action=run : run}
        {topic? : The digest topic to stream}';

    protected $description = 'Run the durable-streaming-digest starter example: dispatch durably, drain, and replay the streamed deltas.';

    public function handle(DurableSwarmManager $manager, CausalLogStore $log): int
    {
        if ((string) $this->argument('action') !== 'run') {
            $this->components->error('Unknown action. Use: run.');

            return self::FAILURE;
        }

        $topic = (string) ($this->argument('topic') ?? 'Weekly engineering digest');

        // (1) Dispatch DURABLY — this is what persists the per-node deltas.
        $response = StreamingDigestSwarm::make()->dispatchDurable($topic);
        $runId = $response->runId;

        $this->components->info('Durable streaming run dispatched.');
        $this->components->twoColumnDetail('Run ID', $runId);

        // (2) Drain to completion in-process (DEMO ONLY — a queue worker does this
        //     in a real app). Advance one step per iteration until the run settles.
        $guard = 0;
        while (! in_array((string) ($manager->find($runId)['status'] ?? 'completed'), ['completed', 'failed', 'cancelled'], true)) {
            if ($guard++ > 100) {
                $this->components->error('Run did not converge; aborting the demo drain.');

                return self::FAILURE;
            }

            $stepIndex = (int) ($manager->find($runId)['next_step_index'] ?? 0);
            (new AdvanceDurableSwarm($runId, $stepIndex))->handle($manager);
        }

        $status = (string) ($manager->find($runId)['status'] ?? 'unknown');
        $this->components->twoColumnDetail('Status', $status);

        // (3) Replay the persisted stream from the causal log, per node.
        $this->newLine();
        $this->components->info('Streamed text, reconstructed from the durable causal log:');

        foreach ($this->reconstructPerNode($log, $runId) as $nodeId => $text) {
            $this->components->twoColumnDetail($nodeId, $text);
        }

        return $status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Fold the run's persisted causal log and reconstruct each node's streamed
     * text by concatenating its token deltas in causal order.
     *
     * @return array<string, string>
     */
    private function reconstructPerNode(CausalLogStore $log, string $runId): array
    {
        $byNode = [];

        foreach (CausalLogView::forRun($log, $runId)->fold() as $event) {
            $nodeId = $this->nodeId($event);

            if ($nodeId !== null) {
                $byNode[$nodeId][] = $event;
            }
        }

        return array_map(
            static fn (array $events): string => SwarmTextDelta::combine($events),
            $byNode,
        );
    }

    private function nodeId(SwarmStreamEvent $event): ?string
    {
        $payload = $event->toArray();

        return is_string($payload['node_id'] ?? null) ? $payload['node_id'] : null;
    }
}
