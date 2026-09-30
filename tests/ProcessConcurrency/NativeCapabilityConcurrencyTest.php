<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\EmbedTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

pest()->group('process-concurrency');

/**
 * Free-function factory: keeps Pest's generated test-case class out of the
 * serialized closure scope so Laravel's child process can resolve it. The child
 * re-bootstraps the provider AND re-arms the native modality fake + the agent
 * wire, because Http::fake()/Embeddings::fake() are process-local static state
 * that does not cross the fork.
 *
 * @return Closure(): array{tenant: string, effect: array<string, int>|null}
 */
function nativeCapabilityTenantWorker(string $tenant, int $dimensions, int $tokens, string $appKey): Closure
{
    return static function () use ($tenant, $dimensions, $tokens, $appKey): array {
        // Core Laravel config the child needs before anything resolves.
        config()->set('app.key', $appKey);
        config()->set('cache.default', 'array');
        config()->set('queue.default', 'sync');

        // Register providers so the FULL ai/swarm package config merges first.
        foreach ([BusServiceProvider::class, AiServiceProvider::class, SwarmServiceProvider::class] as $provider) {
            if (! app()->providerIsLoaded($provider)) {
                app()->register($provider);
            }
        }

        // Only NOW override individual keys — setting ai.providers.* before the
        // merge would leave a partial provider config with no 'driver'.
        config()->set('ai.providers.openai.key', 'test-key');
        config()->set('ai.conversations.generate_title', false);
        config()->set('swarm.persistence.driver', 'cache');
        config()->set('swarm.history.driver', 'cache');

        Embeddings::fake([
            new EmbeddingsResponse(
                [array_fill(0, $dimensions, 0.5)],
                new Usage($tokens, 0),
                new Meta('openai', 'text-embedding-3-small'),
            ),
        ]);

        $turns = 0;
        Http::fake(function (Request $request) use (&$turns) {
            $turns++;

            return Http::response($turns === 1
                ? NativeCapabilityWire::toolCall('EmbedTool', ['text' => 'retrieve'])
                : NativeCapabilityWire::message());
        });

        EmbedTool::$effects = [];
        app(SwarmRunner::class)->agent(new CapabilityAgent([new EmbedTool]))->prompt('task');

        return ['tenant' => $tenant, 'effect' => EmbedTool::$effects[0] ?? null];
    };
}

test('native capability workflows run in real background processes with independent per-branch results', function () {
    $appKey = (string) config('app.key');

    $results = app(ConcurrencyManager::class)->driver('process')->run([
        nativeCapabilityTenantWorker('alpha', 3, 7, $appKey),
        nativeCapabilityTenantWorker('bravo', 5, 11, $appKey),
    ]);

    // Each forked worker bootstraps the swarm, re-arms its own fakes, and produces
    // its OWN typed modality result — process isolation means neither the faked
    // vector nor the tool's static effect state can cross between branches.
    expect($results)->toBe([
        ['tenant' => 'alpha', 'effect' => ['dimensions' => 3, 'input_tokens' => 7]],
        ['tenant' => 'bravo', 'effect' => ['dimensions' => 5, 'input_tokens' => 11]],
    ]);
});
