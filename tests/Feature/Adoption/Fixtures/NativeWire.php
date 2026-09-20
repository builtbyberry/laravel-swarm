<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures;

class NativeWire
{
    public static function response(string $text = 'native-answer', bool $tool = false, bool $stream = false): array|string
    {
        $item = $tool
            ? ['type' => 'function_call', 'id' => 'item', 'call_id' => 'call', 'name' => 'WorkflowTool', 'arguments' => '{"value":"effect-secret"}']
            : ['type' => 'message', 'id' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
        $response = ['id' => 'response', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$item], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
        if (! $stream) {
            return $response;
        }

        $events = [
            ['type' => 'response.created', 'response' => $response],
            ['type' => 'response.output_item.added', 'item' => $item, 'output_index' => 0],
        ];
        if ($tool) {
            $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => 'item', 'arguments' => $item['arguments']];
        } else {
            $events[] = ['type' => 'response.output_text.delta', 'item_id' => 'message', 'delta' => $text];
            $events[] = ['type' => 'response.output_text.done', 'item_id' => 'message', 'text' => $text];
        }
        $events[] = ['type' => 'response.completed', 'response' => $response];

        return implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
    }
}
