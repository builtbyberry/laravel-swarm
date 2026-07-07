<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\SequentialContactExtraction;

use {{ rootNamespace }}\Ai\Agents\SequentialContactExtraction\FieldExtractor;
use {{ rootNamespace }}\Ai\Agents\SequentialContactExtraction\RecordNormalizer;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * sequential-contact-extraction — structured output, end to end.
 *
 * Topology: Sequential. Two structured-output agents run in order to turn a
 * messy free-text blurb into a typed, schema-validated contact record:
 *
 *   FieldExtractor  — pulls the raw fields out of the prose as a JSON object.
 *   RecordNormalizer — validates and canonicalises that object against a
 *                      schema, filling defaults and flagging what is missing.
 *
 * Both agents declare a Laravel AI structured-output schema (they implement
 * `HasStructuredOutput`), so a real provider is constrained to return a single
 * parsed object rather than free-form prose. The swarm runs via `prompt()`, so
 * the #321 "structured output cannot be streamed" guard never applies.
 *
 * Demonstrates: producing a validated STRUCTURED result (not free text),
 * `HasStructuredOutput` + `schema()`, Sequential topology, the Runnable trait,
 * and one agent consuming the previous agent's structured JSON output.
 *
 * Next step: docs/sequential.md, docs/execution-modes.md
 */
#[Topology(TopologyEnum::Sequential)]
class ContactExtractor implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new FieldExtractor,
            new RecordNormalizer,
        ];
    }
}
