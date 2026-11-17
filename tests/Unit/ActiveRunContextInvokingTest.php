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

it('keeps a straddling run\'s frame when the run beneath it exits', function () {
    // Two swarm runs can be live at once without nesting: an application
    // consuming two streams in an interleaved way enters S1's frame, then S2's,
    // and S1 can finish while S2 is still suspended mid-step -- frame live,
    // invocation already recorded, its tool not yet run. exit() used to
    // array_pop, which discarded S2's frame, so isInvoking() could not find S2
    // and a genuine swarm run's failing tool was silently recovered. Removing
    // the exiting run's OWN frame is what keeps that from happening.
    $straddled = new CapabilityAgent([]);

    invokingFrame('run-s1');
    invokingFrame('run-s2');
    ActiveRunContext::invoking($straddled);

    ActiveRunContext::exit('run-s1');

    expect(ActiveRunContext::isInvoking($straddled))->toBeTrue()
        ->and(ActiveRunContext::current()?->runId)->toBe('run-s2');
});

it('removes only the named frame when the same run is entered twice', function () {
    // Re-entry with the same run id (memory replay does this) pushes a second
    // frame; removing the topmost match keeps that LIFO behaviour intact.
    invokingFrame('run-a');
    invokingFrame('run-a');
    invokingFrame('run-b');

    ActiveRunContext::exit('run-a');

    expect(ActiveRunContext::current()?->runId)->toBe('run-b');

    ActiveRunContext::exit('run-b');

    expect(ActiveRunContext::current()?->runId)->toBe('run-a');
});

it('pops the top frame when exit is called without a run id', function () {
    // The parameterless form is retained for callers that genuinely own the top
    // frame; every production call site passes its own run id.
    invokingFrame('run-a');
    invokingFrame('run-b');

    ActiveRunContext::exit();

    expect(ActiveRunContext::current()?->runId)->toBe('run-a');
});

it('leaves the stack alone when the named run has no frame', function () {
    invokingFrame('run-a');

    ActiveRunContext::exit('run-gone');

    expect(ActiveRunContext::current()?->runId)->toBe('run-a');
});
