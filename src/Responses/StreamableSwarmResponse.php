<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use Closure;
use Generator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use IteratorAggregate;
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

    protected bool $started = false;

    protected ?Throwable $failedException = null;

    protected ?SwarmException $abandonedException = null;

    protected string $state = self::STATE_PENDING;

    protected bool $thenCallbacksRan = false;

    /**
     * @param  Closure():iterable<int, SwarmStreamEvent>  $generator
     * @param  Closure(Throwable):SwarmStreamEvent|null  $onReplayFailure
     * @param  Closure(SwarmException):void|null  $onAbandoned
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

    public function storeForReplay(bool $value = true): self
    {
        if ($this->started) {
            throw new SwarmException('Persisted stream replay must be enabled before the stream is iterated.');
        }

        $this->storesForReplay = $value;

        return $this;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return response()->stream(function (): Generator {
            foreach ($this as $event) {
                yield 'data: '.((string) $event)."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, headers: ['Content-Type' => 'text/event-stream']);
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
        } catch (Throwable) {
            //
        }
    }
}
