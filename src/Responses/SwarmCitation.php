<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A provider-supplied source, not a verification of the answer's claims.
 * Ranges retain the provider's original agent-output coordinates; they are not
 * offsets into a concatenated, rewritten, or truncated swarm output.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SwarmCitation implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $url,
        public ?string $title,
        public string $runId,
        public int $stepIndex,
        public string $agentClass,
        public ?int $startIndex = null,
        public ?int $endIndex = null,
        public ?string $nodeId = null,
        public ?string $invocationId = null,
        public ?string $messageId = null,
        public ?string $eventId = null,
        public ?int $timestamp = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => 'url', 'url' => $this->url, 'title' => $this->title,
            'run_id' => $this->runId, 'step_index' => $this->stepIndex,
            'agent_class' => $this->agentClass, 'node_id' => $this->nodeId,
            'invocation_id' => $this->invocationId, 'message_id' => $this->messageId,
            'event_id' => $this->eventId, 'timestamp' => $this->timestamp,
            'start_index' => $this->startIndex, 'end_index' => $this->endIndex,
            'range_domain' => 'original_agent_output',
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['url', 'run_id', 'agent_class'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException('Malformed citation source.');
            }
        }
        if (($data['type'] ?? null) !== 'url' || ! is_int($data['step_index'] ?? null)) {
            throw new InvalidArgumentException('Unsupported citation source.');
        }
        foreach (['title', 'node_id', 'invocation_id', 'message_id', 'event_id'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new InvalidArgumentException('Malformed citation identity.');
            }
        }
        foreach (['start_index', 'end_index', 'timestamp'] as $key) {
            if (isset($data[$key]) && ! is_int($data[$key])) {
                throw new InvalidArgumentException('Malformed citation range.');
            }
        }

        return new self(
            $data['url'], $data['title'] ?? null, $data['run_id'], $data['step_index'],
            $data['agent_class'], $data['start_index'] ?? null, $data['end_index'] ?? null,
            $data['node_id'] ?? null, $data['invocation_id'] ?? null,
            $data['message_id'] ?? null, $data['event_id'] ?? null, $data['timestamp'] ?? null,
        );
    }

    public function withNodeId(string $nodeId): self
    {
        return new self($this->url, $this->title, $this->runId, $this->stepIndex, $this->agentClass,
            $this->startIndex, $this->endIndex, $nodeId, $this->invocationId,
            $this->messageId, $this->eventId, $this->timestamp);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
