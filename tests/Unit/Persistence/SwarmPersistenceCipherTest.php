<?php

declare(strict_types=1);

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
): SwarmPersistenceCipher {
    return new SwarmPersistenceCipher(
        new Repository([
            'swarm.persistence.encrypt_at_rest' => $encryptAtRest,
            'swarm.persistence.driver' => $persistenceDriver,
            'swarm.persistence.decrypt_failure_policy' => $decryptFailurePolicy,
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

test('seal and open round trip when database persistence and encryption are enabled', function () {
    $cipher = makeCipher(true, 'database');

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
