<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeApprovalRecovery;

class NativeApprovalWire
{
    /**
     * @param  list<array{id: string, call_id: string, name: string, arguments: array<string, mixed>}>  $calls
     * @return array<string, mixed>
     */
    public static function toolCalls(array $calls, string $responseId = 'response-pause'): array
    {
        return self::response(array_map(fn (array $call): array => [
            'type' => 'function_call',
            'id' => $call['id'],
            'call_id' => $call['call_id'],
            'name' => $call['name'],
            'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
        ], $calls), $responseId);
    }

    /** @return array<string, mixed> */
    public static function final(string $text = 'continued'): array
    {
        return self::response([[
            'type' => 'message',
            'id' => 'message-final',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]], 'response-final');
    }

    /**
     * @param  list<array<string, mixed>>  $output
     * @return array<string, mixed>
     */
    private static function response(array $output, string $id): array
    {
        return [
            'id' => $id,
            'model' => 'gpt-4.1-mini',
            'status' => 'completed',
            'output' => $output,
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ];
    }
}
