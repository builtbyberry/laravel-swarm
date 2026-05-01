<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\DurableWaitOutcome;
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
            $data = PlainData::array($task, 'task');

            return new self(
                runId: self::newRunId(),
                input: self::normalizePrompt($data),
                data: $data,
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
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function withLabels(array $labels): self
    {
        $this->metadata['durable_labels'] = array_merge(
            $this->labels(),
            self::validateLabels($labels),
        );

        return $this;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function withDetails(array $details): self
    {
        PlainData::array($details, 'details');

        $this->metadata['durable_details'] = array_merge($this->details(), $details);

        return $this;
    }

    public function label(string $key): mixed
    {
        return $this->labels()[$key] ?? null;
    }

    public function detail(string $key): mixed
    {
        return $this->details()[$key] ?? null;
    }

    public function signalPayload(string $name): mixed
    {
        $signals = is_array($this->metadata['durable_signals'] ?? null) ? $this->metadata['durable_signals'] : [];

        return $signals[$name]['payload'] ?? null;
    }

    public function waitOutcome(string $name): ?DurableWaitOutcome
    {
        $outcomes = is_array($this->metadata['durable_wait_outcomes'] ?? null) ? $this->metadata['durable_wait_outcomes'] : [];
        $outcome = $outcomes[$name] ?? null;

        if (! is_array($outcome)) {
            return null;
        }

        return new DurableWaitOutcome(
            name: $name,
            status: is_string($outcome['status'] ?? null) ? $outcome['status'] : 'unknown',
            payload: $outcome['payload'] ?? null,
            timedOut: (bool) ($outcome['timed_out'] ?? false),
        );
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    public function labels(): array
    {
        return self::validateLabels(is_array($this->metadata['durable_labels'] ?? null) ? $this->metadata['durable_labels'] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return is_array($this->metadata['durable_details'] ?? null) ? PlainData::array($this->metadata['durable_details'], 'details') : [];
    }

    /**
     * @return array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>}
     */
    public function toArray(): array
    {
        return self::validateSerializedPayload([
            'run_id' => $this->runId,
            'input' => $this->input,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'artifacts' => array_map(
                static fn (SwarmArtifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
        ], 'RunContext');
    }

    /**
     * @return array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>}
     */
    public function toQueuePayload(): array
    {
        return self::validateSerializedPayload($this->toArray(), 'RunContext queue payload');
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
            throw new SwarmException('Structured swarm task input must be plain data that can be encoded as JSON.', previous: $exception);
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
            data: is_array($payload['data'] ?? null) ? PlainData::array($payload['data'], 'data') : [],
            metadata: is_array($payload['metadata'] ?? null) ? PlainData::array($payload['metadata'], 'metadata') : [],
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

        if (array_key_exists('data', $payload)) {
            PlainData::array($payload['data'], 'data');
        }

        if (array_key_exists('metadata', $payload)) {
            PlainData::array($payload['metadata'], 'metadata');
        }

        if (array_key_exists('artifacts', $payload)) {
            self::validateArtifactsPayload($payload['artifacts'], 'artifacts');
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

        if (! is_string($payload['run_id'])) {
            throw new SwarmException('RunContext::fromPayload() expects run_id to be a string, ['.gettype($payload['run_id']).'] given.');
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

        self::validateSerializedPayload($payload, 'RunContext payload');
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, bool|int|float|string|null>
     */
    protected static function validateLabels(array $labels): array
    {
        $validated = [];

        foreach ($labels as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new SwarmException('Durable run labels must use non-empty string keys.');
            }

            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null) {
                throw new SwarmException("Durable run label [{$key}] must be a scalar or null.");
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @return array<int, SwarmArtifact>
     */
    protected static function hydrateArtifacts(mixed $artifacts): array
    {
        if (! is_array($artifacts)) {
            return [];
        }

        $hydrated = [];

        foreach ($artifacts as $index => $artifact) {
            $payload = ArtifactPayload::normalize($artifact, "artifacts.{$index}");

            $hydrated[] = new SwarmArtifact(
                name: $payload['name'],
                content: $payload['content'],
                metadata: $payload['metadata'],
                stepAgentClass: $payload['step_agent_class'],
            );
        }

        return $hydrated;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>}
     */
    protected static function validateSerializedPayload(array $payload, string $path): array
    {
        PlainData::array($payload['data'], $path.'.data');
        PlainData::array($payload['metadata'], $path.'.metadata');
        self::validateArtifactsPayload($payload['artifacts'], $path.'.artifacts');

        /** @var array{run_id: string, input: string, data: array<string, mixed>, metadata: array<string, mixed>, artifacts: array<int, array{name: string, content: mixed, metadata: array<string, mixed>, step_agent_class: string|null}>} $payload */
        return $payload;
    }

    protected static function validateArtifactsPayload(array $artifacts, string $path): void
    {
        foreach ($artifacts as $index => $artifact) {
            ArtifactPayload::normalize($artifact, "{$path}.{$index}");
        }
    }
}
