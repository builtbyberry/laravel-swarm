<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities;

use Illuminate\Support\Facades\Http;

/**
 * Controlled OpenAI Responses wire for the native-capability workflow proofs.
 *
 * The OUTER agent's text turn is what runs the application tool loop, so it must
 * go over the real text gateway. This builder is what {@see Http::fake()}
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
     * Request-1 wire: instruct the model to call SEVERAL application tools in a
     * single step, in the given order. The native loop runs every tool call in
     * a step before yielding any result, so this is what a proof about one tool
     * failing before its siblings needs.
     *
     * @param  list<string>  $tools
     * @return array<string, mixed>
     */
    public static function toolCalls(array $tools): array
    {
        $output = [];

        foreach (array_values($tools) as $index => $tool) {
            $output[] = [
                'type' => 'function_call',
                'id' => 'item-call'.$index,
                'call_id' => 'call'.$index,
                'name' => $tool,
                'arguments' => (string) json_encode(['value' => 'v'], JSON_THROW_ON_ERROR),
            ];
        }

        return self::response($output);
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
     * Re-emit one of the bodies above as a streamed SSE turn.
     *
     * The streamed path is where interleaved consumption matters: a stream's
     * tool calls run as the consumer iterates, so two streams advanced
     * round-robin have both runs live at once. Same output, event-by-event.
     *
     * Covers function calls and output text only. It does NOT emit
     * `response.output_item.done`, which the real gateway uses for reasoning
     * items and provider-tool completion (see laravel/ai's
     * `Gateway/OpenAi/Concerns/HandlesTextGeneration.php`), so a test that
     * depends on either must build its own wire rather than reach for this.
     *
     * @param  array<string, mixed>  $body
     */
    public static function sse(array $body): string
    {
        /** @var list<array<string, mixed>> $output */
        $output = $body['output'];
        $events = [['type' => 'response.created', 'response' => $body]];

        foreach ($output as $index => $item) {
            $events[] = ['type' => 'response.output_item.added', 'item' => $item, 'output_index' => $index];

            if ($item['type'] === 'function_call') {
                $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => $item['id'], 'arguments' => $item['arguments']];

                continue;
            }

            /** @var list<array{text: string}> $content */
            $content = $item['content'];
            $events[] = ['type' => 'response.output_text.delta', 'item_id' => $item['id'], 'delta' => $content[0]['text']];
            $events[] = ['type' => 'response.output_text.done', 'item_id' => $item['id'], 'text' => $content[0]['text']];
        }

        $events[] = ['type' => 'response.completed', 'response' => $body];

        return implode('', array_map(
            fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ));
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
