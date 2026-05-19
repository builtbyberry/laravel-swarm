<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

enum OutboxDispatchType: string
{
    case Step = 'step';
    case Branch = 'branch';
    case QueuedResume = 'queued_resume';
    case Audit = 'audit';

    /**
     * Whether this type drains the audit outbox (rather than the durable outbox).
     */
    public function isAudit(): bool
    {
        return $this === self::Audit;
    }
}
