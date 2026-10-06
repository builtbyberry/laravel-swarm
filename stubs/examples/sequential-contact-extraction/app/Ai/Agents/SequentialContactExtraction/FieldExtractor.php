<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialContactExtraction;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Step 1 of the sequential-contact-extraction starter example.
 *
 * Reads the unstructured blurb (the task prompt) and returns the raw fields it
 * can find as a JSON object. Implements `HasStructuredOutput` and declares a
 * {@see schema()}, so a real Laravel AI provider is constrained to emit a
 * single parsed object matching that shape — not free-form prose. That is what
 * makes this STRUCTURED output rather than text.
 *
 * Extends ScriptedAgent so the deterministic offline example runs with no
 * provider configured. For model behavior, generate FieldExtractor with
 * Laravel AI's `make:agent --structured` command and port the application-owned
 * instructions and schema.
 */
class FieldExtractor extends ScriptedAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return 'Extract the contact from the message. Return name, email, phone, and company as a JSON object. Use null for any field you cannot find.';
    }

    /**
     * The structured-output schema a real provider is constrained to fill.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->nullable(),
            'email' => $schema->string()->nullable(),
            'phone' => $schema->string()->nullable(),
            'company' => $schema->string()->nullable(),
        ];
    }

    protected function reply(string $prompt): string
    {
        // For model behavior, generate FieldExtractor with make:agent --structured
        // and port this schema. This deterministic offline reply does a
        // light regex pull from the blurb so the demo genuinely reflects its
        // input; a real provider returns the same shape — a single JSON object,
        // not prose.
        $email = preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $prompt, $m) === 1 ? $m[0] : null;
        $phone = preg_match('/(\+?\d[\d\s-]{6,}\d)/', $prompt, $m) === 1 ? trim($m[1]) : null;

        // Name: the words before the first "at"/email/phone signal, if any.
        $name = preg_match('/(?:this is|i\'m|i am|contact|reach)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i', $prompt, $m) === 1
            ? trim($m[1])
            : null;

        $company = preg_match('/\b(?:at|from|with)\s+([A-Z][\w&.\-]*(?:\s+[A-Z][\w&.\-]*)*\s+(?:Inc|LLC|Ltd|Corp|Co)\b)/', $prompt, $m) === 1
            ? trim($m[1])
            : null;

        return (string) json_encode([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
