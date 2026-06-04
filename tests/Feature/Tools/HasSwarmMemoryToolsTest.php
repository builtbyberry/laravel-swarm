<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryToolAgent;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;

/**
 * The HasSwarmMemoryTools concern exposes the Recall/Remember tools on an agent
 * only when `swarm.memory.tools.enabled` is true, and honours the per-tool
 * toggles — the "optional default-on registration via config" surface.
 */
function memoryToolClasses(): array
{
    return array_map(
        static fn (object $tool): string => $tool::class,
        [...(new MemoryToolAgent)->tools()],
    );
}

test('it exposes no tools when disabled (the default)', function () {
    config()->set('swarm.memory.tools.enabled', false);

    expect([...(new MemoryToolAgent)->tools()])->toBe([]);
});

test('it exposes both tools when enabled', function () {
    config()->set('swarm.memory.tools.enabled', true);

    expect(memoryToolClasses())->toBe([Recall::class, Remember::class]);
});

test('it honours the per-tool toggles', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.recall', true);
    config()->set('swarm.memory.tools.remember', false);

    expect(memoryToolClasses())->toBe([Recall::class]);
});

test('a bound subclass is resolved in place of the default tool', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.remember', false);

    app()->bind(Recall::class, fn () => new class extends Recall {});

    $tools = [...(new MemoryToolAgent)->tools()];

    expect($tools[0])->toBeInstanceOf(Recall::class);
    expect($tools[0]::class)->not->toBe(Recall::class);
});
