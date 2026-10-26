<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Converts a blocking agent response into the step output Swarm records.
 *
 * A `StructuredAgentResponse` stringifies to `json_encode($structured)`. When the
 * structured payload is empty (`[]`), that is `"[]"` and the model's text is
 * lost. laravel/ai 1.0 already returned that shape when a provider's structured
 * decode failed; laravel/ai 1.3 also returns it for every schema agent whose
 * generation produced no structured payload at all, where 1.0 returned a plain
 * `AgentResponse` carrying the text. In both cases the text is used instead, so a
 * hierarchical coordinator reports invalid JSON rather than a missing `start_at`,
 * and history keeps what the model said.
 *
 * A populated structured payload, a plain response, and an empty payload with no
 * text all stringify exactly as before.
 *
 * @internal
 */
final class AgentResponseOutput
{
    public static function text(AgentResponse $response): string
    {
        if ($response instanceof StructuredAgentResponse && $response->structured === [] && $response->text !== '') {
            return $response->text;
        }

        return (string) $response;
    }
}
