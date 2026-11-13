<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelProcessStreamTransport;
use Illuminate\Process\Factory;
use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\PendingProcess;

test('each parallel process receives a private result key outside its serialized task', function (): void {
    $environments = [];
    $factory = new Factory;
    $factory->fake(function (PendingProcess $process) use (&$environments): FakeProcessDescription {
        $environments[] = $process->environment;

        return new FakeProcessDescription;
    });
    $session = (new ParallelProcessStreamTransport($factory))->start([
        'first' => static fn (): array => [],
        'second' => static fn (): array => [],
    ], (float) hrtime(true) + 2_000_000_000, 65_536, 10);

    // The synthetic children never connect. Consuming closes the session's socket.
    expect(fn () => iterator_to_array($session->events()))
        ->toThrow(SwarmException::class, 'exited before an authenticated terminal outcome');
    expect($environments)->toHaveCount(2);
    $keys = [];
    foreach ($environments as $environment) {
        $key = $environment['LARAVEL_CONCURRENCY_HMAC_KEY'] ?? '';
        expect($key)->toMatch('/^[a-f0-9]{64}$/')
            ->and(base64_decode($environment['LARAVEL_INVOKABLE_CLOSURE']))->not->toContain($key);
        $keys[] = $key;
    }
    expect($keys[0])->not->toBe($keys[1]);
});
