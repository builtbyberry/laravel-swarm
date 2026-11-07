<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Parallel\ParallelProcessResultEnvelope;

function signedProcessResult(string $payload, string $key = 'branch-secret'): string
{
    $encoded = base64_encode($payload);

    return "\n@@LARAVEL-CONCURRENCY-V1@@{$encoded}@@".hash_hmac('sha256', $encoded, $key)."@@\n";
}

test('signed process results accept an authenticated envelope through noisy and forged later stdout', function (): void {
    $payload = json_encode(['successful' => true, 'result' => 'class-free'], JSON_THROW_ON_ERROR);
    $output = "untrusted preface\0".signedProcessResult($payload)
        .signedProcessResult(json_encode(['successful' => true, 'result' => 'forged']), 'foreign-key')."\x1f\x8bnoise";

    expect(ParallelProcessResultEnvelope::decode($output, 'branch-secret'))->toBe(json_decode($payload, true));
});

test('signed process results reject unauthenticated and malformed wire envelopes', function (string $output): void {
    expect(ParallelProcessResultEnvelope::decode($output, 'branch-secret'))->toBeNull();
})->with([
    'plain JSON downgrade' => [json_encode(['successful' => true, 'result' => 'forged'])],
    'foreign signature' => [signedProcessResult('{"successful":true}', 'foreign-key')],
    'corrupted payload' => [str_replace(base64_encode('{"successful":true}'), base64_encode('{"successful":false}'), signedProcessResult('{"successful":true}'))],
    'truncated frame' => [substr(signedProcessResult('{"successful":true}'), 0, -8)],
    'authenticated malformed JSON' => [signedProcessResult('not-json')],
    'authenticated scalar' => [signedProcessResult('true')],
    'authenticated unrelated object' => [signedProcessResult('{"unrelated":true}')],
    'invalid base64' => ['@@LARAVEL-CONCURRENCY-V1@@=@@'.hash_hmac('sha256', '=', 'branch-secret').'@@'],
    'invalid base64 padding' => ['@@LARAVEL-CONCURRENCY-V1@@'.base64_encode('{"successful":true}').'=@@'.hash_hmac('sha256', base64_encode('{"successful":true}').'=', 'branch-secret').'@@'],
]);

test('legacy process results retain complete JSON parsing without accepting framed output', function (): void {
    $payload = '{"successful":true,"result":"legacy"}';

    expect(ParallelProcessResultEnvelope::decode($payload, null))->toBe(json_decode($payload, true))
        ->and(ParallelProcessResultEnvelope::decode('noise'.$payload, null))->toBeNull()
        ->and(ParallelProcessResultEnvelope::decode(signedProcessResult($payload), null))->toBeNull();
});
