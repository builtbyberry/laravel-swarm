<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\PersistenceDecryptFailurePolicy;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

function makeCipher(
    bool $encryptAtRest,
    string $persistenceDriver = 'cache',
    ?LoggerInterface $logger = null,
    string $decryptFailurePolicy = 'null_with_log',
    bool $warnOnInvalidDecryptFailurePolicy = true,
): SwarmPersistenceCipher {
    return new SwarmPersistenceCipher(
        new Repository([
            'swarm.persistence.encrypt_at_rest' => $encryptAtRest,
            'swarm.persistence.driver' => $persistenceDriver,
            'swarm.persistence.decrypt_failure_policy' => $decryptFailurePolicy,
            'swarm.persistence.warn_on_invalid_decrypt_failure_policy' => $warnOnInvalidDecryptFailurePolicy,
        ]),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        $logger ?? new NullLogger,
    );
}

test('seal is a no-op when encrypt at rest is disabled', function () {
    $cipher = makeCipher(false, 'database');

    expect($cipher->enabled())->toBeFalse()
        ->and($cipher->seal('plain'))->toBe('plain');
});

test('seal and open round trip when encryption is enabled', function () {
    $cipher = makeCipher(true);

    expect($cipher->enabled())->toBeTrue();

    $sealed = $cipher->seal('secret prompt');
    expect($sealed)->toStartWith(SwarmPersistenceCipher::PREFIX)
        ->and($cipher->open($sealed))->toBe('secret prompt');
});

test('open leaves legacy plaintext untouched', function () {
    $cipher = makeCipher(true, 'database');

    expect($cipher->open('no prefix here'))->toBe('no prefix here');
});

test('open logs and returns null when decrypt fails under null_with_log', function () {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => 'null_with_log',
        'swarm.persistence.warn_on_invalid_decrypt_failure_policy' => true,
    ];

    $cipherSeal = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    $sealed = $cipherSeal->seal('secret');

    $cipherOpen = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        $logger,
    );

    expect($cipherOpen->open($sealed))->toBeNull();
});

test('open logs unrecognized decrypt_failure_policy once when warn toggle is enabled', function () {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('warning');

    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => 'not-a-valid-policy',
        'swarm.persistence.warn_on_invalid_decrypt_failure_policy' => true,
    ];

    $cipherSeal = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    $sealed = $cipherSeal->seal('secret');

    $cipherOpen = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        $logger,
    );

    expect($cipherOpen->open($sealed))->toBeNull();
});

test('open does not log unrecognized policy when warn toggle is disabled', function () {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => 'not-a-valid-policy',
        'swarm.persistence.warn_on_invalid_decrypt_failure_policy' => false,
    ];

    $cipherSeal = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    $sealed = $cipherSeal->seal('secret');

    $cipherOpen = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        $logger,
    );

    expect($cipherOpen->open($sealed))->toBeNull();
});

test('parse marks unknown non-empty policy as invalid', function () {
    $parsed = PersistenceDecryptFailurePolicy::parse('typo');

    expect($parsed['invalid'])->toBeTrue()
        ->and($parsed['policy'])->toBe(PersistenceDecryptFailurePolicy::NullWithLog);
});

test('parse treats explicit known policies as valid', function () {
    foreach (['null_with_log', 'NULL_WITH_LOG', ' legacy ', 'throw'] as $raw) {
        $parsed = PersistenceDecryptFailurePolicy::parse($raw);

        expect($parsed['invalid'])->toBeFalse();
    }
});

test('open returns opaque ciphertext when decrypt fails under legacy policy', function () {
    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => 'legacy',
    ];

    $cipherSeal = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    $sealed = $cipherSeal->seal('secret');

    $cipherOpen = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    expect($cipherOpen->open($sealed))->toBe($sealed)
        ->and($sealed)->toStartWith(SwarmPersistenceCipher::PREFIX);
});

test('open rethrows when decrypt fails under throw policy', function () {
    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => 'throw',
    ];

    $cipherSeal = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    $sealed = $cipherSeal->seal('secret');

    $cipherOpen = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    expect(fn () => $cipherOpen->open($sealed))->toThrow(DecryptException::class);
});

test('openContextTopLevelInputStrict passes through a plaintext input (#212)', function () {
    $cipher = makeCipher(true, 'database');

    expect($cipher->openContextTopLevelInputStrict(['input' => 'plain'])['input'])->toBe('plain');
});

test('openContextTopLevelInputStrict round-trips a sealed input (#212)', function () {
    $cipher = makeCipher(true);

    $row = $cipher->sealContextTopLevelInput(['input' => 'secret prompt']);

    expect($cipher->openContextTopLevelInputStrict($row)['input'])->toBe('secret prompt');
});

test('openContextTopLevelInputStrict round-trips an input that legitimately starts with the sealed prefix (#212)', function () {
    $cipher = makeCipher(true);

    $row = $cipher->sealContextTopLevelInput(['input' => 'sw0:hello']);

    expect($cipher->openContextTopLevelInputStrict($row)['input'])->toBe('sw0:hello');
});

