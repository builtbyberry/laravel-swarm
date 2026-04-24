<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;

class SwarmHistoryQuery
{
    public function __construct(
        protected RunHistoryStore $historyStore,
        protected ?string $swarmClass = null,
        protected ?string $status = null,
        protected int $limit = 25,
    ) {}

    public function forSwarm(string $swarmClass): self
    {
        return new self($this->historyStore, $swarmClass, $this->status, $this->limit);
    }

    public function withStatus(string $status): self
    {
        return new self($this->historyStore, $this->swarmClass, $status, $this->limit);
    }

    public function limit(int $limit): self
    {
        return new self($this->historyStore, $this->swarmClass, $this->status, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->historyStore->query($this->swarmClass, $this->status, $this->limit);
    }
}
