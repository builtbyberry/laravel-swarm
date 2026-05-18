<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Audit;

/**
 * The decision a CapturePolicy returns for each captured payload category.
 *
 * Full   — store the payload as-is.
 * Redact — store the payload structure with scalar values replaced by
 *          SwarmCapture::REDACTED. Today's default behavior when boolean
 *          capture flags are false.
 * Skip   — omit the payload from audit and history. In v0.4, SwarmCapture
 *          treats Skip identically to Redact for field-level emission;
 *          per-field omit lands in v0.5 alongside the audit dispatcher
 *          Skip-aware path. Custom policies may return Skip today to
 *          declare intent; the contract is locked.
 */
enum CaptureDecision
{
    case Full;
    case Redact;
    case Skip;
}
