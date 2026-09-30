<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

/**
 * Controlled OpenAI Responses wire for the native-capability workflow proofs.
 *
 * The OUTER agent's text turn is what runs the application tool loop, so it must
 * go over the real text gateway. This builder is what {@see \Illuminate\Support\Facades\Http::fake()}
 * returns for that turn: request 1 emits a native `function_call` naming the
 * application tool, and request 2 (after the tool result is fed back as
 * `function_call_output`) emits the final assistant message. The nested native
 * modality (embeddings, reranking, …) is faked separately at its own gateway
 * via `Embeddings::fake()` etc. — it does not travel this wire.
 */
class NativeCapabilityWire
{
    /**
     * Request-1 wire: instruct the model to call one application tool.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function toolCall(string $tool, array $arguments, string $callId = 'call'): array
    {
        return self::response([[
            'type' => 'function_call',
            'id' => 'item-'.$callId,
            'call_id' => $callId,
            'name' => $tool,
            'arguments' => (string) json_encode($arguments, JSON_THROW_ON_ERROR),
        ]]);
    }

    /**
     * Request-2 wire: the final assistant message that settles the turn.
     *
     * @return array<string, mixed>
     */
    public static function message(string $text = 'workflow-complete'): array
    {
        return self::response([[
            'type' => 'message',
            'id' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $output
     * @return array<string, mixed>
     */
    private static function response(array $output): array
    {
        return [
            'id' => 'response',
            'model' => 'gpt-4.1-mini',
            'status' => 'completed',
            'output' => $output,
            'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
        ];
    }
}
