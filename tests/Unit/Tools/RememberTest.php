<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryToolAgent;
use BuiltByBerry\LaravelSwarm\Tools\Remember;

test('its default description only advertises agent scope when bound', function () {
    $unbound = (string) (new Remember)->description();
    $boundWithKeyOff = (string) (new Remember)->forAgent(new MemoryToolAgent)->description();

    config()->set('swarm.memory.tools.agent_scope', true);
    $bound = (string) (new Remember)->forAgent(new MemoryToolAgent)->description();

    expect($unbound)->not->toContain('"agent"')
        ->and($boundWithKeyOff)->toBe($unbound)
        ->and($bound)->toContain('Use "agent" to keep a value for this agent across runs')
        ->and($bound)->toContain('propagation policy includes the agent scope');
});

test('a custom Remember description overrides bound defaults', function () {
    $tool = (new Remember('Custom remember instructions.'))->forAgent(new MemoryToolAgent);

    expect((string) $tool->description())->toBe('Custom remember instructions.');
});
