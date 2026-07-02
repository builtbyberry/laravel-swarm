<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Enums;

/**
 * @deprecated since v0.16.0. This enum conflated two distinct concepts — the
 * relay lane (durable vs audit) and the granular durable dispatch type — because
 * the v0.5 audit outbox reused it for `swarm:relay --type=audit` even though the
 * `Audit` case never reaches `DurableJobDispatcher`. Use {@see RelayLane} to
 * name the lane (`Durable`/`Audit`) and {@see DurableDispatchType} for the
 * durable dispatch kind (`Step`/`Branch`/`QueuedResume`). Retained, unchanged,
 * for backward compatibility; scheduled for removal in a future major release.
 */
enum OutboxDispatchType: string
{
    case Step = 'step';
    case Branch = 'branch';
    case QueuedResume = 'queued_resume';
    case Audit = 'audit';

    /**
     * Whether this type drains the audit outbox (rather than the durable outbox).
     *
     * @deprecated since v0.16.0. Compare against {@see RelayLane::Audit} instead.
     */
    public function isAudit(): bool
    {
        return $this === self::Audit;
    }

    /**
     * The relay lane this type belongs to.
     *
     * @deprecated since v0.16.0. Model the lane with {@see RelayLane} directly.
     */
    public function lane(): RelayLane
    {
        return $this === self::Audit ? RelayLane::Audit : RelayLane::Durable;
    }

    /**
     * The durable dispatch type this maps to, or null for the audit lane.
     *
     * @deprecated since v0.16.0. Use {@see DurableDispatchType} directly.
     */
    public function toDurableDispatchType(): ?DurableDispatchType
    {
        return DurableDispatchType::tryFrom($this->value);
    }
}
