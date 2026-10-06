<?php

use Illuminate\Support\Arr;

it('pins an exact reproducible Laravel AI continuation candidate', function (): void {
    $directory = dirname(__DIR__).'/Fixtures/Upstream/LaravelAiSavedResultContinuation';
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(Arr::only($manifest, ['target_branch', 'target_sha']))->toBe([
        'target_branch' => '1.x',
        'target_sha' => 'a117adfe4e07696b7ffdf76c3c0b2effc0f0139f',
    ])->and(hash_file('sha256', $directory.'/candidate.patch'))->toBe($manifest['patch_sha256'])
        ->and(hash_file('sha256', $directory.'/composer.lock'))->toBe($manifest['composer_lock_sha256']);

    $patch = file_get_contents($directory.'/candidate.patch');

    expect($patch)->toContain(
        'interface ContinuesConversations',
        'class Continuation',
        'SIGKILL after a durable tool result resumes the same native turn in a fresh process',
        'a custom continuation store records results for a contract-only agent without the framework trait',
        'continuation does not cross provider failover because replay state is provider specific',
        "not->toHaveKey('previous_response_id')",
    );
});
