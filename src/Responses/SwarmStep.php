<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class SwarmStep implements Arrayable, JsonSerializable
{
    public readonly CitationEvidence $citationEvidence;

    /** @var list<SwarmCitation> */
    public readonly array $citations;

    /**
     * @param  array<int, SwarmArtifact>  $artifacts
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $agentClass,
        public string $input,
        public string $output,
        public array $artifacts = [],
        public array $metadata = [],
        ?CitationEvidence $citationEvidence = null,
    ) {
        $this->citationEvidence = $citationEvidence ?? new CitationEvidence;
        $this->citations = $this->citationEvidence->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_class' => $this->agentClass,
            'input' => $this->input,
            ...$this->citationEvidence->toArray(),
            'output' => $this->output,
            'artifacts' => array_map(
                static fn (SwarmArtifact $artifact): array => $artifact->toArray(),
                $this->artifacts,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
