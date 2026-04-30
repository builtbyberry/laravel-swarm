<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners;

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Support\MonotonicTime;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Support\SwarmPayloadLimits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Streaming\Events\Error as ProviderStreamError;
use Throwable;

class SequentialStreamRunner
{
    public function __construct(
        protected ConfigRepository $config,
        protected ContextStore $contextStore,
        protected ArtifactRepository $artifactRepository,
        protected RunHistoryStore $historyStore,
        protected StreamEventStore $streamEvents,
        protected Dispatcher $events,
        protected SequentialRunner $sequential,
        protected SwarmCapture $capture,
        protected SwarmPayloadLimits $limits,
        protected SwarmAttributeResolver $resolver,
    ) {}

    public function stream(Swarm $swarm, string|array|RunContext $task): StreamableSwarmResponse
    {
        $topology = $this->resolver->resolveTopology($swarm);
        $this->ensureSwarmHasAgents($swarm);

        if ($topology !== Topology::Sequential) {
            throw new SwarmException('Streaming is only supported for sequential swarms. '.$topology->value.' topology does not support streaming.');
        }

        $timeoutSeconds = $this->resolver->resolveTimeoutSeconds($swarm);
        $maxAgentExecutions = $this->resolver->resolveMaxAgentExecutions($swarm);
        $contextTtl = (int) $this->config->get('swarm.context.ttl', 3600);
        $context = RunContext::fromTask($task);
        $this->checkInputPayload($task, $context);
        $context->mergeMetadata([
            'swarm_class' => $swarm::class,
            'topology' => $topology->value,
        ]);

        $state = new SwarmExecutionState(
            swarm: $swarm,
            topology: $topology,
            executionMode: ExecutionMode::Stream,
            deadlineMonotonic: hrtime(true) + ($timeoutSeconds * 1_000_000_000),
            maxAgentExecutions: $maxAgentExecutions,
            ttlSeconds: $contextTtl,
            leaseSeconds: null,
            executionToken: null,
            verifyOwnership: null,
            context: $context,
            contextStore: $this->contextStore,
            artifactRepository: $this->artifactRepository,
            historyStore: $this->historyStore,
            events: $this->events,
            queueHierarchicalParallelCoordination: null,
        );

        $startedAt = null;

        return new StreamableSwarmResponse(
            runId: $context->runId,
            generator: function () use ($state, $context, $contextTtl, $swarm, &$startedAt): \Generator {
                return yield from $this->execute($state, $context, $contextTtl, $swarm, $startedAt);
            },
            streamEvents: $this->streamEvents,
            ttlSeconds: $contextTtl,
            storesForReplay: (bool) $this->config->get('swarm.streaming.replay.enabled', false),
            replayFailurePolicy: (string) $this->config->get('swarm.streaming.replay.failure_policy', 'fail'),
            onReplayFailure: function (Throwable $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): SwarmStreamError {
                return $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt);
            },
            onAbandoned: function (SwarmException $exception) use ($state, $context, $contextTtl, $swarm, &$startedAt): void {
                $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt);
            },
        );
    }

    /**
     * @return \Generator<int, SwarmStreamEvent, mixed, SwarmResponse>
     */
    protected function execute(SwarmExecutionState $state, RunContext $context, int $contextTtl, Swarm $swarm, ?int &$startedAt): \Generator
    {
        $this->contextStore->put($this->capture->activeContext($context), $contextTtl);
        $this->historyStore->start($context->runId, $swarm::class, $state->topology->value, $this->capture->context($context), $context->metadata, $contextTtl);
        $this->events->dispatch(new SwarmStarted(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $state->topology->value,
            input: $this->capture->input($context->input),
            metadata: $context->metadata,
            executionMode: ExecutionMode::Stream->value,
        ));

        yield new SwarmStreamStart(
            id: SwarmStreamEvent::newId(),
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $state->topology->value,
            input: $this->capture->input($context->input),
            metadata: $context->metadata,
            timestamp: SwarmStreamEvent::timestamp(),
        );

        $startedAt = MonotonicTime::now();

        try {
            yield from $this->sequential->stream($state);

            $response = $this->normalizeCompletionResponse(new SwarmResponse(
                output: (string) ($context->data['last_output'] ?? $context->input),
                context: $context,
                artifacts: $context->artifacts,
                usage: is_array($context->metadata['usage'] ?? null) ? $context->metadata['usage'] : [],
            ), $context, $state->topology->value);

            $capturedResponse = $this->limits->response($this->capture->response($response));
            $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
            $this->historyStore->complete($context->runId, $capturedResponse, $contextTtl);
            $this->events->dispatch(new SwarmCompleted(
                runId: $context->runId,
                swarmClass: $swarm::class,
                topology: $state->topology->value,
                output: $capturedResponse->output,
                durationMs: MonotonicTime::elapsedMilliseconds($startedAt),
                metadata: $capturedResponse->metadata,
                artifacts: $capturedResponse->artifacts,
                executionMode: ExecutionMode::Stream->value,
            ));

            yield new SwarmStreamEnd(
                id: SwarmStreamEvent::newId(),
                runId: $context->runId,
                output: $capturedResponse->output,
                usage: $capturedResponse->usage,
                metadata: $capturedResponse->metadata,
                timestamp: SwarmStreamEvent::timestamp(),
            );

            return $response;
        } catch (Throwable $exception) {
            yield $this->failStream($state, $context, $contextTtl, $swarm, $exception, $startedAt);

            throw $exception;
        }
    }

    protected function failStream(SwarmExecutionState $state, RunContext $context, int $contextTtl, Swarm $swarm, Throwable $exception, ?int $startedAt): SwarmStreamError
    {
        $this->historyStore->fail($context->runId, $exception, $contextTtl);
        $this->contextStore->put($this->capture->terminalContext($context), $contextTtl);
        $this->events->dispatch(new SwarmFailed(
            runId: $context->runId,
            swarmClass: $swarm::class,
            topology: $state->topology->value,
            exception: $this->capture->failureException($exception),
            durationMs: $startedAt !== null ? MonotonicTime::elapsedMilliseconds($startedAt) : 1,
            metadata: $this->failureMetadata($context, $exception),
            executionMode: ExecutionMode::Stream->value,
            exceptionClass: $this->failureExceptionClass($exception),
        ));

        $event = new SwarmStreamError(
            id: $exception instanceof SwarmStreamProviderException ? $exception->eventId : SwarmStreamEvent::newId(),
            runId: $context->runId,
            message: $this->capture->failureMessage($exception),
            exceptionClass: $this->failureExceptionClass($exception),
            recoverable: $exception instanceof SwarmStreamProviderException ? $exception->recoverable : false,
            metadata: $this->failureMetadata($context, $exception),
            timestamp: $exception instanceof SwarmStreamProviderException ? $exception->timestamp : SwarmStreamEvent::timestamp(),
        );

        if ($exception instanceof SwarmStreamProviderException && is_string($exception->invocationId)) {
            $event->withInvocationId($exception->invocationId);
        }

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    protected function failureMetadata(RunContext $context, Throwable $exception): array
    {
        if (! $exception instanceof SwarmStreamProviderException) {
            return $context->metadata;
        }

        return array_merge($context->metadata, [
            'provider_error' => $exception->metadata,
        ]);
    }

    /**
     * @return class-string
     */
    protected function failureExceptionClass(Throwable $exception): string
    {
        if ($exception instanceof SwarmStreamProviderException) {
            return ProviderStreamError::class;
        }

        return $exception::class;
    }

    protected function normalizeCompletionResponse(SwarmResponse $response, RunContext $context, string $topology): SwarmResponse
    {
        return new SwarmResponse(
            output: $response->output,
            steps: $response->steps,
            usage: $response->usage,
            context: $context,
            artifacts: $response->artifacts,
            metadata: array_merge(
                $context->metadata,
                $response->metadata,
                [
                    'run_id' => $context->runId,
                    'topology' => $topology,
                ],
            ),
        );
    }

    protected function checkInputPayload(string|array|RunContext $task, RunContext $context): void
    {
        if ($task instanceof RunContext) {
            $this->limits->checkContextInput($context);

            return;
        }

        $this->limits->checkInput($context->input);
    }

    protected function ensureSwarmHasAgents(Swarm $swarm): void
    {
        if ($swarm->agents() !== []) {
            return;
        }

        throw new SwarmException(class_basename($swarm).': swarm has no agents. Add at least one agent to agents().');
    }
}
