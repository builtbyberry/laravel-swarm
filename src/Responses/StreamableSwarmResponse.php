<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\NativeChatProtocolAdapter;
use BuiltByBerry\LaravelSwarm\Streaming\Protocols\AgentUserInteractionSwarmProtocol;
use BuiltByBerry\LaravelSwarm\Streaming\Protocols\VercelSwarmProtocol;
use Closure;
use Generator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Laravel\Ai\Streaming\Protocols\StreamProtocol;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Traversable;

/**
 * @implements IteratorAggregate<int, SwarmStreamEvent>
 */
class StreamableSwarmResponse implements IteratorAggregate, Responsable
{
    protected const STATE_PENDING = 'pending';

    protected const STATE_STREAMING = 'streaming';

    protected const STATE_COMPLETED = 'completed';

    protected const STATE_FAILED = 'failed';

    protected const STATE_ABANDONED = 'abandoned';

    /**
     * @var Collection<int, SwarmStreamEvent>
     */
    public Collection $events;

    public ?StreamedSwarmResponse $streamedResponse = null;

    /**
     * @var array<int, callable>
     */
    protected array $thenCallbacks = [];

    /**
     * @var array<int, callable>
     */
    protected array $catchCallbacks = [];

    protected bool $started = false;

    protected ?Throwable $failedException = null;

    protected ?SwarmException $abandonedException = null;

    protected string $state = self::STATE_PENDING;

    protected bool $thenCallbacksRan = false;

    protected ?StreamProtocol $nativeProtocol = null;

    protected NativeProtocolProjection $nativeProtocolProjection = NativeProtocolProjection::Workflow;

    protected bool $topologyResolverRan = false;

    protected ?string $resolvedTopology = null;

    /**
     * @param  Closure():iterable<int, SwarmStreamEvent>  $generator
     * @param  Closure(Throwable):SwarmStreamEvent|null  $onReplayFailure
     * @param  Closure(SwarmException):void|null  $onAbandoned
     * @param  Closure(Throwable):void|null  $onAbandonmentFailure
     * @param  Closure():?string|null  $topologyResolver
     * @param  Closure(string, string, NativeProtocolProjection, string):void|null  $onNativeProtocolFailure
     */
    public function __construct(
        public readonly string $runId,
        protected Closure $generator,
        protected ?StreamEventStore $streamEvents = null,
        protected int $ttlSeconds = 3600,
        protected bool $storesForReplay = false,
        protected string $replayFailurePolicy = 'fail',
        protected ?Closure $onReplayFailure = null,
        protected ?Closure $onAbandoned = null,
        protected ?Closure $onAbandonmentFailure = null,
        public readonly ?string $topology = null,
        protected bool $nativeChatProtocolsEnabled = false,
        protected ?Closure $topologyResolver = null,
        protected ?Closure $onNativeProtocolFailure = null,
    ) {
        if (! in_array($this->replayFailurePolicy, ['fail', 'continue'], true)) {
            throw new SwarmException("Invalid swarm stream replay failure policy [{$this->replayFailurePolicy}]. Supported policies: fail, continue.");
        }

        $this->events = new Collection;
    }

    public function each(callable $callback): self
    {
        foreach ($this as $event) {
            if ($callback($event) === false) {
                break;
            }
        }

        return $this;
    }

