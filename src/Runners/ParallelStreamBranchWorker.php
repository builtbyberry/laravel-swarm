<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\ResolvedCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot;
use BuiltByBerry\LaravelSwarm\Memory\NullSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Memory\SnapshotToolCallNormalizer;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\DetachedParallelStreamStores;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelStreamProtocol;
use BuiltByBerry\LaravelSwarm\Streaming\ProviderToolDataLimits;
use BuiltByBerry\LaravelSwarm\Streaming\ProviderToolEventMapper;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use BuiltByBerry\LaravelSwarm\Streaming\StreamStepAccumulator;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\AdHocParallelSwarm;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentInvoker;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentSettingsAttempt;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/** @internal */
final class ParallelStreamBranchWorker
{
    /**
     * @param  class-string  $swarmClass
     * @param  class-string  $agentClass
     * @param  array<string, mixed>  $contextPayload
     * @param  list<string>  $nativeSettingsAttemptIds
     * @param  array<int, array{scope: string, scope_id: string, key: string, value: mixed, metadata: array<string, mixed>, created_at: string|null, updated_at: string|null}>  $snapshotEntries
     * @param  array{capture: array{inputs: string, outputs: string}, citations: array{max_count: int, max_bytes: int}, provider_tools: array{max_event_bytes: int, max_step_bytes: int, max_depth: int}, native_results: array<string, int>}  $workerSettings
     * @return array{branch_id: string, terminal_sent: true}
     */
    public function run(
        string $endpoint,
        string $token,
        float $deadline,
        int $maxFrameBytes,
        string $branchId,
        string $runId,
        string $swarmClass,
        string $agentClass,
        int $index,
        bool $adHoc,
        string $input,
        array $contextPayload,
        array $nativeSettingsAttemptIds,
        array $snapshotEntries,
        int $ttlSeconds,
        int $maxAgentExecutions,
        array $workerSettings,
    ): array {
        $timeout = max(0.001, ($deadline - (float) hrtime(true)) / 1_000_000_000);
        $socket = @stream_socket_client('tcp://'.$endpoint, $errorCode, $errorMessage, $timeout);
        if (! is_resource($socket)) {
            throw new SwarmException("Parallel stream branch [{$branchId}] could not connect to its loopback transport [{$errorCode}: {$errorMessage}].");
        }
        stream_set_blocking($socket, false);

        $frame = static function (string $type, array $payload = []) use ($socket, $token, $branchId, $deadline, $maxFrameBytes): void {
            ParallelStreamProtocol::writeFrame($socket, [
                'v' => ParallelStreamProtocol::VERSION,
                'token' => $token,
                'branch_id' => $branchId,
                'type' => $type,
                'payload' => $payload,
            ], $maxFrameBytes, $deadline);
            ParallelStreamProtocol::awaitAcknowledgement($socket, $deadline);
        };

        $frame('hello');

        try {
            $container = Container::getInstance();
            $workerContext = RunContext::fromPayload($contextPayload, $runId);
            $attempt = new NativeAgentSettingsAttempt($nativeSettingsAttemptIds);
            $activeContextEntered = false;
            ActiveRunContext::enter($runId, $swarmClass, $workerContext);
            $activeContextEntered = true;

            $agent = $container->make(ParallelAgentResolver::class)
                ->resolve($swarmClass, $agentClass, $index, $adHoc);
            StructuredOutputStreamingException::guard($agent, $branchId);
            $swarm = $adHoc ? new AdHocParallelSwarm([$agent]) : $container->make($swarmClass);
            if (! $swarm instanceof Swarm) {
                throw new SwarmException("Parallel swarm [{$swarmClass}] must reconstruct to a swarm in its branch process.");
            }

            $detachedStores = new DetachedParallelStreamStores;
            $state = new SwarmExecutionState(
                swarm: $swarm,
                topology: Topology::Parallel,
                executionMode: ExecutionMode::Stream,
                deadlineMonotonic: $deadline,
                maxAgentExecutions: $maxAgentExecutions,
                ttlSeconds: $ttlSeconds,
                leaseSeconds: null,
                executionToken: null,
                verifyOwnership: null,
                context: $workerContext,
                contextStore: $detachedStores,
                artifactRepository: $detachedStores,
                historyStore: $detachedStores,
                events: $container->make(Dispatcher::class),
                queueHierarchicalParallelCoordination: null,
                nativeSettingsAttempt: $attempt,
            );

            $detachedSnapshots = new NullSnapshotsMemory;
            $workerConfig = new Repository(['swarm' => [
                'citations' => $workerSettings['citations'],
                'provider_tools' => $workerSettings['provider_tools'],
                'native_results' => $workerSettings['native_results'],
            ]]);
            $nativeResults = NativeStepResultProjector::fromResolvedLimits($workerSettings['native_results']);
            $capture = new SwarmCapture(
                config: $workerConfig,
                policy: new ResolvedCapturePolicy(
                    $this->captureDecision($workerSettings['capture']['inputs']),
                    $this->captureDecision($workerSettings['capture']['outputs']),
                ),
                nativeResults: $nativeResults,
            );
            $mapper = new StreamEventMapper(
                capture: $capture,
                snapshots: $detachedSnapshots,
                outcomes: $container->make(NativeOutcomeValidator::class),
                citations: NativeCitationEvidence::forConcurrentWorker($workerSettings['citations']),
                providerTools: new ProviderToolEventMapper($capture, new ProviderToolDataLimits($workerConfig)),
                nativeResults: $nativeResults,
            );
            $accumulator = new StreamStepAccumulator(new MemorySnapshot($runId, $index, $snapshotEntries));
            $startedAt = MonotonicTime::now();
            try {
                $invocation = $workerContext->nativeInvocation($branchId, $input, $attempt);
                $stream = NativeAgentInvoker::stream($agent, $invocation);
                foreach ($stream as $event) {
                    $mapped = $mapper->map($event, $state, $index, $agent, $accumulator);
                    if ($mapped !== null) {
                        $frame('event', $mapped->toArray());
                    }
                }
                $stream->then(fn ($response) => $mapper->complete($response, $state, $index, $agent, $accumulator));
            } finally {
                foreach ($accumulator->pendingToolCalls as $unpairedCall) {
                    $accumulator->snapshot = $detachedSnapshots->appendToolCall(
                        $accumulator->snapshot,
                        SnapshotToolCallNormalizer::entry($unpairedCall),
                    );
                }
            }

            $frame('terminal', [
                'ok' => true,
                'output' => $accumulator->output,
                'citation_evidence' => $accumulator->citationEvidence->toArray(),
                'usage' => $accumulator->stepUsage,
                'class' => $agentClass,
                'duration_ms' => MonotonicTime::elapsedMilliseconds($startedAt),
                'tool_calls' => $accumulator->snapshot->toolCalls,
                'native_settings_consumed' => $attempt->ids(),
                'native_result' => $accumulator->nativeResult?->toArray(),
                'unknown_event_classes' => array_keys($accumulator->unknownEventClasses),
            ]);

            return ['branch_id' => $branchId, 'terminal_sent' => true];
        } catch (Throwable $exception) {
            try {
                $frame('terminal', [
                    'ok' => false,
                    'usage' => isset($accumulator) ? $accumulator->stepUsage : [],
                    'failure' => ConcurrentAgentResult::failureDescriptor($exception),
                ]);
            } catch (Throwable) {
                throw $exception;
            } finally {
                fclose($socket);
            }

            return ['branch_id' => $branchId, 'terminal_sent' => true];
        } finally {
            if (($activeContextEntered ?? false) === true) {
                ActiveRunContext::exit();
            }
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function captureDecision(string $name): CaptureDecision
    {
        return match ($name) {
            'Full' => CaptureDecision::Full,
            'Skip' => CaptureDecision::Skip,
            default => CaptureDecision::Redact,
        };
    }
}
