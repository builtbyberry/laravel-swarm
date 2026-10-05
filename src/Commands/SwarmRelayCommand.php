<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\CommandOverlapGuard;
use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;
use BuiltByBerry\LaravelSwarm\Responses\AuditDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\CallbackDrainResult;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:relay')]
class SwarmRelayCommand extends Command
{
    protected $signature = 'swarm:relay
                            {--type=* : Dispatch types to relay (step, branch, queued_resume, audit, callback). Defaults to all.}
                            {--limit= : Maximum number of outbox entries to drain per invocation (overrides config; capped at 10,000).}
                            {--drain-until-empty : Keep draining in a loop until no entries remain.}
                            {--max-attempts= : Maximum drain iterations when using --drain-until-empty. Includes retrying transient failures. Must be >= 1.}';

    protected $description = 'Drain the durable, audit, and callback swarm outboxes (durable dispatches queue jobs; audit re-emits failed evidence records through the bound sink; callback delivers terminal workflow then/catch)';

    protected $help = <<<'HELP'
        This command drains the durable, audit, and callback outbox lanes. It
        dispatches durable work, re-emits failed audit evidence, and delivers
        terminal workflow callbacks. Schedule it to run regularly:

          Schedule::command('swarm:relay')->everyMinute()->withoutOverlapping(
              max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60))
          );

        Without the relay, durable runs stall after writing to the outbox, failed
        audit evidence remains pending, and enabled terminal callbacks are not
        delivered. Use --drain-until-empty to clear backlogs in one invocation.

        The command also owns a finite atomic lease. Configure its store and duration
        with swarm.commands.overlap; the lease must exceed the worst-case drain time.

        EXIT CODES
          0 (success)  All claimed entries were dispatched or permanently removed.
          1 (failure)  Another invocation holds the command overlap lease, or one or more
                       entries could not be dispatched due to a transient error. On
                       contention, identify the competing process or resize the finite
                       lease. On dispatch failure, entries remain reclaimable; check your
                       error tracker and queue driver health.

        --drain-until-empty
          Loops until the outbox is empty. Stops when a batch produces no dispatched or
          skipped rows AND no transient failures, or when --max-attempts is exhausted.

        --max-attempts N
          Limits the number of drain iterations when using --drain-until-empty. Without
          this flag the loop continues only while there is real progress (dispatched or
          skipped rows); transient failures alone stop the loop. With --max-attempts set,
          the loop also retries through batches of pure transient failures up to N times
          total, making it suitable for clearing backlogs during a recovering queue outage.
          Iterations run consecutively with no sleep or backoff between them — size N
          for a short recovery window, not as a substitute for the scheduled relay.

        When --drain-until-empty encounters permanently invalid entries, each loop
        iteration reports and deletes the bad rows and continues until the table is
        empty. This is correct behaviour — use --limit to control throughput.

        Examples:
          php artisan swarm:relay
          php artisan swarm:relay --type=step --type=branch
          php artisan swarm:relay --type=audit
          php artisan swarm:relay --type=callback
          php artisan swarm:relay --limit=500 --drain-until-empty
          php artisan swarm:relay --drain-until-empty --max-attempts=10
        HELP;

    public function handle(DurableOutbox $outbox, AuditOutbox $auditOutbox, CallbackDeliveryOutbox $callbackOutbox, SwarmAuditDispatcher $audit, ConfigRepository $config, CommandOverlapGuard $overlap): int
    {
        $selection = $this->resolveTypes();

        if ($selection === false) {
            return self::FAILURE;
        }

        [
            'raw' => $selectedTypeValues,
            'durable' => $durableTypes,
            'drainDurable' => $shouldDrainDurable,
            'drainAudit' => $shouldDrainAudit,
            'drainCallback' => $shouldDrainCallback,
        ] = $selection;

        $limit = $this->resolveLimit($config);
        $drainUntilEmpty = (bool) $this->option('drain-until-empty');
        $maxAttempts = $this->resolveMaxAttempts();

        if ($maxAttempts !== null && ! $drainUntilEmpty) {
            $this->components->warn('--max-attempts has no effect without --drain-until-empty.');
        }

        $totalDispatched = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $totalClaimed = 0;
        $totalReclaimed = 0;
        $totalAuditReplayed = 0;
        $totalAuditDeadLettered = 0;
        $totalCallbackDispatched = 0;
        $totalCallbackDeadLettered = 0;
        $attempts = 0;
        $durableResult = new DrainResult(0, 0, 0, 0, 0);
        $auditResult = new AuditDrainResult(0, 0, 0, 0, 0);
        $callbackResult = new CallbackDrainResult(0, 0, 0, 0, 0);
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        try {
            $result = $overlap->run(
                CommandOverlapGuard::RELAY_KEY,
                function () use (
                    $outbox,
                    $auditOutbox,
                    $callbackOutbox,
                    $durableTypes,
                    $limit,
                    $drainUntilEmpty,
                    $maxAttempts,
                    $shouldDrainDurable,
                    $shouldDrainAudit,
                    $shouldDrainCallback,
                    &$totalDispatched,
                    &$totalSkipped,
                    &$totalFailed,
                    &$totalClaimed,
                    &$totalReclaimed,
                    &$totalAuditReplayed,
                    &$totalAuditDeadLettered,
                    &$totalCallbackDispatched,
                    &$totalCallbackDeadLettered,
                    &$attempts,
                    &$durableResult,
                    &$auditResult,
                    &$callbackResult,
                ): int {
                    do {
                        $attempts++;
                        $lastDurableProgress = 0;
                        $lastDurableTransient = 0;
                        $lastAuditProgress = 0;
                        $lastAuditTransient = 0;
                        $lastCallbackProgress = 0;
                        $lastCallbackTransient = 0;

                        if ($shouldDrainDurable) {
                            $durableResult = $outbox->drain($durableTypes, $limit);
                            $totalDispatched += $durableResult->dispatched;
                            $totalSkipped += $durableResult->skipped;
                            $totalFailed += $durableResult->failed;
                            $totalClaimed += $durableResult->claimed;
                            $totalReclaimed += $durableResult->reclaimed;
                            $lastDurableProgress = $durableResult->total();
                            $lastDurableTransient = $durableResult->failed;
                        }

                        if ($shouldDrainAudit) {
                            $auditResult = $auditOutbox->drain($limit);
                            $totalAuditReplayed += $auditResult->replayed;
                            $totalAuditDeadLettered += $auditResult->deadLettered;
                            $totalFailed += $auditResult->failed;
                            $totalClaimed += $auditResult->claimed;
                            $totalReclaimed += $auditResult->reclaimed;
                            $lastAuditProgress = $auditResult->total();
                            $lastAuditTransient = $auditResult->failed;
                        }

                        if ($shouldDrainCallback) {
                            $callbackResult = $callbackOutbox->drain($limit);
                            $totalCallbackDispatched += $callbackResult->dispatched;
                            $totalCallbackDeadLettered += $callbackResult->deadLettered;
                            $totalFailed += $callbackResult->failed;
                            $totalClaimed += $callbackResult->claimed;
                            $totalReclaimed += $callbackResult->reclaimed;
                            $lastCallbackProgress = $callbackResult->total();
                            $lastCallbackTransient = $callbackResult->failed;
                        }

                        $madeProgress = ($lastDurableProgress + $lastAuditProgress + $lastCallbackProgress) > 0;
                        $hasTransient = ($lastDurableTransient + $lastAuditTransient + $lastCallbackTransient) > 0;

                        // Only retry transient failures when --max-attempts gives a finite budget.
                        // Without it the loop would spin forever during a sustained queue outage.
                        $shouldRetryTransient = $hasTransient && $maxAttempts !== null && $attempts < $maxAttempts;
                        $shouldContinueForProgress = $madeProgress
                            && ($maxAttempts === null || $attempts < $maxAttempts);

                    } while ($drainUntilEmpty && ($shouldContinueForProgress || $shouldRetryTransient));

                    return self::SUCCESS;
                },
            );

        } catch (Throwable $exception) {
            $audit->emit('command.relay', [
                'types' => $selectedTypeValues,
                'limit' => $limit,
                'drain_until_empty' => $drainUntilEmpty,
                'max_attempts' => $maxAttempts,
                'attempts' => $attempts,
                'dispatched_count' => $totalDispatched,
                'skipped_count' => $totalSkipped,
                'failed_count' => $totalFailed,
                'claimed_count' => $totalClaimed,
                'reclaimed_count' => $totalReclaimed,
                'audit_replayed_count' => $totalAuditReplayed,
                'audit_dead_lettered_count' => $totalAuditDeadLettered,
                'callback_dispatched_count' => $totalCallbackDispatched,
                'callback_dead_lettered_count' => $totalCallbackDeadLettered,
                'status' => 'error',
                'exception_class' => $exception::class,
                ...$audit->metadata($actorMetadata),
            ]);

            throw $exception;
        }

        if ($result === null) {
            $audit->emit('command.relay', [
                'types' => $selectedTypeValues,
                'limit' => $limit,
                'drain_until_empty' => $drainUntilEmpty,
                'max_attempts' => $maxAttempts,
                'attempts' => 0,
                'dispatched_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'claimed_count' => 0,
                'reclaimed_count' => 0,
                'audit_replayed_count' => 0,
                'audit_dead_lettered_count' => 0,
                'callback_dispatched_count' => 0,
                'callback_dead_lettered_count' => 0,
                'status' => 'skipped_overlap',
                ...$audit->metadata($actorMetadata),
            ]);

            $this->components->warn('Run swarm:health --durable. Another swarm:relay invocation holds the command overlap lease; this drain was skipped.');

            return self::FAILURE;
        }

        $lastDurableFailed = $durableResult->failed;
        $lastAuditFailed = $auditResult->failed;
        $lastCallbackFailed = $callbackResult->failed;
        $hasUnresolvedTransient = ($lastDurableFailed + $lastAuditFailed + $lastCallbackFailed) > 0;

        $audit->emit('command.relay', [
            'types' => $selectedTypeValues,
            'limit' => $limit,
            'drain_until_empty' => $drainUntilEmpty,
            'max_attempts' => $maxAttempts,
            'attempts' => $attempts,
            'dispatched_count' => $totalDispatched,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
            'claimed_count' => $totalClaimed,
            'reclaimed_count' => $totalReclaimed,
            'audit_replayed_count' => $totalAuditReplayed,
            'audit_dead_lettered_count' => $totalAuditDeadLettered,
            'callback_dispatched_count' => $totalCallbackDispatched,
            'callback_dead_lettered_count' => $totalCallbackDeadLettered,
            'status' => $this->auditStatus($totalDispatched + $totalAuditReplayed + $totalCallbackDispatched, $totalSkipped + $totalAuditDeadLettered + $totalCallbackDeadLettered, $hasUnresolvedTransient),
            ...$audit->metadata($actorMetadata),
        ]);

        $totalRemoved = $totalDispatched + $totalSkipped + $totalAuditReplayed + $totalAuditDeadLettered + $totalCallbackDispatched + $totalCallbackDeadLettered;

        if ($totalRemoved === 0 && $totalFailed === 0) {
            $this->components->info('No pending outbox entries were found.');

            return self::SUCCESS;
        }

        if ($totalDispatched > 0) {
            $this->components->info('Dispatched '.$totalDispatched.' outbox entr'.($totalDispatched === 1 ? 'y' : 'ies').'.');
        }

        if ($totalAuditReplayed > 0) {
            $this->components->info('Replayed '.$totalAuditReplayed.' audit record'.($totalAuditReplayed === 1 ? '' : 's').'.');
        }

        if ($totalCallbackDispatched > 0) {
            $this->components->info('Dispatched '.$totalCallbackDispatched.' terminal callback deliver'.($totalCallbackDispatched === 1 ? 'y' : 'ies').'.');
        }

        if ($totalCallbackDeadLettered > 0) {
            $this->components->warn('Dead-lettered '.$totalCallbackDeadLettered.' terminal callback'.($totalCallbackDeadLettered === 1 ? '' : 's').' that exceeded swarm.callbacks.max_attempts.');
        }

        if ($totalAuditDeadLettered > 0) {
            $this->components->warn('Dead-lettered '.$totalAuditDeadLettered.' audit record'.($totalAuditDeadLettered === 1 ? '' : 's').' that exceeded swarm.audit.outbox.max_attempts.');
        }

        if ($totalSkipped > 0) {
            $this->components->warn('Skipped '.$totalSkipped.' invalid outbox entr'.($totalSkipped === 1 ? 'y' : 'ies').'. Check your error tracker for details.');
        }

        if ($hasUnresolvedTransient) {
            $stuck = $lastDurableFailed + $lastAuditFailed + $lastCallbackFailed;
            $this->components->warn(
                $stuck.' outbox entr'.($stuck === 1 ? 'y' : 'ies').' could not be dispatched due to a transient error'
                .($maxAttempts !== null ? ' after '.$attempts.' attempt'.($attempts === 1 ? '' : 's') : '')
                .'. The '.($stuck === 1 ? 'entry' : 'entries').' will be re-claimed after the reservation timeout.'
                .' Check your error tracker and queue driver.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the `--type` flag into the lanes to drain.
     *
     * The CLI flag accepts the three durable dispatch types (step, branch,
     * queued_resume) plus the audit lane keyword (audit) — the exact surface
     * documented since v0.5.0. This maps each string to its lane: durable types
     * become DurableDispatchType cases restricting the durable drain, and `audit`
     * selects the audit lane. No flag means drain both lanes.
     *
     * @return array{raw: list<string>, durable: list<DurableDispatchType>, drainDurable: bool, drainAudit: bool, drainCallback: bool}|false
     *                                                                                                                                       false signals a validation failure
     */
    protected function resolveTypes(): array|false
    {
        $raw = (array) $this->option('type');
        $raw = array_values(array_filter($raw, static fn (mixed $v): bool => is_string($v) && $v !== ''));

        // No --type flag: drain every lane with no durable-type restriction.
        if ($raw === []) {
            return ['raw' => [], 'durable' => [], 'drainDurable' => true, 'drainAudit' => true, 'drainCallback' => true];
        }

        $durable = [];
        $drainAudit = false;
        $drainCallback = false;

        foreach ($raw as $value) {
            if ($value === RelayLane::Audit->value) {
                $drainAudit = true;

                continue;
            }

            if ($value === RelayLane::Callback->value) {
                $drainCallback = true;

                continue;
            }

            $type = DurableDispatchType::tryFrom($value);

            if ($type === null) {
                $valid = implode(', ', [
                    ...array_column(DurableDispatchType::cases(), 'value'),
                    RelayLane::Audit->value,
                    RelayLane::Callback->value,
                ]);
                $this->components->error("Unknown dispatch type [{$value}]. Valid types: {$valid}.");

                return false;
            }

            $durable[] = $type;
        }

        return [
            'raw' => $raw,
            'durable' => $durable,
            'drainDurable' => $durable !== [],
            'drainAudit' => $drainAudit,
            'drainCallback' => $drainCallback,
        ];
    }

    protected function resolveLimit(ConfigRepository $config): int
    {
        $raw = $this->option('limit');
        $configured = (int) $config->get('swarm.durable.relay.limit', 100);
        $value = $raw !== null ? (int) $raw : $configured;

        return max(1, min($value, 10_000));
    }

    protected function resolveMaxAttempts(): ?int
    {
        $raw = $this->option('max-attempts');

        if ($raw === null) {
            return null;
        }

        $value = (int) $raw;

        if ($value < 1) {
            $this->components->warn('--max-attempts must be >= 1; ignoring.');

            return null;
        }

        return $value;
    }

    protected function auditStatus(int $dispatched, int $skipped, bool $hasUnresolvedTransient): string
    {
        if ($hasUnresolvedTransient) {
            return 'transient_failure';
        }

        if ($dispatched > 0) {
            return 'dispatched';
        }

        if ($skipped > 0) {
            return 'skipped';
        }

        return 'none_found';
    }
}
