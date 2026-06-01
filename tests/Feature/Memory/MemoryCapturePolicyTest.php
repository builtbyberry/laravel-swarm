<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Tests\Support\RedactingMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Event;

/**
 * The memory capture policy decides, at the write boundary, whether each entry
 * is persisted as-is, redacted, or dropped. Redaction is enforced by the bound
 * RedactingMemoryStore decorator, so it governs every write — and because the
 * propagation view and frozen snapshots read back through the same store, PII
 * redacted at write never reaches a snapshot. The default policy is a no-op,
 * preserving pre-v0.10 behaviour.
 */
beforeEach(function () {
    /** @var Factory $cacheFactory */
    $cacheFactory = $this->app->make('cache');
    $cacheFactory->store('array')->flush();
});

/**
 * Bind a capture policy and flush the memory chain so the decorator and facade
 * re-resolve around it.
 */
function bindMemoryCapture(MemoryCapturePolicy $policy): void
{
    app()->instance(MemoryCapturePolicy::class, $policy);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);
}

/**
 * Flatten every MemoryEntry the runners handed to the snapshot recorder.
 *
 * @return array<int, MemoryEntry>
 */
function capturedSnapshotEntries(RecordingSnapshotsMemory $recorder): array
{
    $entries = [];

    foreach ($recorder->snapshotCalls as $call) {
        foreach ($call['entries'] ?? [] as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * True when every scalar leaf of the value is the redaction sentinel.
 */
function isFullyRedacted(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (! isFullyRedacted($item)) {
                return false;
            }
        }

        return true;
    }

    return $value === SwarmCapture::REDACTED;
}

test('the default policy writes every entry as-is, preserving pre-v0.10 behaviour', function () {
    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);

    $memory->put(MemoryScope::Run, 'run-1', 'note', ['ssn' => '123-45-6789']);
    $memory->put(MemoryScope::Agent, FakeWriter::class, 'pref', 'verbose');

    expect($memory->get(MemoryScope::Run, 'run-1', 'note'))->toBe(['ssn' => '123-45-6789']);
    expect($memory->get(MemoryScope::Agent, FakeWriter::class, 'pref'))->toBe('verbose');
});

test('a Redact decision structurally redacts the value at write, preserving keys and shape', function () {
    bindMemoryCapture(new RedactingMemoryCapturePolicy(['pii']));

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);

    $memory->put(MemoryScope::Run, 'run-1', 'pii', [
        'ssn' => '123-45-6789',
        'contact' => ['email' => 'a@b.com', 'phone' => '555-1234'],
    ]);
    $memory->put(MemoryScope::Run, 'run-1', 'safe', 'keep-me');

    // Keys and structure preserved; every scalar replaced by the sentinel.
    expect($memory->get(MemoryScope::Run, 'run-1', 'pii'))->toBe([
        'ssn' => SwarmCapture::REDACTED,
        'contact' => ['email' => SwarmCapture::REDACTED, 'phone' => SwarmCapture::REDACTED],
    ]);
    // Untargeted keys are untouched.
    expect($memory->get(MemoryScope::Run, 'run-1', 'safe'))->toBe('keep-me');
});

test('a Skip decision drops the entry entirely and dispatches no MemoryWritten event', function () {
    Event::fake([MemoryWritten::class]);

    bindMemoryCapture(new SkippingMemoryCapturePolicy(['secret']));

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);

    $memory->put(MemoryScope::Run, 'run-1', 'secret', 'top-secret');
    $memory->put(MemoryScope::Run, 'run-1', 'kept', 'value');

    expect($memory->get(MemoryScope::Run, 'run-1', 'secret'))->toBeNull();
    $keys = array_map(static fn (MemoryEntry $e): string => $e->key, $memory->all(MemoryScope::Run, 'run-1'));
    expect($keys)->toBe(['kept']);

    Event::assertDispatched(MemoryWritten::class, fn (MemoryWritten $e): bool => $e->key === 'kept');
    Event::assertNotDispatched(MemoryWritten::class, fn (MemoryWritten $e): bool => $e->key === 'secret');
});

test('a Redact decision still dispatches MemoryWritten with bytes reflecting the redacted payload', function () {
    Event::fake([MemoryWritten::class]);

    bindMemoryCapture(new RedactingMemoryCapturePolicy(['pii']));

    $raw = 'super-secret-value-that-is-quite-long';

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'pii', $raw);

    Event::assertDispatched(
        MemoryWritten::class,
        fn (MemoryWritten $e): bool => $e->key === 'pii'
            && $e->bytes !== null
            && $e->bytes < strlen($raw),
    );
});

test('redaction reaches the frozen snapshot — PII never bypasses the policy on the read path', function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $recorder);

    // Redact every write; the runners write Run-scoped `last_output` between
    // steps, so the agent-visible view the snapshot freezes must be redacted.
    bindMemoryCapture(new RedactingMemoryCapturePolicy);

    FakeSequentialSwarm::make()->run('a-task');

    $entries = capturedSnapshotEntries($recorder);

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect(isFullyRedacted($entry->value))->toBeTrue();
    }
});

test('the policy is consulted with scope and key but never the value', function () {
    $recorder = SwarmFake::interceptMemoryCapturePolicy();

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'profile', ['secret' => 'value']);

    $recorder->assertConsulted('profile', fn (array $record): bool => $record['scope'] === MemoryScope::Run);
    $recorder->assertDecision('profile', CaptureDecision::Full);
    // The signature carries no value parameter; the entry was still written.
    expect($memory->get(MemoryScope::Run, 'run-1', 'profile'))->toBe(['secret' => 'value']);
});

test('a config-bound capture policy applies to writes', function () {
    config()->set('swarm.memory.capture_policy', RedactingMemoryCapturePolicy::class);
    app()->forgetInstance(MemoryCapturePolicy::class);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'anything', 'sensitive');

    expect($memory->get(MemoryScope::Run, 'run-1', 'anything'))->toBe(SwarmCapture::REDACTED);
});

test('SwarmFake::interceptMemoryCapturePolicy records decisions and drives redaction', function () {
    $recorder = SwarmFake::interceptMemoryCapturePolicy(new RedactingMemoryCapturePolicy(['pii']));

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'pii', 'secret');
    $memory->put(MemoryScope::Run, 'run-1', 'safe', 'plain');

    $recorder->assertDecision('pii', CaptureDecision::Redact);
    $recorder->assertDecision('safe', CaptureDecision::Full);

    expect($memory->get(MemoryScope::Run, 'run-1', 'pii'))->toBe(SwarmCapture::REDACTED);
    expect($memory->get(MemoryScope::Run, 'run-1', 'safe'))->toBe('plain');
});

test('a capture policy class that does not implement the contract throws', function () {
    config()->set('swarm.memory.capture_policy', stdClass::class);
    app()->forgetInstance(MemoryCapturePolicy::class);
    app()->forgetInstance(MemoryStore::class);
    app()->forgetInstance(SwarmMemory::class);

    expect(fn () => $this->app->make(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'k', 'v'))
        ->toThrow(SwarmException::class, 'must implement');
});
