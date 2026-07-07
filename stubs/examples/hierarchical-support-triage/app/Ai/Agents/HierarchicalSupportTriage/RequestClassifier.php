<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * The hierarchical coordinator: it reads the incoming support request, decides
 * which single handler should answer it, and returns a structured route plan.
 *
 * A hierarchical coordinator is the one agent that must implement Laravel AI
 * structured output ({@see HasStructuredOutput}) and declare the top-level
 * route-plan shape (`start_at` + `nodes`). Laravel Swarm reads the coordinator's
 * text output, decodes it against that schema, validates the plan as a DAG, and
 * then executes the routed worker nodes. There is no separate `route()`
 * callback — the coordinator is the single source of truth for what runs next.
 *
 * This example ships as a `ScriptedAgent` so the whole swarm runs end-to-end
 * with no live model. The classification here is a deterministic keyword match
 * over the request text; a real coordinator would let the model fill the schema.
 * Swap this for a `Promptable` agent (keeping `HasStructuredOutput`) to route
 * with a live model.
 */
class RequestClassifier extends ScriptedAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return 'Classify the support request as billing, technical, or general, '
            .'then route it to exactly one handler.';
    }

    /**
     * The route-plan schema the coordinator promises to fill. Laravel Swarm
     * validates the coordinator's output against this shape before executing.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }

    /**
     * Classify the request and emit the route plan as JSON. Laravel Swarm
     * `json_decode`s this reply and normalizes it into the validated plan.
     */
    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable agent (keep
        // HasStructuredOutput) to let a live model fill the route plan.
        [$agent, $prompt, $category] = $this->classify($prompt);

        return (string) json_encode([
            'start_at' => 'respond',
            'nodes' => [
                'respond' => [
                    'type' => 'worker',
                    'agent' => $agent,
                    'prompt' => $prompt,
                    'metadata' => ['category' => $category],
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'finish',
                    'output_from' => 'respond',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Pick the handler for this request. Returns the worker agent class, the
     * base prompt for that worker, and a human-readable category label.
     *
     * @return array{0: class-string, 1: string, 2: string}
     */
    private function classify(string $request): array
    {
        $needle = Str::lower($request);

        if (Str::contains($needle, ['refund', 'invoice', 'charge', 'billing', 'payment', 'subscription'])) {
            return [BillingResponder::class, 'Answer this billing request: '.$request, 'billing'];
        }

        if (Str::contains($needle, ['error', 'bug', 'crash', 'broken', 'login', 'timeout', 'exception'])) {
            return [TechnicalResponder::class, 'Answer this technical request: '.$request, 'technical'];
        }

        return [GeneralResponder::class, 'Answer this general request: '.$request, 'general'];
    }
}
