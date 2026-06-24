<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Support\SafeReporting;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default SinkFailureHandler that maps the swarm.audit.failure_policy config
 * value to a SinkFailureDecision.
 *
 * Recognized policies:
 *   'swallow'     — return SinkFailureDecision::Swallow, no logging.
 *   'log'         — log via the application logger, then Swallow.
 *   'queue'       — log, then return Queue. The dispatcher persists the failed
 *                   record to the audit outbox for later replay via
 *                   swarm:relay --type=audit. Default since v0.5.
 *   'dead_letter' — log, then return DeadLetter. The dispatcher persists the
 *                   failed record directly to the dead-letter status (no retry).
 *   'halt'        — log, then return Halt. The dispatcher throws
 *                   AuditSinkHaltedException, which carries the
 *                   HaltsSwarmExecution marker.
 *
 * Unknown policy values fall back to Swallow with a one-time warning logged,
 * matching the conservative posture of the v0.3 dispatcher.
 *
 * RetryInline is never returned by the default handler — it's reserved for
 * custom implementations that want transient-failure retry semantics.
 *
 * @internal
 */
class ConfiguredSinkFailureHandler implements SinkFailureHandler
{
    use SafeReporting;

    public function __construct(
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
        protected SwarmCapture $capture,
    ) {}

    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision {
        $policy = (string) $this->config->get('swarm.audit.failure_policy', 'queue');

        return match ($policy) {
            'swallow' => SinkFailureDecision::Swallow,
            'log' => $this->logAndSwallow($category, $exception),
            'queue' => $this->logAndQueue($category, $exception),
            'dead_letter' => $this->logAndDeadLetter($category, $exception),
            'halt' => $this->logAndHalt($category, $exception),
            default => $this->logUnknownPolicyAndSwallow($policy, $category, $exception),
        };
    }

    protected function logAndSwallow(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->safeLog($this->logger, 'error', 'Swarm audit sink failed.', [
            'category' => $category,
            'exception' => $this->capture->auditExceptionMessage($exception),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Swallow;
    }

    protected function logAndHalt(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->safeLog($this->logger, 'error', 'Swarm audit sink failed; halting run per swarm.audit.failure_policy=halt.', [
            'category' => $category,
            'exception' => $this->capture->auditExceptionMessage($exception),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Halt;
    }

    protected function logAndQueue(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->safeLog($this->logger, 'warning', 'Swarm audit sink failed; queuing for retry via swarm:relay --type=audit.', [
            'category' => $category,
            'exception' => $this->capture->auditExceptionMessage($exception),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Queue;
    }

    protected function logAndDeadLetter(string $category, Throwable $exception): SinkFailureDecision
    {
        $this->safeLog($this->logger, 'error', 'Swarm audit sink failed; routing to dead-letter per swarm.audit.failure_policy=dead_letter.', [
            'category' => $category,
            'exception' => $this->capture->auditExceptionMessage($exception),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::DeadLetter;
    }

    protected function logUnknownPolicyAndSwallow(string $policy, string $category, Throwable $exception): SinkFailureDecision
    {
        $this->safeLog($this->logger, 'warning', 'Unknown swarm.audit.failure_policy value; defaulting to swallow.', [
            'configured_policy' => $policy,
            'category' => $category,
            'exception' => $this->capture->auditExceptionMessage($exception),
            'class' => $exception::class,
        ]);

        return SinkFailureDecision::Swallow;
    }
}
