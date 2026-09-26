<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\NativeOnboardingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\NativeOnboardingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeOnboardingLookup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Console\Commands\MakeAgentCommand;
use Laravel\Ai\Responses\Data\ToolCall;

afterEach(function (): void {
    foreach (['P4GeneratedAgent.php', 'P4StructuredAgent.php'] as $file) {
        File::delete(app_path('Ai/Agents/'.$file));
    }

    NativeOnboardingLookup::reset();
});

test('model agent generation stays owned by the installed Laravel AI command', function (): void {
    $commands = app(Kernel::class)->all();

    expect($commands['make:agent'])->toBeInstanceOf(MakeAgentCommand::class);

    Artisan::call('make:agent', [
        'name' => 'P4GeneratedAgent',
        '--no-interaction' => true,
    ]);
    Artisan::call('make:agent', [
        'name' => 'P4StructuredAgent',
        '--structured' => true,
        '--no-interaction' => true,
    ]);

    expect(File::get(app_path('Ai/Agents/P4GeneratedAgent.php')))
        ->toContain('namespace App\Ai\Agents;')
        ->toContain('implements Agent, Conversational, HasTools')
        ->toContain('use Promptable;')
        ->not->toContain('ScriptedAgent')
        ->and(File::get(app_path('Ai/Agents/P4StructuredAgent.php')))
        ->toContain('implements Agent, Conversational, HasStructuredOutput, HasTools')
        ->toContain('public function schema(JsonSchema $schema): array');
});

test('a generated-style native agent runs tools and streams through a swarm without an external provider request', function (): void {
    Http::preventStrayRequests();
    NativeOnboardingLookup::reset();
    NativeOnboardingWriter::fake([
        new ToolCall(
            id: 'release-lookup-call',
            name: 'lookup_release_notes',
            arguments: ['release' => 'v0.28.0'],
            resultId: 'release-lookup-result',
        ),
        'Release notes are ready.',
    ])->preventStrayPrompts();

    $stream = NativeOnboardingSwarm::make()->stream('Draft the v0.28.0 release note.');
    $events = collect(iterator_to_array($stream));
    $types = $events->map(fn ($event): string => $event->type())->all();

    $toolCallIndex = array_search('swarm_tool_call', $types, true);
    $toolResultIndex = array_search('swarm_tool_result', $types, true);
    $textIndex = array_search('swarm_text_delta', $types, true);
    $streamEndIndex = array_search('swarm_stream_end', $types, true);
    $stepEnd = $events->whereInstanceOf(SwarmStepEnd::class)->sole();
    $toolResult = $events->whereInstanceOf(SwarmToolResult::class)->sole();

    expect($toolCallIndex)->toBeInt()
        ->and($toolResultIndex)->toBeInt()->toBeGreaterThan($toolCallIndex)
        ->and($textIndex)->toBeInt()->toBeGreaterThan($toolResultIndex)
        ->and($streamEndIndex)->toBeInt()->toBeGreaterThan($textIndex)
        ->and(NativeOnboardingLookup::$calls)->toBe(1)
        ->and(NativeOnboardingLookup::$arguments)->toBe(['release' => 'v0.28.0'])
        ->and($toolResult->toolResult->result)->toBe('v0.28.0 makes native Laravel AI agents the normal authoring path.')
        ->and($events->whereInstanceOf(SwarmTextDelta::class)->pluck('delta')->implode(''))->toBe('Release notes are ready.')
        ->and($events->last())->toBeInstanceOf(SwarmStreamEnd::class)
        ->and($stream->streamedResponse?->output)->toBe('Release notes are ready.')
        ->and($stepEnd->nativeResult?->status)->toBe(NativeStepResult::AVAILABLE)
        ->and($stepEnd->nativeResult?->tools)->toContain([
            'call_id' => 'release-lookup-call',
            'result_id' => 'release-lookup-result',
            'name' => 'lookup_release_notes',
            'status' => 'succeeded',
        ]);
});
