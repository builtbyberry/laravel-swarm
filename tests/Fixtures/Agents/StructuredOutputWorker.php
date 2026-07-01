<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * A worker agent that declares structured output. Streaming a `HasStructuredOutput`
 * agent is unsupported by laravel/ai (#321), so this fixture exists to prove the
 * swarm-domain guard fails loud — before `$agent->stream()` is ever called — instead
 * of leaking the vendor's `InvalidArgumentException`.
 */
class StructuredOutputWorker implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a structured-output worker.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'result' => $schema->string()->required(),
        ];
    }
}
