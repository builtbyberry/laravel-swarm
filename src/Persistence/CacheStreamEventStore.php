<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\ResolvesSwarmCacheStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class CacheStreamEventStore implements StreamEventStore
{
    use ResolvesSwarmCacheStore;

    public function __construct(
        protected CacheFactory $cacheFactory,
        protected ConfigRepository $config,
    ) {}

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $events = $this->store()->get($this->key($runId), []);

        if (! is_array($events)) {
            $events = [];
        }

        $events[] = $event->toArray();

        $this->store()->put($this->key($runId), $events, $ttlSeconds);
    }

    public function forget(string $runId): void
    {
        $this->store()->forget($this->key($runId));
    }

    public function events(string $runId): iterable
    {
        $events = $this->store()->get($this->key($runId), []);

        if (! is_array($events)) {
            return;
        }

        foreach ($events as $event) {
            if (is_array($event)) {
                yield SwarmStreamEvent::fromArray($event);
            }
        }
    }

    protected function key(string $runId): string
    {
        return (string) $this->config->get('swarm.streaming.replay.prefix', 'swarm:stream:').$runId;
    }

    protected function store(): Repository
    {
        return $this->resolveCacheStore($this->cacheFactory, $this->config, 'streaming.replay');
    }
}
