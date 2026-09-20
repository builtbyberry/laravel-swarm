<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;

it('keeps the unknown event native and its privacy sentinel serializable', function () {
    $event = new UnknownStreamEvent;
    expect($event)->toBeInstanceOf(StreamEvent::class)
        ->and($event->toArray())->toBe([
            'type' => 'unknown.fixture', 'id' => 'unknown-1', 'secret' => 'super-secret-unredacted-payload',
        ])
        ->and(json_decode((string) $event, true, flags: JSON_THROW_ON_ERROR))->toBe($event->toArray());
});
