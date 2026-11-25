<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\ResolvedCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputStart;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalToolInputStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ToolInputStreamSwarm;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * #649 (widened): laravel/ai v1.4.2 streams a tool call's input as the model writes
 * it (tool_input_start, tool_input_delta) and reports one that never completed
 * (tool_input_error). Swarm streams them live as swarm_tool_input_* through both
 * mapping chains (StreamEventMapper, and StaticHierarchicalStreamRunner's own),
 * persists them for replay, and no longer breadcrumbs the lifecycle starts it
 * deliberately skips. On laravel/ai before v1.4.2 the fixture stands in with the
 * same wire shape (WireToolInputEvent), so every case runs on both lanes.
 */
final class ToolInputCollectingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /** @return list<list<string>> */
    public function breadcrumbEventTypes(): array
    {
        return array_values(array_map(
            fn (array $record): array => $record['context']['event_types'],
            array_filter($this->records, fn (array $record): bool => str_contains($record['message'], 'unrecognized stream event type')),
        ));
    }
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ToolInputStreamEditor::$withUnknown = false;

    $this->logger = new ToolInputCollectingLogger;
    app()->instance(LoggerInterface::class, $this->logger);
});

afterEach(function () {
    ToolInputStreamEditor::$withUnknown = false;
});

/**
 * @param  list<SwarmStreamEvent>  $events
 * @return list<SwarmStreamEvent>
 */
function toolInputToolEvents(array $events): array
{
    return array_values(array_filter($events, fn (SwarmStreamEvent $event): bool => str_starts_with($event->type(), 'swarm_tool_')));
}

/**
 * @param  class-string  $swarm
 * @return list<SwarmStreamEvent>
 */
function toolInputStream(string $swarm): array
{
    return iterator_to_array($swarm::make()->stream('tool-input-task'), false);
}

dataset('tool input chains', [
    'sequential (StreamEventMapper)' => [ToolInputStreamSwarm::class, false],
    'static hierarchical (own chain)' => [StaticHierarchicalToolInputStreamSwarm::class, true],
]);

test('tool input streams live as swarm events, in order, through both chains', function (string $swarm, bool $tagsNode) {
    $events = toolInputToolEvents(toolInputStream($swarm));

    expect(array_map(fn (SwarmStreamEvent $event): string => $event->type(), $events))->toBe([
        'swarm_tool_input_start',
        'swarm_tool_input_delta',
        'swarm_tool_input_delta',
        'swarm_tool_call',
        'swarm_tool_result',
        'swarm_tool_input_start',
        'swarm_tool_input_delta',
        'swarm_tool_input_error',
    ]);

    [$start, $first, $second, , , $cutStart, $cutDelta, $error] = $events;

    expect($start)->toBeInstanceOf(SwarmToolInputStart::class)
        ->and($start->toolId)->toBe('tool-1')
        ->and($start->toolName)->toBe('lookup')
        ->and($start->id)->toBe('tool-input-start-1')
        ->and($first)->toBeInstanceOf(SwarmToolInputDelta::class)
        ->and($first->delta.$second->delta)->toBe('{"query":"'.ToolInputStreamEditor::ARGUMENT_SECRET.'"}')
        ->and($cutStart->toolName)->toBe('search')
        ->and($cutDelta->toolId)->toBe('tool-2')
        ->and($error)->toBeInstanceOf(SwarmToolInputError::class)
        ->and($error->toolId)->toBe('tool-2')
        ->and($error->toolName)->toBe('search')
        ->and($error->input)->toBe('{"q":"'.ToolInputStreamEditor::ARGUMENT_SECRET)
        ->and($error->error)->toBe(ToolInputStreamEditor::ERROR_SECRET)
        ->and($error->toArray()['payload_status'])->toBe('available');

    foreach ([$start, $first, $error] as $event) {
        expect($event->nodeId)->toBe($tagsNode ? 'editor_node' : null);
    }
})->with('tool input chains');

test('the lifecycle starts Swarm skips leave no breadcrumb, and a genuinely unknown event still does', function (string $swarm) {
    toolInputStream($swarm);

    expect($this->logger->breadcrumbEventTypes())->toBe([]);

    ToolInputStreamEditor::$withUnknown = true;
    toolInputStream($swarm);

    expect($this->logger->breadcrumbEventTypes())->toBe([[UnknownStreamEvent::class]]);
})->with('tool input chains');

test('persisted replay reproduces the live tool input events exactly', function (string $swarm) {
    $stream = $swarm::make()->stream('tool-input-task');
    $live = toolInputToolEvents(iterator_to_array($stream, false));

    $replayed = toolInputToolEvents(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId), false));

    expect(array_map(fn (SwarmStreamEvent $event): string => $event::class, $replayed))
        ->toBe(array_map(fn (SwarmStreamEvent $event): string => $event::class, $live))
        ->and(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $replayed))
        ->toBe(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $live));
})->with('tool input chains');

test('withheld capture keeps every tool input event but never its fragments, live and replayed', function (string $swarm, bool $tagsNode, CaptureDecision $decision, string $status, ?string $error) {
    app()->instance(CapturePolicy::class, new ResolvedCapturePolicy(CaptureDecision::Full, $decision));

    $stream = $swarm::make()->stream('tool-input-task');
    $live = toolInputToolEvents(iterator_to_array($stream, false));
    $replayed = toolInputToolEvents(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId), false));

    foreach (['live' => $live, 'replayed' => $replayed] as $events) {
        $inputs = array_values(array_filter($events, fn (SwarmStreamEvent $event): bool => str_starts_with($event->type(), 'swarm_tool_input_')));

        expect(array_map(fn (SwarmStreamEvent $event): string => $event->type(), $inputs))->toBe([
            'swarm_tool_input_start', 'swarm_tool_input_delta', 'swarm_tool_input_delta',
            'swarm_tool_input_start', 'swarm_tool_input_delta', 'swarm_tool_input_error',
        ]);

        foreach ($inputs as $event) {
            expect($event->toArray()['payload_status'])->toBe($status)
                ->and($event->toArray()['tool_id'])->toBeIn(['tool-1', 'tool-2']);
        }

        expect($inputs[1]->delta)->toBeNull()
            ->and($inputs[0]->toolName)->toBe('lookup')
            ->and($inputs[5]->toolName)->toBe('search')
            ->and($inputs[5]->input)->toBeNull()
            ->and($inputs[5]->error)->toBe($error);

        $encoded = json_encode(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $inputs));

        expect($encoded)->not->toContain(ToolInputStreamEditor::ARGUMENT_SECRET)
            ->and($encoded)->not->toContain(ToolInputStreamEditor::ERROR_SECRET);
    }
})->with('tool input chains')->with([
    'redact' => [CaptureDecision::Redact, 'redacted', '[redacted]'],
    'skip' => [CaptureDecision::Skip, 'omitted', null],
]);

test('the frozen snapshot records only the tool call that ran, not the cut-off one', function (string $swarm) {
    $stream = $swarm::make()->stream('tool-input-task');
    iterator_to_array($stream, false);

    $snapshot = app(SnapshotsMemory::class)->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull()
        ->and(array_column($snapshot->toolCalls, 'name'))->toBe(['lookup']);
})->with('tool input chains');
