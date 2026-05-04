<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Config\Repository;
use Illuminate\Encryption\Encrypter;

function makeCipher(bool $encryptAtRest, string $persistenceDriver = 'cache'): SwarmPersistenceCipher
{
    return new SwarmPersistenceCipher(
        new Repository([
            'swarm.persistence.encrypt_at_rest' => $encryptAtRest,
            'swarm.persistence.driver' => $persistenceDriver,
        ]),
        new Encrypter(random_bytes(32), 'aes-256-cbc'),
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
