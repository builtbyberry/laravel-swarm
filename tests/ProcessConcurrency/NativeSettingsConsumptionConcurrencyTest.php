<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ConsumesNativeInputMessages;
use BuiltByBerry\LaravelSwarm\Contracts\NativeInputStore;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Support\NativeInputManager;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\UserMessage;

pest()->group('process-concurrency', 'skip-locked-real-db');

function nativeSettingsConsumptionWorker(string $reference, string $runId, string $configurationId, string $appKey): Closure
{
    return static function () use ($reference, $runId, $configurationId, $appKey): string {
        config()->set('app.key', $appKey);
        config()->set('swarm.native_inputs.enabled', true);
        config()->set('swarm.native_agent_settings.enabled', true);
        config()->set('swarm.native_inputs.disk', 'local');
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', true);

        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }

        $store = app(NativeInputStore::class);
        if (! $store instanceof ConsumesNativeInputMessages) {
            throw new RuntimeException('Native input store cannot consume messages.');
        }

        $store->transaction(fn () => $store->consumeMessages($reference, $runId, [$configurationId]));

        return $configurationId;
    };
}

test('concurrent real-database one-shot consumption preserves every recipient checkpoint', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Native settings row locking requires the real MySQL/Postgres lane.');
    }

    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_agent_settings.enabled', true);
    config()->set('swarm.native_inputs.disk', 'local');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.history.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $context = RunContext::fromTask('real-db-consumption')->withAgentConfiguration([
        NativeInputRecipient::parallel(0)->withMessages([new UserMessage('first')]),
        NativeInputRecipient::parallel(1)->withMessages([new UserMessage('second')]),
    ]);
    app(NativeInputManager::class)->admit($context, Topology::Parallel, ExecutionMode::Queue);

    $reference = (string) $context->nativeInputReference();
    $runId = $context->runId;
    $appKey = (string) config('app.key');
    $callbacks = [];
    foreach (['recipient:parallel:0', 'recipient:parallel:1'] as $configurationId) {
        // The free-function factory keeps Pest's generated test-case class out of
        // the serialized closure scope so Laravel's child process can resolve it.
        $callbacks[] = nativeSettingsConsumptionWorker($reference, $runId, $configurationId, $appKey);
    }

    $results = app(ConcurrencyManager::class)->driver('process')->run($callbacks);
    $row = app(NativeInputStore::class)->find($reference);

    expect($results)->toBe(['recipient:parallel:0', 'recipient:parallel:1'])
        ->and($row['payload']['consumed_message_configuration_ids'])->toEqualCanonicalizing([
            'recipient:parallel:0',
            'recipient:parallel:1',
        ]);
});
