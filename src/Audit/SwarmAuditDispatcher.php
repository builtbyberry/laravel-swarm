<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes normalized audit evidence to the bound SwarmAuditSink.
 *
 * Enriches every payload with schema_version, category, and occurred_at before
 * forwarding to the sink. Sink exceptions are isolated and handled according to
 * swarm.audit.failure_policy — they never propagate into swarm execution.
 *
 * Supported failure policies:
 *   swallow — silently discard the exception (default, safest for production).
 *   log     — record the exception via the application logger, then continue.
 */
class SwarmAuditDispatcher
{
    public const SCHEMA_VERSION = '1';

    public function __construct(
        protected SwarmAuditSink $sink,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Emit a single evidence record to the bound sink.
     *
     * @param  array<string, mixed>  $payload  Domain-specific correlation fields.
     *                                         schema_version, category, and occurred_at
     *                                         are merged automatically.
     */
    public function emit(string $category, array $payload): void
    {
        $enriched = array_merge($payload, [
            'schema_version' => self::SCHEMA_VERSION,
            'category' => $category,
            'occurred_at' => now()->toIso8601String(),
        ]);

        try {
            $this->sink->emit($category, $enriched);
        } catch (Throwable $exception) {
            $this->handleSinkFailure($category, $exception);
        }
    }

    protected function handleSinkFailure(string $category, Throwable $exception): void
    {
        $policy = (string) $this->config->get('swarm.audit.failure_policy', 'swallow');

        if ($policy === 'log') {
            $this->logger->error('Swarm audit sink failed.', [
                'category' => $category,
                'exception' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
        }

        // Never rethrow — sink failures must not affect swarm execution.
    }
}
