<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use JsonException;

class RunContext
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     * @param  array<int, SwarmArtifact>  $artifacts
     */
    public function __construct(
        public string $runId,
        public string $input,
        public array $data = [],
        public array $metadata = [],
        public array $artifacts = [],
    ) {}

    /**
     * @param  string|array<string, mixed>|self  $input
     */
    public static function from(string|array|self $input, ?string $runId = null): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if (is_array($input)) {
            self::assertExplicitContextPayload($input);

            return self::fromValidatedPayload($input, $runId);
        }

        return new self(
            runId: $runId ?? self::newRunId(),
            input: self::normalizePrompt($input),
        );
    }

    /**
     * @param  string|array<string, mixed>|self  $task
     */
    public static function fromTask(string|array|self $task): self
    {
        if ($task instanceof self) {
            return $task;
        }

        if (is_array($task)) {
            return new self(
                runId: self::newRunId(),
                input: self::normalizePrompt($task),
                data: $task,
            );
        }

        return new self(
            runId: self::newRunId(),
            input: self::normalizePrompt($task),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, ?string $runId = null): self
    {
        self::assertSerializedPayload($payload);

        return self::fromValidatedPayload($payload, $runId);
    }

    public static function newRunId(): string
    {
        return (string) str()->uuid();
    }

    public function prompt(): string
    {
        return (string) ($this->data['last_output'] ?? $this->input);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergeData(array $values): self
    {
        $this->data = array_merge($this->data, $values);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergeMetadata(array $values): self
    {
        $this->metadata = array_merge($this->metadata, $values);

        return $this;
    }

    public function addArtifact(SwarmArtifact $artifact): self
    {
        $this->artifacts[] = $artifact;

        return $this;
    }

    /**
     * @return array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'input' => $this->input,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'artifacts' => array_map(
                static fn (SwarmArtifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
        ];
    }

    /**
     * @return array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>}
     */
    public function toQueuePayload(): array
    {
        return $this->toArray();
    }

    /**
     * @param  string|array<string, mixed>  $input
     */
    protected static function normalizePrompt(string|array $input): string
    {
        if (is_string($input)) {
            return $input;
        }

        try {
            return json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwarmException('Structured swarm task input must be JSON-encodable plain data.', previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function fromValidatedPayload(array $payload, ?string $runId = null): self
    {
        return new self(
            runId: (string) ($payload['run_id'] ?? $runId ?? self::newRunId()),
            input: $payload['input'],
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            artifacts: self::hydrateArtifacts($payload['artifacts'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function assertExplicitContextPayload(array $payload): void
    {
        if (! array_key_exists('input', $payload)) {
            throw new SwarmException('RunContext::from() expects an explicit context payload array containing an [input] key.');
        }

        if (! is_string($payload['input'])) {
            throw new SwarmException('RunContext::from() expects input to be a string, ['.gettype($payload['input']).'] given. Use RunContext::fromTask() to pass structured arrays as task input.');
        }

        if (array_key_exists('data', $payload) && ! is_array($payload['data'])) {
            throw new SwarmException('RunContext::from() expects [data] to be an array.');
        }

        if (array_key_exists('metadata', $payload) && ! is_array($payload['metadata'])) {
            throw new SwarmException('RunContext::from() expects [metadata] to be an array.');
        }

        if (array_key_exists('artifacts', $payload) && ! is_array($payload['artifacts'])) {
            throw new SwarmException('RunContext::from() expects [artifacts] to be an array.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function assertSerializedPayload(array $payload): void
    {
        foreach (['run_id', 'input', 'data', 'metadata', 'artifacts'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new SwarmException('RunContext::fromPayload() expects serialized queue payload keys: [run_id, input, data, metadata, artifacts].');
            }
        }

        if (! is_string($payload['input'])) {
            throw new SwarmException('RunContext::fromPayload() expects input to be a string, ['.gettype($payload['input']).'] given.');
        }

        if (! is_array($payload['data'])) {
            throw new SwarmException('RunContext::fromPayload() expects [data] to be an array.');
        }

        if (! is_array($payload['metadata'])) {
            throw new SwarmException('RunContext::fromPayload() expects [metadata] to be an array.');
        }

        if (! is_array($payload['artifacts'])) {
            throw new SwarmException('RunContext::fromPayload() expects [artifacts] to be an array.');
        }
    }

    /**
     * @return array<int, SwarmArtifact>
     */
    protected static function hydrateArtifacts(mixed $artifacts): array
    {
        if (! is_array($artifacts)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $artifact): ?SwarmArtifact {
            if (! is_array($artifact) || ! isset($artifact['name'])) {
                return null;
            }

            return new SwarmArtifact(
                name: (string) $artifact['name'],
                content: $artifact['content'] ?? null,
                metadata: is_array($artifact['metadata'] ?? null) ? $artifact['metadata'] : [],
                stepAgentClass: isset($artifact['step_agent_class']) ? (string) $artifact['step_agent_class'] : null,
            );
        }, $artifacts)));
    }
}
