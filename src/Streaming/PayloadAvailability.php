<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;

enum PayloadAvailability: string
{
    case Available = 'available';

    case Redacted = 'redacted';

    case Omitted = 'omitted';

    case Unknown = 'unknown';

    public static function fromCaptureDecision(CaptureDecision $decision): self
    {
        return match ($decision) {
            CaptureDecision::Full => self::Available,
            CaptureDecision::Redact => self::Redacted,
            CaptureDecision::Skip => self::Omitted,
        };
    }
}
