<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\IdentifiesSigningKey;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;
use Illuminate\Testing\Assert as PHPUnit;

/**
 * Test double for {@see SwarmAuditSigner} that records every signing call.
 *
 * Wraps an optional delegate signer and forwards each sign() call to it,
 * recording the category, the input payload, and the signed payload the
 * delegate returned. When no delegate is supplied, the recorder returns the
 * input payload unchanged so it works as a transparent stand-alone signer
 * for tests that only care that signing was invoked.
 *
 * SwarmFake installs this via
 * {@see SwarmFake::interceptSwarmAuditSigner()},
 * which swaps the container binding only. The dispatcher resolves this
 * recorder during the real run and records the signing path without
 * SwarmFake itself ever invoking the dispatcher.
 */
class RecordingSwarmAuditSigner implements IdentifiesSigningKey, SwarmAuditSigner
{
    /**
     * @var array<int, array{category: string, input: array<string, mixed>, output: array<string, mixed>}>
     */
    protected array $records = [];

    public function __construct(
        protected ?SwarmAuditSigner $delegate = null,
    ) {}

    public function sign(string $category, array $payload): array
    {
        $output = $this->delegate !== null
            ? $this->delegate->sign($category, $payload)
            : $payload;

        $this->records[] = [
            'category' => $category,
            'input' => $payload,
            'output' => $output,
        ];

        return $output;
    }

    /**
     * Forward the wrapped delegate's key id, or null when there is no delegate
     * or the delegate does not identify a signing key.
     *
     * The recorder is transparent: it mirrors whatever the real signer under
     * test would expose so the dispatcher stamps "signature_key_id" identically
     * whether or not SwarmFake intercepted the signer.
     */
    public function keyId(): ?string
    {
        return $this->delegate instanceof IdentifiesSigningKey
            ? $this->delegate->keyId()
            : null;
    }

    /**
     * Every recorded signing call in the order it was emitted.
     *
     * @return array<int, array{category: string, input: array<string, mixed>, output: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Records for a single category.
     *
     * @return array<int, array{category: string, input: array<string, mixed>, output: array<string, mixed>}>
     */
    public function recordsFor(string $category): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['category'] === $category,
        ));
    }

    /**
     * Assert the signer was invoked at least once.
     *
     * Pass a category to scope the assertion. Pass a callable matcher to
     * inspect the recorded entry (category, input, output).
     */
    public function assertSigned(?string $category = null, ?callable $matcher = null): void
    {
        $records = $category === null
            ? $this->records
            : $this->recordsFor($category);

        $label = $category === null
            ? 'for any category'
            : "for category [{$category}]";

        if ($matcher === null) {
            PHPUnit::assertNotEmpty(
                $records,
                "SwarmAuditSigner was not invoked {$label}.",
            );

            return;
        }

        PHPUnit::assertTrue(
            collect($records)->contains(fn (array $record): bool => (bool) $matcher($record)),
            "SwarmAuditSigner was not invoked {$label} with a record matching the expected matcher.",
        );
    }

    /**
     * Assert the signer was never invoked (optionally for a single category).
     */
    public function assertNeverSigned(?string $category = null): void
    {
        if ($category === null) {
            PHPUnit::assertEmpty(
                $this->records,
                'SwarmAuditSigner was invoked unexpectedly.',
            );

            return;
        }

        PHPUnit::assertEmpty(
            $this->recordsFor($category),
            "SwarmAuditSigner was invoked for category [{$category}] unexpectedly.",
        );
    }
}
