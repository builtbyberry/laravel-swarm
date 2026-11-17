<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure;

use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;

/**
 * Controlled OpenAI Responses wire for the native tool-failure policy proofs.
 *
 * {@see NativeCapabilityWire} emits a single `function_call` per turn, which
 * cannot express two tool calls in one step — the shape the sibling-tool guard
 * needs. This builder emits as many as it is given, so one step can call a
 * failing tool and a sibling, in that order.
 */
class NativeToolFailureWire
{
    /**
     * Request-1 wire: instruct the model to call several application tools in a
     * single step, in the given order.
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
     * Request-2 wire: the final assistant message that settles the turn, used on
     * the recover path where the run continues past the failed tool.
     *
     * @return array<string, mixed>
     */
    public static function message(string $text = 'workflow-complete'): array
    {
        return NativeCapabilityWire::message($text);
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