test('openContextTopLevelInputStrict throws on an undecryptable input regardless of decrypt_failure_policy (#212)', function (string $policy) {
    $repo = [
        'swarm.persistence.encrypt_at_rest' => true,
        'swarm.persistence.driver' => 'database',
        'swarm.persistence.decrypt_failure_policy' => $policy,
    ];

    $sealer = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );
    $row = $sealer->sealContextTopLevelInput(['input' => 'secret prompt']);

    // A different key cannot decrypt — the strict read must throw under EVERY display
    // policy (it deliberately ignores decrypt_failure_policy).
    $opener = new SwarmPersistenceCipher(
        new Repository($repo),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
        new NullLogger,
    );

    expect(fn () => $opener->openContextTopLevelInputStrict($row))->toThrow(DecryptException::class);
})->with(['null_with_log', 'legacy', 'throw']);

test('openForDisplay passes plaintext, null, and empty through as available', function () {
    $cipher = makeCipher(true, 'database');

    expect($cipher->openForDisplay('no prefix here'))->toBe(['no prefix here', true])
        ->and($cipher->openForDisplay(null))->toBe([null, true])
        ->and($cipher->openForDisplay(''))->toBe(['', true]);
});

test('openForDisplay round-trips a sealed value under the correct key', function () {
    $cipher = makeCipher(true, 'database');

    $sealed = $cipher->seal('secret prompt');

    expect($cipher->openForDisplay($sealed))->toBe(['secret prompt', true]);
});

test('openForDisplay degrades a rotated-key value to null+unavailable under null_with_log', function () {
    $sealed = makeCipher(true, 'database')->seal('secret prompt');
    $opener = makeCipher(true, 'database', null, 'null_with_log');

    // Different random key => decrypt fails; display read degrades, never throws.
    expect($opener->openForDisplay($sealed))->toBe([null, false]);
});

test('openForDisplay masks a rotated-key value and never leaks ciphertext under legacy', function () {
    $sealed = makeCipher(true, 'database')->seal('secret prompt');
    $opener = makeCipher(true, 'database', null, 'legacy');

    // legacy policy would surface the raw sw0: ciphertext via open(); openForDisplay
    // must NOT leak it — it degrades to null+unavailable.
    [$value, $available] = $opener->openForDisplay($sealed);

    // null (not the stored sw0: ciphertext) proves it did not leak the ciphertext.
    expect($available)->toBeFalse()
        ->and($value)->toBeNull();
});

test('openForDisplay never throws under the throw policy and degrades instead', function () {
    $sealed = makeCipher(true, 'database')->seal('secret prompt');
    $opener = makeCipher(true, 'database', null, 'throw');

    // open() under throw would raise DecryptException; openForDisplay swallows it.
    expect($opener->openForDisplay($sealed))->toBe([null, false]);
});

test('openStepIoForDisplay decrypts a clean member and degrades a poison member under throw', function () {
    $opener = makeCipher(true, 'database', null, 'throw');
    $poisonOutput = makeCipher(true, 'database')->seal('the output'); // sealed under a foreign key

    $step = $opener->openStepIoForDisplay([
        'step_index' => 0,
        'agent_class' => 'A',
        'input' => $opener->seal('mine'), // sealed under opener's own key → decrypts
        'output' => $poisonOutput,        // sealed under a different key → degrades
    ]);

    expect($step['input_available'])->toBeTrue()
        ->and($step['input'])->toBe('mine')
        ->and($step['output_available'])->toBeFalse()
        ->and($step['output'])->toBeNull();
});

test('openStepIoForDisplay leaves an absent (Skip-omitted) member absent', function () {
    $cipher = makeCipher(true, 'database');

    $step = $cipher->openStepIoForDisplay(['step_index' => 1, 'agent_class' => 'B']);

    expect($step)->not->toHaveKey('input')
        ->and($step)->not->toHaveKey('input_available')
        ->and($step)->not->toHaveKey('output_available');
});

test('openContextTopLevelInputForDisplay degrades a poison input without throwing', function () {
    $sealed = makeCipher(true, 'database')->seal('context prompt');
    $opener = makeCipher(true, 'database', null, 'throw');

    [$row, $available] = $opener->openContextTopLevelInputForDisplay(['input' => $sealed, 'data' => ['x' => 1]]);

    expect($available)->toBeFalse()
        ->and($row['input'])->toBeNull()
        ->and($row['data'])->toBe(['x' => 1]);
});

test('openContextTopLevelInputForDisplay reports available for a row with no sealed input', function () {
    $cipher = makeCipher(true, 'database');

    [$row, $available] = $cipher->openContextTopLevelInputForDisplay(['data' => ['y' => 2]]);

    expect($available)->toBeTrue()
        ->and($row)->toBe(['data' => ['y' => 2]]);
});

test('openForDisplay returns a value that legitimately decrypts to a sw0: prefixed plaintext (#212)', function () {
    $cipher = makeCipher(true, 'database');

    // Real plaintext that happens to start with the sealed sentinel; sealed on
    // write, it must decrypt back to itself and NOT be masked as ciphertext.
    $sealed = $cipher->seal('sw0:hello');

    expect($cipher->openForDisplay($sealed))->toBe(['sw0:hello', true]);
});
