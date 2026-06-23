<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;

/**
 * Regression for #122: SwarmServiceProvider used to autowire the Encrypter into
 * SwarmPersistenceCipher's constructor, so *resolving* the cipher resolved the
 * encrypter — which throws MissingAppKeyException when no APP_KEY is set. Because
 * `package:discover` boots service providers during a fresh `composer install`
 * (and the Laravel Cloud build) before a key exists, every fresh install threw.
 *
 * The cipher now resolves the encrypter lazily, only when a seal/open actually
 * needs it, so it can be constructed without an APP_KEY.
 */

/** Drop the encrypter (and its aliases) and the cipher so the next resolve rebuilds against the current key. */
function dropKeyAndEncryptionSingletons(Application $app): void
{
    config()->set('app.key', null);

    foreach (['encrypter', Encrypter::class, EncrypterContract::class, SwarmPersistenceCipher::class] as $abstract) {
        $app->forgetInstance($abstract);
    }
}

test('the persistence cipher resolves with no APP_KEY so package:discover boot does not throw (#122)', function () {
    dropKeyAndEncryptionSingletons($this->app);

    // The exact thing package:discover does transitively: resolve the cipher
    // through the container. Before the fix this threw MissingAppKeyException.
    $cipher = $this->app->make(SwarmPersistenceCipher::class);

    expect($cipher)->toBeInstanceOf(SwarmPersistenceCipher::class);
});

test('seal still fails loud when encryption is enabled but no APP_KEY is set', function () {
    dropKeyAndEncryptionSingletons($this->app);
    config()->set('swarm.persistence.encrypt_at_rest', true);

    $cipher = $this->app->make(SwarmPersistenceCipher::class);

    // Constructing the cipher is fine; the key is only required at actual use,
    // where a genuinely-missing key must still fail loud rather than silently.
    expect(fn () => $cipher->seal('secret'))->toThrow(MissingAppKeyException::class);
});

test('the container-resolved cipher seals and opens once an APP_KEY is present', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $this->app->forgetInstance(SwarmPersistenceCipher::class);

    // The base TestCase sets a valid app.key; this proves the lazy resolver
    // path produces a working encrypter and round-trips a sealed value.
    $cipher = $this->app->make(SwarmPersistenceCipher::class);
    $sealed = $cipher->seal('secret prompt');

    expect($sealed)->toStartWith(SwarmPersistenceCipher::PREFIX)
        ->and($cipher->open($sealed))->toBe('secret prompt');
});
