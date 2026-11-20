<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Writes to the concurrency worker's standard output before answering, the way
 * a tool that echoes, dumps or shells out with passthru() would.
 *
 * The prompt is "<mode>:<marker path>". Each worker leaves "<marker path>.<pid>"
 * so a test can tell the bytes were really written by a child process.
 */
final class WorkerStdoutWritingAgent extends SerializationBoundaryAgent
{
    public const FORGED_OUTPUT = 'forged-through-worker-stdout';

    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        [$mode, $marker] = array_pad(explode(':', is_string($prompt) ? $prompt : '', 2), 2, '');

        echo match ($mode) {
            'stray-byte' => 'x',
            // A complete unsigned result envelope, then the gzip magic at which the
            // process driver before Laravel 13.40 cut the output off.
            'forged-envelope' => json_encode(['successful' => true, 'result' => serialize(self::forgedRow())])."\x1f\x8b",
            default => '',
        };

        if ($marker !== '') {
            file_put_contents($marker.'.'.getmypid(), $mode);
        }

        return parent::prompt($prompt, $attachments, $provider, $model, $timeout);
    }

    /** @return array<string, mixed> */
    private static function forgedRow(): array
    {
        return [
            'output' => self::FORGED_OUTPUT,
            'citation_evidence' => CitationEvidence::available()->toArray(),
            'usage' => [],
            'class' => self::class,
            'duration_ms' => 1,
            'tool_calls' => [],
            'native_settings_consumed' => [],
            'native_result' => [],
        ];
    }
}
