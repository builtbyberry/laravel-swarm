<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

/**
 * The decision a CapturePolicy returns for each captured payload category.
 *
 * Full   — store the payload as-is.
 * Redact — store the payload structure with scalar values replaced by
 *          SwarmCapture::REDACTED. The default behavior when boolean capture
 *          flags are false.
 * Skip   — omit the field entirely from the *evidence* surfaces. Since v0.12
 *          SwarmCapture honors Skip with true per-field omission: the field is
 *          absent from persisted/emitted arrays (run history, lifecycle/stream
 *          events, audit envelopes) and null on the evidence DB columns that
 *          allow it (run-history step input/output, top-level run output, the
 *          error `message`). The operational active-context store retains the
 *          true input because durable resume requires it. The default
 *          BooleanCapturePolicy never returns Skip, so apps using the
 *          swarm.capture.* booleans keep seeing REDACTED, not omission.
 *
 * Note that MemoryCapturePolicy (v0.10) interprets Skip the same way for memory
 * writes: a Skip on a memory write drops the entry entirely — no row is
 * persisted and no MemoryWritten event fires.
 */
enum CaptureDecision
{
    case Full;
    case Redact;
    case Skip;
}
