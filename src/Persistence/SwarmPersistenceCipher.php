<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Enums\PersistenceDecryptFailurePolicy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

/**
 * Application-level encryption for sensitive string columns written by database
 * persistence drivers, mirroring Laravel's Encrypter usage (same as encrypted casts).
 *
 * @internal
 */
class SwarmPersistenceCipher
{
    public const PREFIX = 'sw0:';

    protected ?PersistenceDecryptFailurePolicy $resolvedDecryptFailurePolicy = null;

    public function __construct(
        protected ConfigRepository $config,
        protected Encrypter $encrypter,
        protected LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('swarm.persistence.encrypt_at_rest', false);
    }

    public function seal(?string $value): ?string
    {
        if ($value === null || $value === '' || ! $this->enabled()) {
            return $value;
        }

        return self::PREFIX.$this->encrypter->encryptString($value);
    }

    /**
     * Open a sealed value for an **evidence / display** read (run history, audit,
     * inspector). On a decrypt failure this honors the operator's
     * `swarm.persistence.decrypt_failure_policy` — `null_with_log` (default),
     * `legacy` (surface the stored ciphertext), or `throw`.
     *
     * For an **operational, recomputable** read — where the right answer on a
     * decrypt failure is "recompute," not "show the operator something" — use
     * {@see openStrict()} instead, which gives an unambiguous decrypted-or-throw
     * result independent of that display policy.
     */
    public function open(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return $this->encrypter->decryptString(substr($value, strlen(self::PREFIX)));
        } catch (DecryptException $e) {
            return match ($this->decryptFailurePolicy()) {
                PersistenceDecryptFailurePolicy::Legacy => $value,
                PersistenceDecryptFailurePolicy::Throw => throw $e,
                PersistenceDecryptFailurePolicy::NullWithLog => $this->decryptFailedReturnNull($value),
            };
        }
    }

    /**
     * Decrypt a sealed value strictly: a non-prefixed value is returned as-is
     * (plaintext / encryption disabled), and a sealed (`sw0:`-prefixed) value is
     * decrypted or **throws `DecryptException`** — independent of
     * `swarm.persistence.decrypt_failure_policy`.
     *
     * The failure policy governs the evidence/display read path ({@see open()});
     * it is the wrong lens for operational state that can safely recompute on
     * failure (e.g. the stream-step checkpoint resume path, issue #202). Such a
     * consumer needs an unambiguous "did this decrypt cleanly?" answer — `open()`
     * overloads its return (null / ciphertext / throw, by policy), forcing the
     * caller to guess from the plaintext's bytes. `openStrict()` never returns a
     * sealed/ciphertext value and never swallows a failure: the caller catches
     * `DecryptException` and treats it as "not usable → recompute."
     */
    public function openStrict(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        return $this->encrypter->decryptString(substr($value, strlen(self::PREFIX)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function sealContextTopLevelInput(array $row): array
    {
        if (! $this->enabled()) {
            return $row;
        }

        if (isset($row['input']) && is_string($row['input'])) {
            $row['input'] = $this->seal($row['input']);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function openContextTopLevelInput(array $row): array
    {
        if (isset($row['input']) && is_string($row['input'])) {
            $row['input'] = $this->open($row['input']);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function sealStepIo(array $step): array
    {
        if (! $this->enabled()) {
            return $step;
        }

        foreach (['input', 'output'] as $key) {
            if (isset($step[$key]) && is_string($step[$key])) {
                $step[$key] = $this->seal($step[$key]);
            }
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function openStepIo(array $step): array
    {
        foreach (['input', 'output'] as $key) {
            if (isset($step[$key]) && is_string($step[$key])) {
                $step[$key] = $this->open($step[$key]);
            }
        }

        return $step;
    }

    private function decryptFailurePolicy(): PersistenceDecryptFailurePolicy
    {
        if ($this->resolvedDecryptFailurePolicy !== null) {
            return $this->resolvedDecryptFailurePolicy;
        }

        $raw = $this->config->get('swarm.persistence.decrypt_failure_policy');
        $parsed = PersistenceDecryptFailurePolicy::parse(is_string($raw) ? $raw : null);

        if (
            $parsed['invalid']
            && filter_var(
                $this->config->get('swarm.persistence.warn_on_invalid_decrypt_failure_policy', true),
                FILTER_VALIDATE_BOOLEAN,
            )
        ) {
            $this->logger->warning(
                'Unrecognized swarm.persistence.decrypt_failure_policy value; using null_with_log as the effective decrypt failure policy.',
                ['config_key' => 'swarm.persistence.decrypt_failure_policy'],
            );
        }

        $this->resolvedDecryptFailurePolicy = $parsed['policy'];

        return $this->resolvedDecryptFailurePolicy;
    }

    private function decryptFailedReturnNull(string $prefixedValue): null
    {
        $this->logger->warning('Swarm persistence could not decrypt a sealed column value. The field will be returned as null. Verify APP_KEY matches the key used to encrypt stored rows.', [
            'sealed_length' => strlen($prefixedValue),
            'has_swarm_prefix' => str_starts_with($prefixedValue, self::PREFIX),
        ]);

        return null;
    }
}
