<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Support;

use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemorySnapshotRecorder;
use BuiltByBerry\LaravelSwarm\Persistence\Concerns\InteractsWithJsonColumns;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Site-scoped degrade-safe handling for tool-result payloads on their way into
 * a JSON column.
 *
 * Swarm's tool model is pure passthrough: a `ToolCall`/`ToolResult` (including
 * the MCP-backed tools surfaced by `laravel/ai` 0.8) is carried as an opaque
 * object through capture, snapshot, and streaming. A tool result is typed
 * `mixed`, so a structured MCP result can be any JSON-shaped value — and, at
 * the edges, something JSON cannot represent (invalid UTF-8 from a binary-ish
 * tool, recursion, a resource-like value).
 *
 * The shared {@see InteractsWithJsonColumns}
 * encoder is deliberately strict (`JSON_THROW_ON_ERROR`) because nine
 * audit/durable/resume/evidence stores depend on it failing loud on
 * corruption; loosening it would turn a silent placeholder substitution into a
 * non-repudiation / resume-integrity defect. That encoder STAYS strict.
 *
 * Instead, the two tool-result boundaries degrade here, at the tool-result
 * site only:
 *
 *  - the memory snapshot's `tool_calls` column
 *    ({@see DatabaseMemorySnapshotRecorder}), and
 *  - the streamed `swarm_tool_result` event payload
 *    ({@see SwarmToolResult::toArray()}),
 *    which the strict {@see DatabaseStreamEventStore}
 *    then encodes unchanged.
 *
 * A tool result that cannot be encoded is replaced by a fixed, pure-scalar
 * placeholder. The placeholder never echoes the original payload — echoing it
 * could itself re-throw on encode, and would leak the unencodable tool output
 * into a forensic row — and the breadcrumb is class-only, mirroring the #257
 * and v0.12.3 degrade-safe discipline ({@see SafeReporting}). The run never
 * crashes on a single bad tool result, and the audit/durable encoder it shares
 * stays strict.
 *
 * @internal
 */
final class ToolResultEncoding
{
    use SafeReporting;

    /**
     * Marker key on a substituted placeholder. A reader can detect that the
     * original tool result was dropped at persist time because it could not be
     * encoded. The result was never valid JSON to begin with, so this is not a
     * tamper signal — just a faithful record that the payload was
     * unrepresentable.
     */
    public const UNENCODABLE_MARKER = '__swarm_unencodable_tool_result__';

    /**
     * Return `$result` unchanged when it can be JSON-encoded, or a fixed
     * pure-scalar placeholder when it cannot — leaving a class-only breadcrumb.
     *
     * The placeholder shape is deliberately fixed and scalar so encoding it can
     * never re-throw, and it intentionally does not echo the original payload.
     *
     * The breadcrumb is routed through the never-throw {@see SafeReporting}
     * helpers: when a PSR logger is available (the recorder path) a class-only
     * `warning` is emitted; otherwise (the value-object stream boundary) the
     * `error_log` last resort carries the class-only note. Either way the
     * breadcrumb cannot become a second failure surface.
     */
    public static function degradeToolResult(mixed $result, ?string $toolName, ?LoggerInterface $logger = null): mixed
    {
        $encoding = new self;

        return $encoding->degrade($result, $toolName, $logger);
    }

    /**
     * Return a copy of a tool-call entry list in which any entry whose `result`
     * cannot be JSON-encoded has had that `result` replaced by the placeholder.
     * Every other field (`id`, `name`, `arguments`, `result_id`) and every
     * encodable entry passes through byte-identical.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    public static function degradeToolCalls(array $toolCalls, ?LoggerInterface $logger = null): array
    {
        $encoding = new self;

        foreach ($toolCalls as $index => $entry) {
            if (! array_key_exists('result', $entry)) {
                continue;
            }

            $toolCalls[$index]['result'] = $encoding->degrade(
                $entry['result'],
                is_string($entry['name'] ?? null) ? $entry['name'] : null,
                $logger,
            );
        }

        return $toolCalls;
    }

    private function degrade(mixed $result, ?string $toolName, ?LoggerInterface $logger): mixed
    {
        try {
            json_encode($result, JSON_THROW_ON_ERROR);

            return $result;
        } catch (JsonException $exception) {
            $this->breadcrumb($exception, $toolName, $logger);

            return [
                self::UNENCODABLE_MARKER => true,
                'tool' => $toolName,
            ];
        }
    }

    private function breadcrumb(Throwable $exception, ?string $toolName, ?LoggerInterface $logger): void
    {
        $message = 'laravel-swarm: a tool result could not be JSON-encoded for persistence; substituting a typed placeholder';

        if ($logger !== null) {
            $this->safeLog($logger, 'warning', $message, [
                'tool' => $toolName,
                'exception' => $exception::class,
            ]);

            return;
        }

        // No logger at this boundary (the streamed value object's toArray()):
        // emit a never-throw, class-only breadcrumb to the same ungoverned
        // error_log sink SafeReporting uses as its last resort. The tool *name*
        // is operator-supplied identification, not payload contents, so it is
        // safe to include; the unencodable result bytes are never echoed.
        try {
            error_log(
                '[laravel-swarm] '.$message
                .' (tool: '.($toolName ?? 'unknown').', exception: '.$exception::class.')',
            );
        } catch (Throwable) {
            // error_log() itself failed; there is nothing safe left to do.
        }
    }
}
