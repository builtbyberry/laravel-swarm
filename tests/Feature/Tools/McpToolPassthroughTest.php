<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Support\ToolResultEncoding;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\McpStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnencodableMcpStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeMcpStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeUnencodableMcpStreamingSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * laravel/ai 0.8 adds MCP client/server tool support. Swarm's tool model is
 * pure passthrough — `ToolCall`/`ToolResult` are carried as opaque objects — so
 * an MCP-backed tool's call/result must flow through tool-call capture, the
 * SnapshotToolCallNormalizer, and the streamed `SwarmToolCall`/`SwarmToolResult`
 * events with no MCP-specific code.
 *
 * These tests prove that end to end with a STRUCTURED (non-scalar) MCP result,
 * and prove the degrade-safe boundary: a tool result JSON cannot represent
 * never throws a `JsonException` up through a runner — it degrades to a typed
 * placeholder at the tool-result site while the run completes.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

// ---------------------------------------------------------------------------
// (a) Structured MCP tool result round-trips through every boundary
// ---------------------------------------------------------------------------

test('a structured MCP tool result flows through capture and the streamed events', function () {
    $events = iterator_to_array(FakeMcpStreamingSwarm::make()->stream('mcp-task'));

    $toolCall = collect($events)->whereInstanceOf(SwarmToolCall::class)->first();
    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();

    expect($toolCall)->not->toBeNull();
    expect($toolCall->toolCall->name)->toBe('docs.search');

    expect($toolResult)->not->toBeNull();
    expect($toolResult->successful)->toBeTrue();
    // The structured (non-scalar) MCP payload passes through opaque and intact.
    expect($toolResult->toolResult->result)->toBe(McpStreamEditor::STRUCTURED_RESULT);
});

test('the snapshot row persists a structured MCP tool result and decodes it intact', function () {
    $stream = FakeMcpStreamingSwarm::make()->stream('mcp-snapshot-task');
    iterator_to_array($stream);

    // The default database-backed recorder is active (driver=database). Reading
    // back through find() exercises the strict decodeJson read path, so a clean
    // decode proves the structured payload round-tripped encode -> decode.
    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);
    $snapshot = $recorder->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->toolCalls)->toHaveCount(1);
    expect($snapshot->toolCalls[0]['name'])->toBe('docs.search');
    expect($snapshot->toolCalls[0]['result'])->toBe(McpStreamEditor::STRUCTURED_RESULT);
});

test('a persisted MCP stream replays the structured tool result byte-identically', function () {
    $original = FakeMcpStreamingSwarm::make()
        ->stream('mcp-replay-task')
        ->storeForReplay();

    $originalEvents = iterator_to_array($original);

    // Reading events back out of the strict stream-event store exercises the
    // encodeJson -> decodeJson round trip the gate requires.
    $stored = iterator_to_array(app(StreamEventStore::class)->events($original->runId));
    $storedResult = collect($stored)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($storedResult->toolResult->result)->toBe(McpStreamEditor::STRUCTURED_RESULT);

    $replayEvents = iterator_to_array(app(SwarmHistory::class)->replay($original->runId));

    $serialize = fn (array $events): array => array_map(
        fn ($event): string => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
        $events,
    );

    expect($serialize($replayEvents))->toBe($serialize($originalEvents));
});

// ---------------------------------------------------------------------------
// (b) Unencodable MCP tool result degrades safely — never crashes the run
// ---------------------------------------------------------------------------

test('an unencodable MCP tool result degrades to a typed placeholder without crashing the run', function () {
    Log::spy();

    // The whole run must complete: no JsonException escapes the runner.
    $stream = FakeUnencodableMcpStreamingSwarm::make()
        ->stream('mcp-unencodable-task')
        ->storeForReplay();

    $events = iterator_to_array($stream);

    // The run produced its tool result event (the stream did not abort).
    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($toolResult)->not->toBeNull();
    expect($toolResult->successful)->toBeTrue();

    // The persisted snapshot row carries the typed placeholder, not the raw,
    // unencodable payload — and reads back through the strict decodeJson path.
    /** @var SnapshotsMemory $recorder */
    $recorder = app(SnapshotsMemory::class);
    $snapshot = $recorder->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->toolCalls)->toHaveCount(1);
    expect($snapshot->toolCalls[0]['result'])->toBe([
        ToolResultEncoding::UNENCODABLE_MARKER => true,
        'tool' => UnencodableMcpStreamEditor::TOOL_NAME,
    ]);

    // The streamed event store persisted the placeholder at the tool-result
    // boundary too, so the strict store encode never saw the bad payload.
    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));
    $storedResult = collect($stored)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($storedResult->toolResult->result)->toBe([
        ToolResultEncoding::UNENCODABLE_MARKER => true,
        'tool' => UnencodableMcpStreamEditor::TOOL_NAME,
    ]);

    // The breadcrumb is class-only: it names the tool but never leaks the
    // unencodable payload bytes.
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'could not be JSON-encoded')
            && ($context['tool'] ?? null) === UnencodableMcpStreamEditor::TOOL_NAME
            && ($context['exception'] ?? null) === JsonException::class;
    })->atLeast()->once();
});

test('the placeholder is a fixed pure-scalar shape that encodes without throwing', function () {
    $placeholder = ToolResultEncoding::degradeToolResult(
        UnencodableMcpStreamEditor::unencodableResult(),
        UnencodableMcpStreamEditor::TOOL_NAME,
    );

    expect($placeholder)->toBe([
        ToolResultEncoding::UNENCODABLE_MARKER => true,
        'tool' => UnencodableMcpStreamEditor::TOOL_NAME,
    ]);

    // Encoding the placeholder must never re-throw — that is the whole point.
    expect(json_encode($placeholder, JSON_THROW_ON_ERROR))->toBeString();

    // An encodable structured result passes through unchanged.
    expect(ToolResultEncoding::degradeToolResult(McpStreamEditor::STRUCTURED_RESULT, 'docs.search'))
        ->toBe(McpStreamEditor::STRUCTURED_RESULT);
});
