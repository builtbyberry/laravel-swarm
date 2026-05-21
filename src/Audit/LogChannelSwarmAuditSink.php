<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Log-channel-backed audit sink.
 *
 * Writes every audit evidence record as a structured log entry to the
 * configured Laravel log channel (defaults to `audit`, falling back to the
 * default channel if `audit` is not configured). Ideal for development and
 * staging — the operator gets human-readable evidence in the same place they
 * already tail their app logs, with zero extra infrastructure.
 *
 * Production deployments should ship a bounded backend (database, queue,
 * SIEM export, object storage with a manifest) so evidence can be queried,
 * signed, retained per policy, and replayed via `swarm:trace`. This sink does
 * not implement {@see ReadableSwarmAuditSink}
 * because log channels are not queryable; `swarm:trace` will degrade gracefully
 * to outbox + run-history rows when this sink is bound.
 *
 * Wire this sink via `php artisan swarm:install:audit --sink=readable` (which
 * scaffolds the binding into AppServiceProvider) or by hand:
 *
 *   $this->app->singleton(
 *       \BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink::class,
 *       \BuiltByBerry\LaravelSwarm\Audit\LogChannelSwarmAuditSink::class,
 *   );
 *
 * @see SwarmAuditSink
 */
final class LogChannelSwarmAuditSink implements SwarmAuditSink
{
    /**
     * @param  string  $channel  Log channel to write evidence to. Defaults to
     *                           `audit`; falls through to the default channel
     *                           if `audit` is not configured.
     */
    public function __construct(
        private readonly LogManager $logs,
        private readonly string $channel = 'audit',
    ) {}

    public function emit(string $category, array $payload): void
    {
        $this->resolveChannel()->info('swarm.audit.'.$category, $payload);
    }

    /**
     * Resolve the configured channel, falling back to the default channel if
     * the configured channel is not defined. Keeps the sink usable without
     * the operator having to set up an `audit` channel before installing.
     */
    private function resolveChannel(): LoggerInterface
    {
        $channels = (array) config('logging.channels', []);

        if ($this->channel !== '' && array_key_exists($this->channel, $channels)) {
            return $this->logs->channel($this->channel);
        }

        return $this->logs->channel();
    }
}
