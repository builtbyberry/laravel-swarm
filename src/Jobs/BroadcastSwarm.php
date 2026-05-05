<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\EmitsSwarmJobTelemetry;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @phpstan-import-type SwarmBroadcastChannels from \BuiltByBerry\LaravelSwarm\Support\PhpStanTypeAliases
 */
class BroadcastSwarm implements ShouldQueue
{
    use EmitsSwarmJobTelemetry;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $task
     * @param  SwarmBroadcastChannels  $channels
     */
    public function __construct(
        public string $swarmClass,
        public array $task,
        public Channel|array $channels,
        ?int $enqueuedAtMs = null,
    ) {
        $this->enqueuedAtMs = $enqueuedAtMs ?? self::telemetryEpochMilliseconds();
    }

    /**
     * Execute the job.
     */
    public function handle(SwarmRunner $runner): void
    {
        $this->withSwarmJobTelemetry(function () use ($runner): void {
            $swarm = Container::getInstance()->make($this->swarmClass);
            $context = RunContext::fromPayload($this->task);

            if (! $swarm instanceof Swarm) {
                throw new SwarmException("Unable to resolve broadcast swarm [{$this->swarmClass}] from the container.");
            }

            $telemetry = Container::getInstance()->make(SwarmTelemetryDispatcher::class);
            $streamStart = MonotonicTime::now();
            $sequenceIndex = 0;
            $channelNames = self::normalizeBroadcastChannelNames($this->channels);

            $runner->stream($swarm, $context)
                ->each(function (SwarmStreamEvent $event) use ($telemetry, $context, $swarm, &$sequenceIndex, $streamStart, $channelNames): void {
                    $type = $event->toArray()['type'] ?? 'unknown';

                    $telemetry->emit('broadcast.event', [
                        'run_id' => $context->runId,
                        'parent_run_id' => $context->metadata['parent_run_id'] ?? null,
                        'swarm_class' => $swarm::class,
                        'topology' => 'sequential',
                        'execution_mode' => 'stream',
                        'event_type' => $type,
                        'sequence_index' => $sequenceIndex,
                        'duration_ms' => MonotonicTime::elapsedMilliseconds($streamStart),
                        'is_replay' => false,
                        'channel_names' => $channelNames,
                        'status' => 'broadcast',
                    ]);

                    $sequenceIndex++;
                    $event->broadcastNow($this->channels);
                });
        });
    }

    /**
     * @param  Channel|array<int, Channel|string>  $channels
     * @return array<int, string>
     */
    protected static function normalizeBroadcastChannelNames(Channel|array $channels): array
    {
        if ($channels instanceof Channel) {
            return [(string) $channels->name];
        }

        return array_values(array_map(
            static function (Channel|string $channel): string {
                return $channel instanceof Channel ? (string) $channel->name : $channel;
            },
            $channels,
        ));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->swarmClass;
    }

    protected function telemetryRunId(): ?string
    {
        return RunContext::fromPayload($this->task)->runId;
    }

    protected function telemetrySwarmClass(): ?string
    {
        return $this->swarmClass;
    }
}
