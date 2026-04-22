<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;

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
            $prompt = self::normalizePrompt($input['input'] ?? $input);
            $data = $input['data'] ?? [];
            $metadata = $input['metadata'] ?? [];

            return new self(
                runId: $runId ?? self::newRunId(),
                input: $prompt,
                data: is_array($data) ? $data : ['value' => $data],
                metadata: is_array($metadata) ? $metadata : ['value' => $metadata],
            );
        }

        return new self(
            runId: $runId ?? self::newRunId(),
            input: self::normalizePrompt($input),
        );
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
     * @param  string|array<string, mixed>  $input
     */
    protected static function normalizePrompt(string|array $input): string
    {
        if (is_string($input)) {
            return $input;
        }

        $encoded = json_encode($input);

        return $encoded !== false ? $encoded : serialize($input);
    }
}
