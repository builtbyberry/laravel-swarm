# Native Agent Onboarding

Laravel AI's `make:agent` command is the normal way to author model-backed
agents for a swarm. Laravel Swarm composes those classes directly; it does not
wrap or regenerate them.

## Generate the agent and swarm

```bash
php artisan make:agent ReleaseNoteWriter
php artisan make:swarm:swarm ReleaseNotesSwarm
```

Add your application-owned instructions and tool declarations to the generated
agent. For example:

```php
use App\Ai\Tools\ReleaseNoteLookup;

public function instructions(): string
{
    return 'Use the release lookup tool, then write one concise release note.';
}

public function tools(): iterable
{
    return [new ReleaseNoteLookup];
}
```

Then return the agent from the swarm:

```php
use App\Ai\Agents\ReleaseNoteWriter;

public function agents(): array
{
    return [new ReleaseNoteWriter];
}
```

The generated class already carries Laravel AI's native agent interfaces,
`Promptable` behavior, message hook, and tool hook. Keep those generator-owned
conventions and edit only your application behavior.

## Test tools and streaming without a paid provider

Laravel AI's agent fake drives the native tool loop and stream locally. This
test needs no API key and makes no external provider request:

```php
use App\Ai\Agents\ReleaseNoteWriter;
use App\Ai\Swarms\ReleaseNotesSwarm;
use Laravel\Ai\Responses\Data\ToolCall;

ReleaseNoteWriter::fake([
    new ToolCall(
        id: 'release-lookup-call',
        name: 'lookup_release_notes',
        arguments: ['release' => 'v0.28.0'],
        resultId: 'release-lookup-result',
    ),
    'Release notes are ready.',
])->preventStrayPrompts();

$stream = ReleaseNotesSwarm::make()->stream('Draft the v0.28.0 release note.');
$events = collect(iterator_to_array($stream));

expect($events->pluck('type'))
    ->toContain('swarm_tool_call', 'swarm_tool_result', 'swarm_text_delta', 'swarm_stream_end');
expect($stream->streamedResponse->output)->toBe('Release notes are ready.');
```

The package's executable counterpart is
[`NativeAgentOnboardingTest`](../tests/Feature/NativeAgentOnboardingTest.php).
It additionally asserts tool arguments, one execution, event order, the tool
result, and the native step-result status recorded by the Swarm stream.

## Structured output is a separate path

Generate a schema-backed agent with Laravel AI's supported option:

```bash
php artisan make:agent ContactExtractor --structured
```

Fill in the generated `schema()` method and invoke that agent through
`prompt()`, `queue()`, or a supported durable workflow. Laravel AI structured
responses do not stream, so do not use a structured-output agent as the final
streaming worker.

## Existing compatibility scaffolds

`make:swarm:agent` and `make:swarm --single` remain available. They retain their
existing namespaces, arguments, `ScriptedAgent` inheritance, and published-stub
precedence for deterministic offline helpers. Upgrading does not rewrite existing
agent classes or application-published `stubs/swarm.agent.stub` and
`stubs/swarm.single-agent.stub` files.

For a model-backed replacement, generate a new class with `make:agent`, then
port only the application-owned instructions, tools, and schema. Keep the old
offline helper until its callers and tests have moved; no automatic consumer-code
rewrite occurs.
