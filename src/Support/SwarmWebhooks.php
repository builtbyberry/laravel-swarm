<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class SwarmWebhooks
{
    /**
     * @param  array<int, class-string<Swarm>>  $swarms
     */
    public static function routes(array $swarms): void
    {
        if (! (bool) config('swarm.durable.webhooks.enabled', false)) {
            return;
        }

        self::assertAuthConfiguration();

        $prefix = trim((string) config('swarm.durable.webhooks.prefix', 'swarm/webhooks'), '/');

        Route::post("{$prefix}/signal/{runId}/{signal}", function (Request $request, string $runId, string $signal) {
            self::authenticate($request);

            $result = app(DurableSwarmManager::class)->signal(
                $runId,
                $signal,
                $request->all(),
                $request->headers->get('Idempotency-Key'),
            );

            return response()->json([
                'run_id' => $result->runId,
                'signal' => $result->name,
                'accepted' => $result->accepted,
                'duplicate' => $result->duplicate,
                'status' => $result->status,
            ], $result->accepted ? 202 : 200);
        })->name('swarm.webhooks.signal');

        foreach ($swarms as $swarmClass) {
            if (! is_subclass_of($swarmClass, Swarm::class)) {
                throw new SwarmException("Swarm webhook route registration expects swarm classes; [{$swarmClass}] given.");
            }

            $slug = Str::kebab(class_basename($swarmClass));

            Route::post("{$prefix}/start/{$slug}", function (Request $request) use ($swarmClass) {
                self::authenticate($request);
                $idempotencyKey = $request->headers->get('Idempotency-Key');
                $scope = self::startIdempotencyScope($swarmClass);
                $requestHash = self::requestHash($request);

                if ($idempotencyKey !== null) {
                    $reservation = app(DurableRunStore::class)->reserveWebhookIdempotency($scope, $idempotencyKey, $requestHash);

                    if ($reservation['duplicate']) {
                        $payload = is_array($reservation['record']['response_payload'] ?? null) ? $reservation['record']['response_payload'] : ['run_id' => $reservation['record']['run_id'] ?? null];

                        return response()->json(array_merge($payload, ['duplicate' => true]), 200);
                    }

                    if ($reservation['conflict']) {
                        return response()->json(['message' => 'Idempotency key was already used with a different request payload.'], 409);
                    }

                    if ($reservation['in_flight']) {
                        return response()->json(['message' => 'Idempotency key is already processing.'], 409);
                    }
                }

                try {
                    $swarm = app(Application::class)->make($swarmClass);

                    if (! $swarm instanceof Swarm) {
                        throw new SwarmException("Unable to resolve webhook swarm [{$swarmClass}].");
                    }

                    $response = app(SwarmRunner::class)->dispatchDurable($swarm, $request->all());
                } catch (Throwable $exception) {
                    if ($idempotencyKey !== null) {
                        app(DurableRunStore::class)->failWebhookIdempotency($scope, $idempotencyKey);
                    }

                    throw $exception;
                }

                $payload = ['run_id' => $response->runId];

                if ($idempotencyKey !== null) {
                    app(DurableRunStore::class)->completeWebhookIdempotency($scope, $idempotencyKey, $response->runId, $payload);
                }

                return response()->json($payload, 202);
            })->name("swarm.webhooks.start.{$slug}");
        }
    }

    protected static function startIdempotencyScope(string $swarmClass): string
    {
        return 'start:'.$swarmClass;
    }

    protected static function requestHash(Request $request): string
    {
        return hash('sha256', $request->getContent());
    }

    protected static function assertAuthConfiguration(): void
    {
        $driver = (string) config('swarm.durable.webhooks.auth.driver', 'signed');

        if ($driver === 'none') {
            if (! app()->environment(['local', 'testing'])) {
                throw new SwarmException('Unauthenticated swarm webhooks are only allowed in local and testing environments.');
            }

            return;
        }

        if ($driver === 'signed' && blank(config('swarm.durable.webhooks.auth.secret'))) {
            throw new SwarmException('Signed swarm webhooks require [SWARM_WEBHOOK_SECRET].');
        }

        if ($driver === 'token' && blank(config('swarm.durable.webhooks.auth.token'))) {
            throw new SwarmException('Token swarm webhooks require [SWARM_WEBHOOK_TOKEN].');
        }
    }

    protected static function authenticate(Request $request): void
    {
        /** @var ConfigRepository $config */
        $config = app(ConfigRepository::class);
        $driver = (string) $config->get('swarm.durable.webhooks.auth.driver', 'signed');

        match ($driver) {
            'signed' => self::authenticateSignature($request, $config),
            'token' => self::authenticateToken($request, $config),
            'callback' => self::authenticateCallback($request, $config),
            'none' => app()->environment(['local', 'testing']) ? null : abort(401),
            default => throw new SwarmException("Unsupported swarm webhook auth driver [{$driver}]."),
        };
    }

    protected static function authenticateSignature(Request $request, ConfigRepository $config): void
    {
        $secret = (string) $config->get('swarm.durable.webhooks.auth.secret');
        $signatureHeader = (string) $config->get('swarm.durable.webhooks.auth.signature_header', 'X-Swarm-Signature');
        $timestampHeader = (string) $config->get('swarm.durable.webhooks.auth.timestamp_header', 'X-Swarm-Timestamp');
        $toleranceSeconds = (int) $config->get('swarm.durable.webhooks.auth.tolerance_seconds', 300);
        $timestamp = $request->headers->get($timestampHeader);
        $signature = $request->headers->get($signatureHeader);

        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            abort(401);
        }

        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            abort(401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401);
        }
    }

    protected static function authenticateToken(Request $request, ConfigRepository $config): void
    {
        $token = (string) $config->get('swarm.durable.webhooks.auth.token');
        $header = (string) $request->bearerToken();

        if ($header === '' || ! hash_equals($token, $header)) {
            abort(401);
        }
    }

    protected static function authenticateCallback(Request $request, ConfigRepository $config): void
    {
        $callback = $config->get('swarm.durable.webhooks.auth.callback');

        if (! is_callable($callback) || $callback($request) !== true) {
            abort(401);
        }
    }
}
