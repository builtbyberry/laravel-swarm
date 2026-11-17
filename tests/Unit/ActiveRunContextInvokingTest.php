<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;

covers(ActiveRunContext::class);

afterEach(fn () => ActiveRunContext::flush());

function invokingFrame(string $runId): void
{
    ActiveRunContext::enter($runId, 'TestSwarm', new RunContext($runId, 'task'));
}

it('recognises an agent Swarm is invoking in a frame below the top one', function () {
    // An application consuming two swarm streams in an interleaved way suspends
    // one run's frame under the other's, and the lower run's tool calls still
    // execute when its generator is resumed. Reading only the top frame would
    // silently recover a genuine swarm failure -- a worse error than the
    // application-side false positive the check exists to prevent.
    $lower = new CapabilityAgent([]);
    $upper = new CapabilityAgent([]);

    invokingFrame('run-a');
    ActiveRunContext::invoking($lower);

    invokingFrame('run-b');
    ActiveRunContext::invoking($upper);

    expect(ActiveRunContext::isInvoking($upper))->toBeTrue()
        ->and(ActiveRunContext::isInvoking($lower))->toBeTrue();
});

it('does not recognise an agent no frame is invoking', function () {
    $invoked = new CapabilityAgent([]);
    $other = new CapabilityAgent([]);

    invokingFrame('run-a');
    ActiveRunContext::invoking($invoked);

    // Same class, different instance: identity, not class.
    expect(ActiveRunContext::isInvoking($other))->toBeFalse()
        ->and(ActiveRunContext::isInvoking($invoked))->toBeTrue();
});

it('does not recognise anything while a frame names no invocation', function () {
    // A frame left behind by an abandoned run, or one entered before the
    // invocation starts, names no agent and must match nothing.
    invokingFrame('run-a');

    expect(ActiveRunContext::isInvoking(new CapabilityAgent([])))->toBeFalse();
});

it('matches nothing with no frame at all', function () {
    expect(ActiveRunContext::isInvoking(new CapabilityAgent([])))->toBeFalse();
});
