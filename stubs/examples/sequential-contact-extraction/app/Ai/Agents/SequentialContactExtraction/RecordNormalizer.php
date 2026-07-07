<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Agents\SequentialContactExtraction;

use BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Step 2 of the sequential-contact-extraction starter example.
 *
 * Receives the FieldExtractor's structured JSON as its prompt (the sequential
 * contract: each agent's output is the next agent's input) and returns a
 * validated, canonical contact RECORD. It normalises the raw fields — trimming
 * whitespace, canonicalising the phone number, lower-casing the email — and
 * reports which required fields are present so a caller can trust the shape.
 *
 * Also declares a `HasStructuredOutput` {@see schema()}: the output is a typed
 * record, never prose. This is the "validate/normalise" half of the classic
 * extract → validate structured-output pipeline.
 */
class RecordNormalizer extends ScriptedAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return 'Validate and normalise the extracted contact JSON. Canonicalise the fields and report which required fields (name, email) are present. Return a JSON record.';
    }

    /**
     * The validated record shape this agent guarantees.
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
            'valid' => $schema->boolean(),
            'missing' => $schema->array(),
        ];
    }

    protected function reply(string $prompt): string
    {
        // TODO: swap ScriptedAgent for a real Promptable + HasStructuredOutput
        // agent to have a live model normalise the record. The deterministic
        // validation below mirrors the guarantees the schema encodes so the
        // example produces a genuinely validated result with no provider.
        /** @var array<string, mixed> $extracted */
        $extracted = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR) ?: [];

        $normalize = static function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return ($value === null || $value === '') ? null : $value;
        };

        $name = $normalize($extracted['name'] ?? null);
        $email = $normalize($extracted['email'] ?? null);
        $email = $email !== null ? strtolower($email) : null;
        $phone = $normalize($extracted['phone'] ?? null);
        $phone = $phone !== null ? preg_replace('/[^0-9+]/', '', $phone) : null;
        $company = $normalize($extracted['company'] ?? null);

        // Required fields for a usable contact record.
        $missing = array_values(array_filter([
            $name === null ? 'name' : null,
            $email === null ? 'email' : null,
        ]));

        return (string) json_encode([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'company' => $company,
            'valid' => $missing === [],
            'missing' => $missing,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
