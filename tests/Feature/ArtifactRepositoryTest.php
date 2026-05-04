<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ArtifactInvalidPayloadValue
{
    public string $value = 'sensitive';
}

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

function artifactRepositories(): array
{
    return [
        'cache' => app(CacheArtifactRepository::class),
        'database' => app(DatabaseArtifactRepository::class),
    ];
}

function insertArtifactParentHistoryRow(string $runId): void
{
    $now = now('UTC');
    DB::table('swarm_run_histories')->insertOrIgnore([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'finished_at' => null,
        'expires_at' => $now->copy()->addHour(),
        'execution_token' => null,
        'leased_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('artifact repositories persist normalized array and swarm artifact payloads', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        $runId = "valid-artifacts-{$driver}";

        if ($driver === 'database') {
            insertArtifactParentHistoryRow($runId);
        }

        $repository->storeMany($runId, [
            [
                'name' => 'manual_payload',
                'content' => ['summary' => 'done'],
                'metadata' => ['source' => 'test'],
                'step_agent_class' => FakeEditor::class,
                'ignored' => 'value',
            ],
            [
                'name' => 'defaults_payload',
            ],
            new SwarmArtifact(
                name: 'swarm_artifact',
                content: 'output',
                metadata: ['index' => 2],
                stepAgentClass: FakeEditor::class,
            ),
        ], 60);

        expect($repository->all($runId))->toBe([
            [
                'name' => 'manual_payload',
                'content' => ['summary' => 'done'],
                'metadata' => ['source' => 'test'],
                'step_agent_class' => FakeEditor::class,
            ],
            [
                'name' => 'defaults_payload',
                'content' => null,
                'metadata' => [],
                'step_agent_class' => null,
            ],
            [
                'name' => 'swarm_artifact',
                'content' => 'output',
                'metadata' => ['index' => 2],
                'step_agent_class' => FakeEditor::class,
            ],
        ]);
    }
});

test('artifact repositories reject non array non swarm artifact payloads', function (mixed $artifact) {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-payload-{$driver}", [$artifact], 60))
            ->toThrow(SwarmException::class, 'Swarm artifact payload [artifact.0] must be a SwarmArtifact or array.');

        expect($repository->all("invalid-payload-{$driver}"))->toBe([]);
    }
})->with([
    'string payload' => ['bad'],
    'object payload' => [new ArtifactInvalidPayloadValue],
]);

test('artifact repositories reject non string names', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-name-{$driver}", [
            ['name' => 42],
        ], 60))->toThrow(SwarmException::class, 'Swarm artifact payload [artifact.0.name] must be a string.');

        expect($repository->all("invalid-name-{$driver}"))->toBe([]);
    }
});

test('artifact repositories reject non array metadata', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-metadata-{$driver}", [
            ['name' => 'manual', 'metadata' => 'bad'],
        ], 60))->toThrow(SwarmException::class, 'Swarm artifact metadata [artifact.0.metadata] must be an array.');

        expect($repository->all("invalid-metadata-{$driver}"))->toBe([]);
    }
});

test('artifact repositories reject nested non plain metadata values', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-nested-metadata-{$driver}", [
            ['name' => 'manual', 'metadata' => ['bad' => new ArtifactInvalidPayloadValue]],
        ], 60))->toThrow(SwarmException::class, 'Swarm plain data value [artifact.0.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');

        expect($repository->all("invalid-nested-metadata-{$driver}"))->toBe([]);
    }
});

test('artifact repositories reject invalid batches without partial persistence', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-batch-{$driver}", [
            ['name' => 'valid-first', 'content' => 'stored only if the full batch is valid'],
            ['name' => 'invalid-second', 'metadata' => ['bad' => new ArtifactInvalidPayloadValue]],
        ], 60))->toThrow(SwarmException::class, 'Swarm plain data value [artifact.1.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');

        expect($repository->all("invalid-batch-{$driver}"))->toBe([]);
    }
});

test('artifact repositories reject invalid step agent classes', function () {
    foreach (artifactRepositories() as $driver => $repository) {
        /** @var ArtifactRepository $repository */
        expect(fn () => $repository->storeMany("invalid-step-agent-{$driver}", [
            ['name' => 'manual', 'step_agent_class' => new ArtifactInvalidPayloadValue],
        ], 60))->toThrow(SwarmException::class, 'Swarm artifact payload [artifact.0.step_agent_class] must be a string or null.');

        expect($repository->all("invalid-step-agent-{$driver}"))->toBe([]);
    }
});
