<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputDelta;
use BuiltByBerry\LaravelSwarm\Streaming\ToolInputEventMapper;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * #649: an upstream event's toArray() is foreign code, called once per event: a
 * second call that fails cannot turn an event that serialised into a thrown run.
 */
test('a tool-input event is serialised once, so a toArray() that fails on a second call still maps', function () {
    config()->set('swarm.streaming.tool_input.enabled', true);
    $event = new class extends StreamEvent
    {
        public int $calls = 0;

        public function toArray(): array
        {
            if (++$this->calls > 1) {
                throw new RuntimeException('serialised twice');
            }

            return ['id' => 'delta-1', 'type' => 'tool_input_delta', 'tool_id' => 'tool-1', 'delta' => '{"q":', 'timestamp' => 1];
        }
    };
    $capture = app(SwarmCapture::class);

    expect(ToolInputEventMapper::skips($event, $capture))->toBeFalse();

    $mapped = ToolInputEventMapper::map($event, $capture, RunContext::from('task'), 0, 'Agent');

    expect($mapped)->toBeInstanceOf(SwarmToolInputDelta::class)
        ->and($mapped->delta)->toBe('{"q":')
        ->and($event->calls)->toBe(1);
});
