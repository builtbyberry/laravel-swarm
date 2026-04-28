<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
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

    /**
     * @param  Closure():iterable<int, SwarmStreamEvent>  $generator
     */
    public function __construct(
        public readonly string $runId,
        protected Closure $generator,
        protected ?StreamEventStore $streamEvents = null,
        protected int $ttlSeconds = 3600,
        protected bool $storesForReplay = false,
    ) {
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
        $events = [];

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
                    $this->streamEvents?->record($this->runId, $event, $this->ttlSeconds);
                }

                yield $event;
            }

            $this->events = new Collection($events);
            $returned = $stream instanceof Generator ? $stream->getReturn() : null;

            $this->streamedResponse = $returned instanceof StreamedSwarmResponse
                ? $returned
                : new StreamedSwarmResponse(
                    $returned instanceof SwarmResponse
                        ? $returned
                        : StreamedSwarmResponse::fromEvents($this->runId, $this->events),
                    $this->events,
                );

        } catch (Throwable $exception) {
            $this->events = new Collection($events);
            $this->failedException = $exception;

            throw $exception;
        }

        foreach ($this->thenCallbacks as $callback) {
            $callback($this->streamedResponse);
        }
    }
}
