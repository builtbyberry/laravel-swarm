<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default SinkFailureHandler that maps the swarm.audit.failure_policy config
 * value to a SinkFailureDecision.
 *
 * Recognized policies:
 *   'swallow' (default) — return SinkFailureDecision::Swallow, no logging.
 *   'log'               — log via the application logger, then Swallow.
 *   'halt'              — log via the application logger, then Halt.
 *                         The dispatcher will throw AuditSinkHaltedException,
 *                         which carries the HaltsSwarmExecution marker.
 *
 * Unknown policy values fall back to Swallow with a one-time warning logged,
 * matching the conservative posture of the v0.3 dispatcher.
 *
 * RetryInline is never returned by the default handler — it's reserved for
 * custom implementations that want transient-failure retry semantics.
 */
class ConfiguredSinkFailureHandler implements SinkFailureHandler
{
    public function __construct(
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {}

    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision {
        $policy = (string) $this->config->get('swarm.audit.failure_policy', 'swallow');

        return match ($policy) {
            'swallow' => SinkFailureDecision::Swallow,
            'log' => $this->logAndSwallow($category, $exception),
            'halt' => $this->logAndHalt($category, $exception),
            default => $this->logUnknownPolicyAndSwallow($policy, $category, $exception),
        };
    }

    protected function logAndSwallow(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->logger->error('Swarm audit sink failed.', [
            'category' => $category,
            'exception' => $exception->getMessage(),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Swallow;
    }

    protected function logAndHalt(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->logger->error('Swarm audit sink failed; halting run per swarm.audit.failure_policy=halt.', [
            'category' => $category,
            'exception' => $exception->getMessage(),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Halt;
    }

    protected function logUnknownPolicyAndSwallow(string $policy, string $category, Throwable $exception): SinkFailureDecision
    {
        $this->logger->warning('Unknown swarm.audit.failure_policy value; defaulting to swallow.', [
            'configured_policy' => $policy,
            'category' => $category,
            'exception' => $exception->getMessage(),
        ]);

        return SinkFailureDecision::Swallow;
    }
}
