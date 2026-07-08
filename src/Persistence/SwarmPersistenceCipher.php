<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Enums\PersistenceDecryptFailurePolicy;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

/**
 * Application-level encryption for sensitive string columns written by database
 * persistence drivers, mirroring Laravel's Encrypter usage (same as encrypted casts).
 *
 * The encrypter is resolved **lazily** (the constructor accepts a
 * `Closure(): Encrypter` from the container binding, or a ready instance for
 * tests). Laravel's encrypter binding throws `MissingAppKeyException` when no
 * `APP_KEY` is set, and `package:discover` boots service providers during a
 * fresh `composer install` *before* the consumer has generated a key — so
 * resolving this cipher (e.g. while booting) must not force the encrypter.
 * Encryption is opt-in (`encrypt_at_rest`), and `seal()`/`open()` only touch the
 * encrypter when they actually have a value to (de)cipher; a genuinely missing
 * key therefore still fails loud at use time, never at boot. See #122.
 *
 * @internal
 */
class SwarmPersistenceCipher
{
    public const PREFIX = 'sw0:';

    protected ?PersistenceDecryptFailurePolicy $resolvedDecryptFailurePolicy = null;

    /**
     * @param  (Closure(): Encrypter)|Encrypter  $encrypter  A ready encrypter,
     *                                                       or a resolver
     *                                                       invoked on first
     *                                                       (de)cipher use.
     */
    public function __construct(
        protected ConfigRepository $config,
        protected Closure|Encrypter $encrypter,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Resolve (once) and return the encrypter. When constructed with a resolver
     * closure, the encrypter — and thus the `APP_KEY` requirement — is not
     * touched until the first seal/open actually needs it.
     */
    protected function encrypter(): Encrypter
    {
        $encrypter = $this->encrypter;

        if ($encrypter instanceof Closure) {
            $encrypter = $encrypter();
            $this->encrypter = $encrypter;
        }

        return $encrypter;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('swarm.persistence.encrypt_at_rest', false);
    }

    public function seal(?string $value): ?string
    {
        if ($value === null || $value === '' || ! $this->enabled()) {
            return $value;
        }

        return self::PREFIX.$this->encrypter()->encryptString($value);
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
            return $this->encrypter()->decryptString(substr($value, strlen(self::PREFIX)));
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

        return $this->encrypter()->decryptString(substr($value, strlen(self::PREFIX)));
    }

    /**
     * Display-safe decrypt for a read-only inspection/display consumer. Where
     * {@see open()} can THROW under `decrypt_failure_policy=throw` and surfaces
     * raw `sw0:` ciphertext under the `legacy` policy, this NEVER throws and NEVER
     * leaks ciphertext: an undecryptable value degrades to `null` alongside an
     * explicit availability=false flag. A display consumer that maps many rows
     * uses this so one poison row can neither abort the batch nor 500 the page,
     * and never has to re-implement the 3-way policy itself (record 632). The
     * operational resume path keeps {@see openStrict()} — this is for evidence and
     * display reads only.
     *
     * @return array{0: ?string, 1: bool} [plaintext or null, decrypted-cleanly]
     */
    public function openForDisplay(?string $value): array
    {
        if ($value === null || $value === '' || ! str_starts_with($value, self::PREFIX)) {
            return [$value, true];
        }

        try {
            $plain = $this->open($value);
        } catch (DecryptException) {
            // decrypt_failure_policy=throw — degrade rather than propagate.
            return [null, false];
        }

        // A decrypt failure surfaces as null (null_with_log) or the ORIGINAL sealed
        // value returned verbatim (legacy passes the ciphertext through). Compare
        // against $value rather than the sw0: prefix so a value that legitimately
        // decrypts to a `sw0:`-prefixed plaintext (#212) is NOT mistaken for
        // ciphertext and masked.
        if ($plain === null || $plain === $value) {
            return [null, false];
        }

        return [$plain, true];
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
     * Display-safe twin of {@see openContextTopLevelInput()}: opens the sealed
     * top-level `input` key via {@see openForDisplay()} so an undecryptable input
     * degrades to null (never throws, never leaks ciphertext) and reports its
     * availability. A row with no sealed input is available by definition.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: bool} [row, input-decrypted-cleanly]
     */
    public function openContextTopLevelInputForDisplay(array $row): array
    {
        $rawInput = $row['input'] ?? null;

        if (! is_string($rawInput)) {
            return [$row, true];
        }

        [$plain, $available] = $this->openForDisplay($rawInput);
        $row['input'] = $plain;

        return [$row, $available];
    }

    /**
     * Display-safe twin of {@see openStepIo()}: opens the sealed `input`/`output`
     * step members via {@see openForDisplay()} and stamps an `input_available` /
     * `output_available` flag alongside each present member, so one undecryptable
     * step never throws or leaks ciphertext into a run-history display (record 632).
     * Absent members (a CaptureDecision::Skip omission) get neither value nor flag.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function openStepIoForDisplay(array $step): array
    {
        foreach (['input' => 'input_available', 'output' => 'output_available'] as $key => $flag) {
            if (isset($step[$key]) && is_string($step[$key])) {
                [$plain, $available] = $this->openForDisplay($step[$key]);
                $step[$key] = $plain;
                $step[$flag] = $available;
            }
        }

        return $step;
    }

    /**
     * Strict twin of {@see openContextTopLevelInput()} for operational
     * child-resume input: the row's `input` is decrypted via {@see openStrict()}
     * (decrypt-or-throw, policy-independent) rather than the display-policy-aware
     * {@see open()}. A wrong/rotated `APP_KEY` therefore throws `DecryptException`
     * instead of silently feeding `null`/ciphertext into a durable child resume.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function openContextTopLevelInputStrict(array $row): array
    {
        if (isset($row['input']) && is_string($row['input'])) {
            $row['input'] = $this->openStrict($row['input']);
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
