<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use ArrayAccess;
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\DurableWaitOutcome;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use JsonException;

/**
 * Per-run state handle that carries the run id, input, and a write-through
 * cache of Run-scoped {@see SwarmMemory} entries.
 *
 * `$data` is the in-memory cache for the Run-scoped memory view. Writes go
 * through {@see mergeData()} (and the {@see ArrayAccess} surface) to both
 * the cache and the bound {@see SwarmMemory}, so reads through `$data`
 * stay array-fast on the hot path (`prompt()` and runner inter-step relays)
 * while the canonical store is always current. This mirrors Eloquent's
 * attribute caching: the in-memory representation is authoritative for the
 * duration of the run; persistence happens on the documented mutation paths.
 *
 * `$metadata` and `$artifacts` are framework-operational state — durable
 * labels, actor binding, conversation binding, signal payloads, wait outcomes,
 * captured artifacts.
 * Those intentionally do not write through to SwarmMemory: they're not
 * agent-readable knowledge, just plumbing.
 *
 * @implements ArrayAccess<string, mixed>
 */
class RunContext implements ArrayAccess
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

    /**
     * Build a RunContext suitable for ad-hoc test setup.
     *
     * Defaults to a deterministic run id ("fake-run-id"), empty input, no
     * data, no metadata, no artifacts, and no actor. Any of those slots can be
     * overridden via the $overrides array using the keys: run_id, input, data,
     * metadata, artifacts, actor. The actor override accepts anything
     * RunContext::withActor() accepts (Actor, Authenticatable, "type:id" or
     * bare-id string, or null) and is applied via that existing builder so the
     * actor is stored under the reserved metadata.actor slot.
     *
     * Compose with the existing fluent builders for richer setups:
     *
     *   RunContext::fake(['input' => 'draft outline'])
     *       ->withActor('user:42')
     *       ->withLabels(['tenant' => 'acme'])
     *       ->mergeData(['draft_id' => 7]);
     *
     * This is a test helper. Do not use it in production code paths.
     *
     * @param  array{run_id?: string, input?: string, data?: array<string, mixed>, metadata?: array<string, mixed>, artifacts?: array<int, SwarmArtifact>, actor?: Actor|Authenticatable|string|null, conversation_id?: string|null}  $overrides
     */
    public static function fake(array $overrides = []): self
    {
        $context = new self(
            runId: $overrides['run_id'] ?? 'fake-run-id',
            input: $overrides['input'] ?? '',
            data: $overrides['data'] ?? [],
            metadata: $overrides['metadata'] ?? [],
            artifacts: $overrides['artifacts'] ?? [],
        );

        if (array_key_exists('actor', $overrides)) {
            // Reassign the return even though withActor() mutates $this->metadata
            // in place today and returns $this. A future refactor toward true
            // immutability would silently break the fake helper otherwise; the
            // reassignment costs nothing and futureproofs the call.
            $context = $context->withActor($overrides['actor']);
        }

        if (array_key_exists('conversation_id', $overrides)) {
            $context = $context->withConversationId($overrides['conversation_id']);
        }

        return $context;
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

        $this->writeThroughToMemory($values);

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

    /**
     * Bind the actor for this run. Accepts an Actor value object, an
     * Authenticatable, a "type:id" or bare-id string, or null to clear.
     *
     * The normalized actor is stored under the reserved metadata key "actor",
     * which the audit dispatcher always emits regardless of the metadata
     * allowlist. Calling withActor() takes precedence over the bound
     * ActorResolver at run entry.
     */
    public function withActor(Actor|Authenticatable|string|null $actor): self
    {
        if ($actor === null) {
            unset($this->metadata['actor']);

            return $this;
        }

        $this->metadata['actor'] = Actor::fromAny($actor)->toArray();

        return $this;
    }

    public function actor(): ?Actor
    {
        $payload = $this->metadata['actor'] ?? null;

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return Actor::fromArray($payload);
    }

    /**
     * Bind the conversation this run belongs to. Accepts an opaque conversation
     * id string, or null (or empty/whitespace) to clear.
     *
     * The id is stored under the reserved metadata key "conversation_id" rather
     * than a dedicated column — the same mechanism {@see withActor()} uses — so
     * it threads through every queue, cache, and durable persistence path, all
     * of which carry the metadata map wholesale. The key is reserved in
     * `EvidenceEnvelope::RESERVED_METADATA_KEYS`, so its value is always
     * emitted on audit and telemetry payloads as run provenance — keep
     * conversation ids opaque (non-PII), since the value bypasses the operator
     * metadata allowlist.
     *
     * Once set, the {@see MemoryScope::Conversation} scope becomes addressable:
     * the agent-visible memory view gathers Conversation-scoped entries under
     * this id, and the agent memory tools (`recall`/`remember`) read and write
     * it. With no conversation id bound the scope stays unaddressable and those
     * call sites skip or decline it gracefully, exactly as before.
     */
    public function withConversationId(?string $conversationId): self
    {
        $conversationId = $conversationId !== null ? trim($conversationId) : null;

        if ($conversationId === null || $conversationId === '') {
            unset($this->metadata['conversation_id']);

            return $this;
        }

        $this->metadata['conversation_id'] = $conversationId;

        return $this;
    }

    /**
     * The conversation id bound to this run, or null when the run does not
     * belong to a conversation. Reads back the reserved metadata slot set by
     * {@see withConversationId()}.
     */
    public function conversationId(): ?string
    {
        $id = $this->metadata['conversation_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
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

    // ------------------------------------------------------------------
    // ArrayAccess — direct SwarmMemory Run-scope reads/writes
    // ------------------------------------------------------------------
    //
    // `$context[$key]` is the canonical context-level memory accessor:
    // every operation goes straight through {@see SwarmMemory} so callers
    // see the latest value, including writes made outside this RunContext
    // instance (e.g. from another worker on a durable parallel branch).
    // The cached `$data` projection is intentionally bypassed here — its
    // purpose is to keep `prompt()` and runner inter-step relays fast, not
    // to be the source of truth.
    //
    // When SwarmMemory isn't bound (e.g. test setups that never boot the
    // app), every operation falls back to the cached `$data` array so
    // POPO-style usage keeps working.

    public function offsetExists(mixed $offset): bool
    {
        $memory = $this->resolveMemory();

        if ($memory === null) {
            return array_key_exists((string) $offset, $this->data);
        }

        return $memory->entry(MemoryScope::Run, $this->runId, (string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $memory = $this->resolveMemory();

        if ($memory === null) {
            return $this->data[(string) $offset] ?? null;
        }

        return $memory->get(MemoryScope::Run, $this->runId, (string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new SwarmException('RunContext memory keys are addressed by string — appending without a key is not supported.');
        }

        $key = (string) $offset;
        $this->data[$key] = $value;

        $memory = $this->resolveMemory();

        if ($memory !== null) {
            $memory->put(MemoryScope::Run, $this->runId, $key, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $key = (string) $offset;
        unset($this->data[$key]);

        $memory = $this->resolveMemory();

        if ($memory !== null) {
            $memory->forget(MemoryScope::Run, $this->runId, $key);
        }
    }

    /**
     * Mirror {@see mergeData()} writes into the bound {@see SwarmMemory}
     * Run scope. Best-effort: when no SwarmMemory is bound (typical for
     * unbooted-app test setups) the cache is the only writer and the
     * canonical store is whatever the test asserts on directly.
     *
     * @param  array<string, mixed>  $values
     */
    protected function writeThroughToMemory(array $values): void
    {
        $memory = $this->resolveMemory();

        if ($memory === null) {
            return;
        }

        foreach ($values as $key => $value) {
            $memory->put(MemoryScope::Run, $this->runId, (string) $key, $value);
        }
    }

    /**
     * Resolve the bound {@see SwarmMemory} or null when no container/binding
     * is available. Uses `Container::getInstance()` rather than the global
     * `app()` helper so RunContext can be constructed in tests that never
     * boot the framework. Returns null cheaply — callers must guard.
     */
    protected function resolveMemory(): ?SwarmMemory
    {
        $container = Container::getInstance();

        if (! $container->bound(SwarmMemory::class)) {
            return null;
        }

        /** @var SwarmMemory $memory */
        $memory = $container->make(SwarmMemory::class);

        return $memory;
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

    /**
     * @param  list<array<string, mixed>>|array<int, mixed>  $artifacts
     */
    protected static function validateArtifactsPayload(array $artifacts, string $path): void
    {
        foreach ($artifacts as $index => $artifact) {
            ArtifactPayload::normalize($artifact, "{$path}.{$index}");
        }
    }
}
