<?php

declare(strict_types=1);

// laravel/octane is not a dependency of this package, so we stand in a stub for
// its OperationTerminated contract under the real namespace. Defining it at file
// load time (before the testbench app boots in TestCase::setUp) means
// SwarmServiceProvider::registerOctaneStateReset() sees Octane as "installed"
// and wires the worker-reset listener, exactly as it would in a host app that
// has Octane. Bracketed namespaces are required because the stubs live under the
// Laravel\Octane namespace while the test body runs in the global namespace.

namespace Laravel\Octane\Contracts {
    if (! interface_exists(OperationTerminated::class)) {
        interface OperationTerminated {}
    }
}

namespace Laravel\Octane\Events {
    use Laravel\Octane\Contracts\OperationTerminated;

    if (! class_exists(RequestTerminated::class)) {
        class RequestTerminated implements OperationTerminated {}
    }
}

namespace {

    use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
    use BuiltByBerry\LaravelSwarm\Support\RunContext;
    use Illuminate\Contracts\Events\Dispatcher;
    use Laravel\Octane\Events\RequestTerminated;

    afterEach(function () {
        ActiveRunContext::flush();
    });

    test('a simulated Octane worker reset flushes stale ActiveRunContext frames', function () {
        // Leave a frame behind, as an abnormally-terminated run on a long-lived
        // worker would (the finally that pairs enter()/exit() was bypassed).
        ActiveRunContext::enter('stale-run', 'StaleSwarm', RunContext::fake(['run_id' => 'stale-run']));
        expect(ActiveRunContext::current())->not->toBeNull();

        // Octane fires an OperationTerminated event (RequestTerminated here) on
        // every worker reset; the provider-registered listener should clear the
        // stack.
        $this->app->make(Dispatcher::class)->dispatch(new RequestTerminated);

        expect(ActiveRunContext::current())->toBeNull();
    });

    test('the worker-reset listener is registered for the OperationTerminated contract', function () {
        expect($this->app->make(Dispatcher::class)->hasListeners('Laravel\Octane\Contracts\OperationTerminated'))
            ->toBeTrue();
    });
}