    public function then(callable $callback): self
    {
        if ($this->streamedResponse !== null) {
            $callback($this->streamedResponse);

            return $this;
        }

        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked with the terminating Throwable when the stream
     * settles as a failure — the symmetric counterpart to {@see then()}.
     *
     * `catch` fires only on a FAILED terminal (an exception thrown while iterating),
     * never on completion and never on an abandoned stream (an early `break` out of
     * the loop, which has its own teardown path). It is a HANDLER, not a suppressor:
     * the original exception still propagates to the caller after the callbacks run,
     * preserving the documented stream() re-throw contract. A callback that itself
     * throws is reported and swallowed so it cannot mask the workflow's own error.
     *
     * Registering after the stream has already failed invokes the callback
     * immediately (mirroring {@see then()}); there is no run-once latch, so a second
     * late `catch()` also fires.
     */
    public function catch(callable $callback): self
    {
        if ($this->failedException !== null) {
            $this->invokeCatchCallback($callback, $this->failedException);

            return $this;
        }

        $this->catchCallbacks[] = $callback;

        return $this;
    }

    public function storeForReplay(bool $value = true): self
    {
        if ($this->started) {
            throw new SwarmException('Persisted stream replay must be enabled before the stream is iterated.');
        }

        $this->storesForReplay = $value;

        return $this;
    }

    public function usingVercelDataProtocol(
        string $messageId,
        NativeProtocolProjection $projection = NativeProtocolProjection::Workflow,
    ): self {
        $this->validateProtocolIdentity($messageId, 'Vercel UI message ID');
        $this->configureNativeProtocol($projection);

        $this->nativeProtocol = new VercelSwarmProtocol($messageId);

        return $this;
    }

    public function usingAgentUserInteractionProtocol(
        string $threadId,
        ?string $runId = null,
        NativeProtocolProjection $projection = NativeProtocolProjection::Workflow,
    ): self {
        $this->validateProtocolIdentity($threadId, 'AG-UI thread ID');

        if ($runId !== null && $runId !== $this->runId) {
            throw new SwarmException('AG-UI protocol run ID must be the Swarm run ID when it is provided.');
        }

        $this->configureNativeProtocol($projection);

        $this->nativeProtocol = new AgentUserInteractionSwarmProtocol($threadId, $this->runId);

        return $this;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($this->nativeProtocol instanceof StreamProtocol) {
            $protocol = $this->nativeProtocol instanceof VercelSwarmProtocol ? 'vercel' : 'ag-ui';

            return $this->nativeProtocol->response(
                (new NativeChatProtocolAdapter)->adapt(
                    $this,
                    $this->nativeProtocolProjection,
                    $protocol,
                    $this->onNativeProtocolFailure,
                ),
            );
        }

        return response()->stream(function (): Generator {
            foreach ($this as $event) {
                yield 'data: '.((string) $event)."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function configureNativeProtocol(NativeProtocolProjection $projection): void
    {
        if (! $this->nativeChatProtocolsEnabled) {
            throw new SwarmException('Native chat protocol projection is disabled. Enable swarm.streaming.native_protocols.enabled first.');
        }

        if ($this->state === self::STATE_STREAMING) {
            throw new SwarmException('A native chat protocol cannot be selected while the stream is being iterated.');
        }

        if ($projection === NativeProtocolProjection::FinalAgent && $this->resolveTopology() !== Topology::Sequential->value) {
            throw new SwarmException('The final-agent native protocol projection is supported only for sequential swarms.');
        }

        $this->nativeProtocolProjection = $projection;
    }

    private function resolveTopology(): ?string
    {
        if ($this->topology !== null) {
            return $this->topology;
        }

        if (! $this->topologyResolverRan) {
            $this->topologyResolverRan = true;
            $resolved = ($this->topologyResolver ?? static fn (): null => null)();
            $this->resolvedTopology = is_string($resolved) ? $resolved : null;
        }

        return $this->resolvedTopology;
    }

    private function validateProtocolIdentity(string $value, string $label): void
    {
        if ($value === '' || trim($value) === '') {
            throw new SwarmException("{$label} must be a non-blank caller-owned string.");
        }

        if (strlen($value) > 512) {
            throw new SwarmException("{$label} must not exceed 512 bytes.");
        }

        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new SwarmException("{$label} must be valid UTF-8 without control characters.");
        }
    }

    public function getIterator(): Traversable
    {
        if ($this->state === self::STATE_ABANDONED) {
            throw $this->abandonedException ?? new SwarmException('Swarm stream response was abandoned before completion and cannot be iterated again.');
        }

        if ($this->state === self::STATE_STREAMING) {
            throw new SwarmException('Swarm stream response is already being iterated.');
        }

        if ($this->streamedResponse !== null || $this->failedException !== null) {
            foreach ($this->events as $event) {
                yield $event;
            }

            if ($this->failedException !== null) {
                throw $this->failedException;
            }

            return;
        }

        $this->started = true;
        $this->state = self::STATE_STREAMING;
        $events = [];
        $completed = false;

        try {
            $stream = ($this->generator)();

            if (! $stream instanceof Traversable) {
                throw new SwarmException('Swarm stream generator must return a traversable event stream.');
            }

            foreach ($stream as $event) {
                if (! $event instanceof SwarmStreamEvent) {
                    throw new SwarmException('Swarm stream generators must yield swarm stream events.');
                }

                $events[] = $event;

                if ($this->storesForReplay) {
                    try {
                        $this->streamEvents?->record($this->runId, $event, $this->ttlSeconds);
                    } catch (Throwable $exception) {
                        if ($this->replayFailurePolicy === 'continue') {
                            try {
                                $this->streamEvents->forget($this->runId);
                            } catch (Throwable $cleanupException) {
                                $failureEvent = $this->handleReplayFailure($cleanupException);

                                if ($failureEvent instanceof SwarmStreamEvent) {
                                    $events[] = $failureEvent;

                                    yield $failureEvent;
                                }

                                throw $cleanupException;
                            }

                            $this->storesForReplay = false;
                        } else {
                            $failureEvent = $this->handleReplayFailure($exception);

                            if ($failureEvent instanceof SwarmStreamEvent) {
                                $events[] = $failureEvent;

                                yield $failureEvent;
                            }

                            throw $exception;
                        }
                    }
                }

                if ($event instanceof SwarmStreamEnd) {
                    $this->completeFromEvents($events);
                    $completed = true;
                }

                yield $event;
            }

            $returned = $stream instanceof Generator ? $stream->getReturn() : null;

            $this->completeFromEvents($events, $returned);
            $completed = true;
        } catch (Throwable $exception) {
            $this->events = new Collection($events);
            $this->failedException = $exception;
            $this->state = self::STATE_FAILED;

            $this->runCatchCallbacks($exception);

            throw $exception;
        } finally {
            if (! $completed && $this->state === self::STATE_STREAMING) {
                $this->markAbandoned($events);
            }

            if ($this->state === self::STATE_COMPLETED && ! $this->thenCallbacksRan) {
                $this->runThenCallbacks();
            }
        }
    }

    /**
     * @param  array<int, SwarmStreamEvent>  $events
     */
    protected function completeFromEvents(array $events, mixed $returned = null): void
    {
        $this->events = new Collection($events);
        $this->streamedResponse = $returned instanceof StreamedSwarmResponse
            ? $returned
            : new StreamedSwarmResponse(
                $returned instanceof SwarmResponse
                    ? $returned
                    : StreamedSwarmResponse::fromEvents($this->runId, $this->events),
                $this->events,
            );
        $this->state = self::STATE_COMPLETED;
    }

    protected function runThenCallbacks(): void
    {
        $this->thenCallbacksRan = true;

        foreach ($this->thenCallbacks as $callback) {
            $callback($this->streamedResponse);
        }
    }

    protected function runCatchCallbacks(Throwable $exception): void
    {
        foreach ($this->catchCallbacks as $callback) {
            $this->invokeCatchCallback($callback, $exception);
        }
    }

    /**
     * Invoke one catch callback, isolating its own failure. The callback runs to
     * handle the workflow error, not to replace it: a throwing callback must never
     * mask the exception the caller is about to receive, so its throw is reported
     * and swallowed.
     */
    protected function invokeCatchCallback(callable $callback, Throwable $exception): void
    {
        try {
            $callback($exception);
        } catch (Throwable $callbackException) {
            if (function_exists('report')) {
                report($callbackException);
            }
        }
    }

    protected function handleReplayFailure(Throwable $exception): ?SwarmStreamEvent
    {
        if ($this->onReplayFailure === null) {
            return null;
        }

        $event = ($this->onReplayFailure)($exception);

        if (! $event instanceof SwarmStreamEvent) {
            throw new SwarmException('Swarm stream replay failure callback must return a swarm stream event.');
        }

        return $event;
    }

    /**
     * @param  array<int, SwarmStreamEvent>  $events
     */
    protected function markAbandoned(array $events): void
    {
        $this->events = new Collection($events);
        $this->state = self::STATE_ABANDONED;
        $this->abandonedException = new SwarmException('Swarm stream response was abandoned before completion and cannot be iterated again.');

        if ($this->onAbandoned === null) {
            return;
        }

        try {
            ($this->onAbandoned)($this->abandonedException);
        } catch (Throwable $exception) {
            try {
                if ($this->onAbandonmentFailure !== null) {
                    ($this->onAbandonmentFailure)($exception);
                }
            } catch (Throwable) {
                // Generator teardown must remain non-throwing.
            }
        }
    }
}
