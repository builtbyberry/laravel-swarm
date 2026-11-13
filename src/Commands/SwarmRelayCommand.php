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
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Queue\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @phpstan-type RelayBatch array{results: array<string, DrainResult|AuditDrainResult|CallbackDrainResult>, contended: list<string>}
 * @phpstan-type RelayTotals array{dispatched: int, skipped: int, failed: int, claimed: int, reclaimed: int, reclaimed_direct: int, audit_replayed: int, audit_dead_lettered: int, callback_dispatched: int, callback_dead_lettered: int}
 */
#[AsCommand(name: 'swarm:relay')]
class SwarmRelayCommand extends Command
{
    /**
     * The cache key queue:restart writes to the default cache store.
     */
    private const RESTART_CACHE_KEY = 'illuminate:queue:restart';

    /**
     * The longest single sleep the daemon takes before re-checking for a stop.
     */
    private const DAEMON_SLEEP_SLICE_MS = 500;

    /**
     * The highest idle-sleep bound accepted from configuration.
     */
    private const DAEMON_MAX_SLEEP_CEILING_MS = 60_000;

    /**
     * The lanes whose work keeps a daemon on its short cadence and makes a cycle
     * reportable. The audit lane is left out so a failing audit sink cannot keep
     * the daemon replaying, and reporting, its own evidence.
     */
    private const DAEMON_WORK_LANES = ['durable', 'callback'];

    protected $signature = 'swarm:relay
                            {--type=* : Dispatch types to relay (step, branch, queued_resume, audit, callback). Defaults to all.}
                            {--limit= : Maximum number of outbox entries to drain per invocation, or per lane per cycle with --daemon (overrides config; capped at 10,000).}
                            {--drain-until-empty : Keep draining in a loop until no entries remain.}
                            {--max-attempts= : Maximum drain iterations when using --drain-until-empty. Includes retrying transient failures. Must be >= 1.}
                            {--daemon : Run as one long-lived relay process with an adaptive idle sleep instead of exiting after one pass. Requires pcntl.}
                            {--max-time= : With --daemon, stop gracefully after this many seconds so a process supervisor restarts it (0 for no limit; default 0).}
                            {--memory= : With --daemon, stop gracefully once memory use reaches this many megabytes (0 for no limit; default 128).}';

    protected $description = 'Drain the durable, audit, and callback swarm outboxes (durable dispatches queue jobs; audit re-emits failed evidence records through the bound sink; callback delivers terminal workflow then/catch)';

    protected $help = <<<'HELP'
        This command drains the durable, audit, and callback outbox lanes. It
        dispatches durable work, re-emits failed audit evidence, and delivers
        terminal workflow callbacks. Schedule it to run regularly:

          Schedule::command('swarm:relay')->everyMinute()->withoutOverlapping(
              max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60))
          );

        Or run it as one long-lived process with --daemon (DAEMON MODE below).
        Without the relay, durable runs stall after writing to the outbox, failed
        audit evidence remains pending, and enabled terminal callbacks are not
        delivered. Use --drain-until-empty to clear backlogs in one invocation.

        LANE LEASES
          Each lane takes its own finite atomic lease: durable (step, branch and
          queued_resume share one), audit, and callback. A lane's lease is held only
          while that lane drains, so relays selecting different lanes run side by
          side and a slow audit or callback drain never holds up another relay's
          step dispatch. Two relays draining the same lane still exclude each other.
          Within one invocation the selected lanes drain one after another. When
          another invocation holds a selected lane, that lane's remaining batches
          are skipped for this run, the other lanes still drain, and the command
          exits 1. (A daemon instead skips a held lane for that cycle only and
          keeps running; see DAEMON MODE.) Configure the store and duration with
          swarm.commands.overlap; the lease must exceed the worst-case single
          drain batch.

          To keep step dispatch on a fast cadence and audit and callback work on a
          slow one, schedule the lanes separately (queued_resume belongs with the
          fast lane because eager dispatch never pushes it):

            Schedule::command('swarm:relay --type=step --type=branch --type=queued_resume')
                ->everyMinute()->withoutOverlapping(...);
            Schedule::command('swarm:relay --type=audit --type=callback')
                ->everyFiveMinutes()->withoutOverlapping(...);

          A slower audit/callback entry delays terminal callbacks by up to its
          interval, and swarm:health warns about audit and callback rows waiting
          longer than twice the reservation timeout (120s by default). Raise the
          warning thresholds above the interval as described under "Split-cadence
          relay schedule" in docs/maintenance.md.

        EXIT CODES (scheduled and one-shot runs; for --daemon see DAEMON MODE)
          0 (success)  All claimed entries were dispatched or permanently removed.
          1 (failure)  Another invocation holds the lease of a selected lane (that lane
                       was skipped; other lanes still drained), or one or more entries
                       could not be dispatched due to a transient error. On contention,
                       identify the competing process or resize the finite lease. On
                       dispatch failure, entries remain reclaimable; check your error
                       tracker and queue driver health.

        --drain-until-empty
          Loops until the outbox is empty. Stops when a batch produces no dispatched or
          skipped rows AND no transient failures, or when --max-attempts is exhausted.
          Lane leases are released between batches, so a scheduled relay can take a
          lane mid-run; that lane is then skipped and the command exits 1. To clear a
          backlog fully, select only the backlogged lane with --type or pause the
          matching schedule entry, and re-run until the command exits 0.

        --max-attempts N
          Limits the number of drain iterations when using --drain-until-empty. Without
          this flag the loop continues only while there is real progress (dispatched or
          skipped rows); transient failures alone stop the loop. With --max-attempts set,
          the loop also retries through batches of pure transient failures up to N times
          total, making it suitable for clearing backlogs during a recovering queue outage.
          Iterations run consecutively with no sleep or backoff between them — size N
          for a short recovery window, not as a substitute for the scheduled relay.

        DAEMON MODE (--daemon)
          Runs the relay as one long-lived process instead of booting the framework
          for every tick. Each cycle drains every selected lane once (--limit rows
          per lane), each lane under its own lease, taken for that lane's batch and
          released before the next lane and before any sleep. Then it sleeps:
          swarm.durable.relay.daemon.min_sleep_ms after a cycle that moved durable
          or callback rows, otherwise a sleep that grows by backoff_multiplier up to
          max_sleep_ms. Audit-lane replays, transient failures and held lanes do not
          shorten the sleep. A held lane is skipped for that cycle only.

          Prefer a daemon when you want sub-minute polling without a framework boot
          per tick. With eager dispatch on, a schedule entry every minute is
          usually enough and needs no process supervisor. Do not also schedule
          swarm:relay for the same lanes beside a daemon.

          Stopping. SIGTERM or SIGINT lets the current cycle finish (every
          remaining selected lane's batch and its reporting), then exits 0; a stop
          during the idle sleep is acted on within about 500 ms. queue:restart
          (read from the default cache store, as queue:work does) also stops it at
          its next check, after the current cycle. --max-time=N stops it after N
          seconds and --memory=MB once memory use reaches MB (default 128); 0
          disables either. Run it under a process supervisor that always restarts
          it, and restart it on every deploy: code and configuration are loaded
          once at start. Daemon mode requires the pcntl extension.

          Evidence. Each cycle that moved or failed durable or callback rows, or
          found a lane held, emits one command.relay event for that cycle; idle and
          audit-only cycles emit nothing. Console lines are timestamped; a cycle
          with audit-lane activity only is printed but not audited.

          Exit codes. 0 when stopped by a signal, queue:restart, --max-time or
          --memory. Non-zero only on genuine failure: invalid options or
          configuration, missing pcntl, an exception from a drain (audited as
          status error, then rethrown), or a failure reading the queue:restart
          key from the default cache store (not audited, as with queue:work).

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
          php artisan swarm:relay --daemon --max-time=3600
          php artisan swarm:relay --daemon --type=step --type=branch --type=queued_resume
        HELP;

    public function handle(DurableOutbox $outbox, AuditOutbox $auditOutbox, CallbackDeliveryOutbox $callbackOutbox, SwarmAuditDispatcher $audit, ConfigRepository $config, CommandOverlapGuard $overlap): int
    {
        $daemon = (bool) $this->option('daemon');
        $drainUntilEmpty = (bool) $this->option('drain-until-empty');

        if ($daemon && $drainUntilEmpty) {
            $this->components->error('--daemon and --drain-until-empty cannot be combined: the daemon already drains continuously. Use --drain-until-empty on its own for a one-off backlog clear.');

            return self::FAILURE;
        }

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
        $maxAttempts = $this->resolveMaxAttempts();

        if ($maxAttempts !== null && ! $drainUntilEmpty) {
            $this->components->warn('--max-attempts has no effect without --drain-until-empty.');
        }

        // Each selected lane's drain for one batch, in lane order.
        /** @var array<string, Closure(): (DrainResult|AuditDrainResult|CallbackDrainResult)> $drainers */
        $drainers = [];

        if ($shouldDrainDurable) {
            $drainers[RelayLane::Durable->value] = static fn (): DrainResult => $outbox->drain($durableTypes, $limit);
        }

        if ($shouldDrainAudit) {
            $drainers[RelayLane::Audit->value] = static fn (): AuditDrainResult => $auditOutbox->drain($limit);
        }

        if ($shouldDrainCallback) {
            $drainers[RelayLane::Callback->value] = static fn (): CallbackDrainResult => $callbackOutbox->drain($limit);
        }

        if ($daemon) {
            $settings = $this->resolveDaemonSettings($config);

            if ($settings === false) {
                return self::FAILURE;
            }

            return $this->runDaemon($drainers, $selectedTypeValues, $limit, $settings, $overlap, $audit, $config);
        }

        foreach (['max-time', 'memory'] as $daemonOption) {
            if ($this->option($daemonOption) !== null) {
                $this->components->warn("--{$daemonOption} has no effect without --daemon.");
            }
        }

        $totals = $this->emptyTotals();
        $attempts = 0;
        // The last batch each lane actually drained; a lane dropped for contention
        // keeps its last real result, so an unresolved transient failure still counts.
        $last = [
            RelayLane::Durable->value => new DrainResult(0, 0, 0, 0, 0),
            RelayLane::Audit->value => new AuditDrainResult(0, 0, 0, 0, 0),
            RelayLane::Callback->value => new CallbackDrainResult(0, 0, 0, 0, 0),
        ];
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        // Each lane's lease is held only while that lane drains, so no relay lease
        // is ever held while another lane drains. A lane another invocation holds
        // is dropped for the rest of this run; the run exits non-zero.
        /** @var array<string, true> $contended */
        $contended = [];
        $drained = false;

        try {
            do {
                $attempts++;
                $batch = $this->emptyBatch();

                try {
                    $this->drainBatch(
                        array_diff_key($drainers, $contended),
                        $overlap,
                        $batch,
                        fn (RelayLane $lane) => $this->components->warn("Another swarm:relay invocation holds the [{$lane->value}] lane's command overlap lease; skipping that lane's remaining batches this run."),
                    );
                } finally {
                    $this->addBatchToTotals($batch, $totals);

                    foreach ($batch['results'] as $lane => $result) {
                        $last[$lane] = $result;
                        $drained = true;
                    }

                    foreach ($batch['contended'] as $lane) {
                        $contended[$lane] = true;
                    }
                }

                $madeProgress = $this->batchRemoved($batch) > 0;
                $hasTransient = $this->batchFailed($batch) > 0;
                $anyLaneLive = array_diff_key($drainers, $contended) !== [];

                // Only retry transient failures when --max-attempts gives a finite budget.
                // Without it the loop would spin forever during a sustained queue outage.
                $shouldRetryTransient = $hasTransient && $maxAttempts !== null && $attempts < $maxAttempts;
                $shouldContinueForProgress = $madeProgress
                    && ($maxAttempts === null || $attempts < $maxAttempts);

            } while ($drainUntilEmpty && $anyLaneLive && ($shouldContinueForProgress || $shouldRetryTransient));

        } catch (Throwable $exception) {
            $audit->emit('command.relay', [
                ...$this->relayEvidence($selectedTypeValues, $limit, $drainUntilEmpty, $maxAttempts, $attempts, $totals, $contended),
                'status' => 'error',
                'exception_class' => $exception::class,
                ...$audit->metadata($actorMetadata),
            ]);

            throw $exception;
        }

        if (! $drained) {
            $audit->emit('command.relay', [
                ...$this->relayEvidence($selectedTypeValues, $limit, $drainUntilEmpty, $maxAttempts, 0, $this->emptyTotals(), $contended),
                'status' => 'skipped_overlap',
                ...$audit->metadata($actorMetadata),
            ]);

            $this->components->warn('Every selected lane\'s command overlap lease is held by another swarm:relay invocation; nothing was drained. Look for a competing swarm:relay process or schedule entry selecting the same lanes (see contended_lanes in the command.relay audit event).');

            return self::FAILURE;
        }

        $lastFailed = [
            RelayLane::Durable->value => $last[RelayLane::Durable->value]->failed,
            RelayLane::Audit->value => $last[RelayLane::Audit->value]->failed,
            RelayLane::Callback->value => $last[RelayLane::Callback->value]->failed,
        ];
        $hasUnresolvedTransient = array_sum($lastFailed) > 0;

        $audit->emit('command.relay', [
            ...$this->relayEvidence($selectedTypeValues, $limit, $drainUntilEmpty, $maxAttempts, $attempts, $totals, $contended),
            'status' => $this->totalsStatus($totals, $hasUnresolvedTransient),
            ...$audit->metadata($actorMetadata),
        ]);

        $exitCode = $contended === [] ? self::SUCCESS : self::FAILURE;

        if ($this->totalsRemoved($totals) === 0 && $totals['failed'] === 0) {
            $this->components->info($contended === []
                ? 'No pending outbox entries were found.'
                : 'No pending entries in the lanes this run drained; held lanes ['.implode(', ', $this->contendedLanes($contended)).'] were not drained.');

            return $exitCode;
        }

        $this->reportTotals($totals, $lastFailed, $maxAttempts, $attempts);

        return $hasUnresolvedTransient ? self::FAILURE : $exitCode;
    }

    /**
     * Run as one long-lived relay: drain every selected lane once per cycle, then
     * sleep adaptively until the next cycle, until a stop is requested.
     *
     * @param  array<string, Closure(): (DrainResult|AuditDrainResult|CallbackDrainResult)>  $drainers
     * @param  list<string>  $selectedTypeValues
     * @param  array{min_sleep_ms: int, max_sleep_ms: int, backoff_multiplier: float, max_time: int, memory: int}  $settings
     */
    private function runDaemon(array $drainers, array $selectedTypeValues, int $limit, array $settings, CommandOverlapGuard $overlap, SwarmAuditDispatcher $audit, ConfigRepository $config): int
    {
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        // The stop request is a local captured by reference: the command keeps no
        // mutable daemon state of its own. Command::run() untraps afterwards.
        $signal = null;
        $this->trap(
            static fn (): array => [SIGTERM, SIGINT],
            static function (int $received) use (&$signal): void {
                $signal = $received;
            },
        );

        // queue:restart writes this key to the default cache store. Resolve it only
        // here, after validation, so a scheduled relay never depends on that store.
        $restartCache = null;
        $lastRestart = null;

        if (Worker::$restartable) {
            $restartCache = $this->laravel->make(CacheFactory::class)->store();
            $lastRestart = $restartCache->get(self::RESTART_CACHE_KEY);
        }

        $startedAt = Carbon::now();
        $elapsedMs = static fn (): float => $startedAt->diffInMilliseconds(Carbon::now(), true);

        // Read through a call: the trap writes $signal asynchronously, between any two statements.
        $signalled = static function () use (&$signal): bool {
            return $signal !== null;
        };

        $stopReason = static function () use ($signalled, $restartCache, $lastRestart, $settings, $elapsedMs): ?string {
            if ($signalled()) {
                return 'signal';
            }

            if ($restartCache !== null && Worker::$restartable && $restartCache->get(self::RESTART_CACHE_KEY) != $lastRestart) {
                return 'restart';
            }

            // A signal that arrived while the restart key was being read.
            if ($signalled()) {
                return 'signal';
            }

            if ($settings['max_time'] > 0 && $elapsedMs() >= $settings['max_time'] * 1000) {
                return 'max_time';
            }

            if ($settings['memory'] > 0 && memory_get_usage(true) / 1024 / 1024 >= $settings['memory']) {
                return 'memory';
            }

            return null;
        };

        $this->components->info(sprintf(
            '[%s] swarm:relay daemon started: lanes [%s], limit %d per lane per cycle, idle sleep %d-%d ms, max-time %s, memory %s.',
            $this->daemonTimestamp(),
            implode(', ', array_keys($drainers)),
            $limit,
            $settings['min_sleep_ms'],
            $settings['max_sleep_ms'],
            $settings['max_time'] > 0 ? $settings['max_time'].' s' : 'none',
            $settings['memory'] > 0 ? $settings['memory'].' MB' : 'none',
        ));

        $defaultStore = $config->get('cache.default');
        $defaultDriver = $config->get('cache.stores.'.(is_string($defaultStore) ? $defaultStore : '').'.driver');

        if (! Worker::$restartable) {
            $this->components->warn('Queue restarts are disabled (Worker::$restartable is false), so queue:restart will not stop this daemon. Stop it with SIGTERM through your process supervisor.');
        } elseif (in_array($defaultDriver, ['array', 'null'], true)) {
            $this->components->warn("The default cache store uses the [{$defaultDriver}] driver, which other processes cannot see, so queue:restart will not reach this daemon. Stop it with SIGTERM through your process supervisor.");
        }

        $sleepMs = $settings['min_sleep_ms'];
        $cycles = 0;

        while (true) {
            $cycles++;
            $batch = $this->emptyBatch();

            try {
                $this->drainBatch(
                    $drainers,
                    $overlap,
                    $batch,
                    fn (RelayLane $lane) => $this->components->warn("[{$this->daemonTimestamp()}] The [{$lane->value}] lane's command overlap lease ({$lane->leaseKey()}) is held, so that lane is skipped this cycle. Either another swarm:relay is draining it, or a relay that was killed mid-batch still holds it until swarm.commands.overlap.lease_seconds expires; see \"Command overlap leases\" in docs/maintenance.md to release it."),
                );
            } catch (Throwable $exception) {
                $totals = $this->emptyTotals();
                $this->addBatchToTotals($batch, $totals);

                $audit->emit('command.relay', [
                    ...$this->daemonCycleEvidence($selectedTypeValues, $limit, 1, $totals, $batch),
                    'status' => 'error',
                    'exception_class' => $exception::class,
                    ...$audit->metadata($actorMetadata),
                ]);

                throw $exception;
            }

            $this->reportDaemonCycle($batch, $cycles, $selectedTypeValues, $limit, $audit, $actorMetadata);

            // Only durable and callback work keeps the cadence short. Audit-lane
            // replays, transient failures and contention back off, so a failing sink
            // cannot hold the daemon at its floor replaying its own evidence.
            $sleepMs = $this->workRemoved($batch) > 0
                ? $settings['min_sleep_ms']
                : (int) min((float) $settings['max_sleep_ms'], ceil($sleepMs * $settings['backoff_multiplier']));

            if (($reason = $stopReason()) !== null) {
                break;
            }

            $wait = $sleepMs;

            if ($settings['max_time'] > 0) {
                $wait = (int) max(0, min($wait, ceil($settings['max_time'] * 1000 - $elapsedMs())));
            }

            // Sliced so a stop requested just before or during the wait is acted on
            // within one slice; the signal also interrupts the slice in progress.
            while ($wait > 0 && ! $signalled()) {
                $slice = min(self::DAEMON_SLEEP_SLICE_MS, $wait);
                Sleep::for($slice)->milliseconds();
                $wait -= $slice;
            }

            if (($reason = $stopReason()) !== null) {
                break;
            }
        }

        $this->components->info(sprintf(
            '[%s] swarm:relay daemon stopping after %d cycle%s: %s (ran %d s, memory %s MB).',
            $this->daemonTimestamp(),
            $cycles,
            $cycles === 1 ? '' : 's',
            $reason,
            (int) floor($elapsedMs() / 1000),
            number_format(memory_get_usage(true) / 1024 / 1024, 1),
        ));

        return self::SUCCESS;
    }

    /**
     * Report one daemon cycle. A cycle where the durable or callback lane did
     * work, or a lane was found held, prints its summary and emits one
     * command.relay event. A cycle whose only activity was in the audit lane
     * prints its summary but emits nothing; an idle cycle stays silent.
     *
     * @param  RelayBatch  $batch
     * @param  list<string>  $selectedTypeValues
     * @param  array<string, mixed>  $actorMetadata
     */
    private function reportDaemonCycle(array $batch, int $cycle, array $selectedTypeValues, int $limit, SwarmAuditDispatcher $audit, array $actorMetadata): void
    {
        $reportable = $this->workRemoved($batch) > 0 || $this->workFailed($batch) > 0 || $batch['contended'] !== [];

        if ($batch['results'] === []) {
            if ($reportable) {
                $audit->emit('command.relay', [
                    ...$this->daemonCycleEvidence($selectedTypeValues, $limit, 0, $this->emptyTotals(), $batch),
                    'status' => 'skipped_overlap',
                    ...$audit->metadata($actorMetadata),
                ]);
            }

            return;
        }

        $totals = $this->emptyTotals();
        $this->addBatchToTotals($batch, $totals);

        if ($this->totalsRemoved($totals) === 0 && $totals['failed'] === 0 && ! $reportable) {
            return;
        }

        if ($reportable) {
            $audit->emit('command.relay', [
                ...$this->daemonCycleEvidence($selectedTypeValues, $limit, 1, $totals, $batch),
                'status' => $this->totalsStatus($totals, $totals['failed'] > 0),
                ...$audit->metadata($actorMetadata),
            ]);
        }

        $failed = [];

        foreach ($batch['results'] as $lane => $result) {
            $failed[$lane] = $result->failed;
        }

        $this->line("[{$this->daemonTimestamp()}] swarm:relay daemon cycle {$cycle}:");
        $this->reportTotals($totals, $failed, null, 1);
    }

    /**
     * The command.relay evidence for one daemon cycle: it describes that cycle,
     * not the invocation, so it never drains until empty and has no attempt
     * budget. $attempts is 1, or 0 when every selected lane was held.
     *
     * @see docs/audit-evidence-contract.md (command.relay)
     *
     * @param  list<string>  $selectedTypeValues
     * @param  RelayTotals  $totals
     * @param  RelayBatch  $batch
     * @return array<string, mixed>
     */
    private function daemonCycleEvidence(array $selectedTypeValues, int $limit, int $attempts, array $totals, array $batch): array
    {
        return $this->relayEvidence($selectedTypeValues, $limit, false, null, $attempts, $totals, array_fill_keys($batch['contended'], true));
    }

    /**
     * The local time stamped on daemon console lines.
     */
    private function daemonTimestamp(): string
    {
        return Carbon::now()->format('Y-m-d H:i:s');
    }

    /**
     * Drain each given lane once, in order, under that lane's own lease.
     *
     * Results are written into $batch inside the guarded callback, before the
     * lease is released, and a held lane is recorded as soon as it is seen, so a
     * later exception (including a failed lease release) leaves everything
     * already done in $batch for the caller to report.
     *
     * @param  array<string, Closure(): (DrainResult|AuditDrainResult|CallbackDrainResult)>  $drainers
     * @param  RelayBatch  $batch
     * @param  Closure(RelayLane): void  $onContended
     */
    private function drainBatch(array $drainers, CommandOverlapGuard $overlap, array &$batch, Closure $onContended): void
    {
        foreach ($drainers as $laneValue => $drain) {
            $lane = RelayLane::from($laneValue);

            $result = $overlap->run($lane->leaseKey(), static function () use ($drain, $laneValue, &$batch): int {
                $batch['results'][$laneValue] = $drain();

                return self::SUCCESS;
            });

            if ($result === null) {
                $batch['contended'][] = $laneValue;
                $onContended($lane);
            }
        }
    }

    /**
     * @return RelayBatch
     */
    private function emptyBatch(): array
    {
        return ['results' => [], 'contended' => []];
    }

    /**
     * @return RelayTotals
     */
    private function emptyTotals(): array
    {
        return [
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
            'claimed' => 0,
            'reclaimed' => 0,
            'reclaimed_direct' => 0,
            'audit_replayed' => 0,
            'audit_dead_lettered' => 0,
            'callback_dispatched' => 0,
            'callback_dead_lettered' => 0,
        ];
    }

    /**
     * @param  RelayBatch  $batch
     * @param  RelayTotals  $totals
     */
    private function addBatchToTotals(array $batch, array &$totals): void
    {
        foreach ($batch['results'] as $result) {
            $totals['failed'] += $result->failed;
            $totals['claimed'] += $result->claimed;
            $totals['reclaimed'] += $result->reclaimed;

            if ($result instanceof DrainResult) {
                $totals['dispatched'] += $result->dispatched;
                $totals['skipped'] += $result->skipped;
                $totals['reclaimed_direct'] += $result->reclaimedDirect;
            } elseif ($result instanceof AuditDrainResult) {
                $totals['audit_replayed'] += $result->replayed;
                $totals['audit_dead_lettered'] += $result->deadLettered;
            } else {
                $totals['callback_dispatched'] += $result->dispatched;
                $totals['callback_dead_lettered'] += $result->deadLettered;
            }
        }
    }

    /**
     * Rows removed from any lane's outbox in this batch.
     *
     * @param  RelayBatch  $batch
     */
    private function batchRemoved(array $batch): int
    {
        return array_sum(array_map(static fn (DrainResult|AuditDrainResult|CallbackDrainResult $result): int => $result->total(), $batch['results']));
    }

    /**
     * @param  RelayBatch  $batch
     */
    private function batchFailed(array $batch): int
    {
        return array_sum(array_map(static fn (DrainResult|AuditDrainResult|CallbackDrainResult $result): int => $result->failed, $batch['results']));
    }

    /**
     * Rows removed from the durable and callback outboxes — the work that keeps
     * the daemon on its short cadence. The audit lane is deliberately excluded.
     *
     * @param  RelayBatch  $batch
     */
    private function workRemoved(array $batch): int
    {
        return array_sum(array_map(static fn (DrainResult|AuditDrainResult|CallbackDrainResult $result): int => $result->total(), $this->workResults($batch)));
    }

    /**
     * Transient failures in the durable and callback outboxes in this batch.
     *
     * @param  RelayBatch  $batch
     */
    private function workFailed(array $batch): int
    {
        return array_sum(array_map(static fn (DrainResult|AuditDrainResult|CallbackDrainResult $result): int => $result->failed, $this->workResults($batch)));
    }

    /**
     * @param  RelayBatch  $batch
     * @return array<string, DrainResult|AuditDrainResult|CallbackDrainResult>
     */
    private function workResults(array $batch): array
    {
        return array_intersect_key($batch['results'], array_flip(self::DAEMON_WORK_LANES));
    }

    /**
     * @param  RelayTotals  $totals
     */
    private function totalsRemoved(array $totals): int
    {
        return $totals['dispatched'] + $totals['skipped'] + $totals['audit_replayed'] + $totals['audit_dead_lettered'] + $totals['callback_dispatched'] + $totals['callback_dead_lettered'];
    }

    /**
     * @param  RelayTotals  $totals
     */
    private function totalsStatus(array $totals, bool $hasUnresolvedTransient): string
    {
        return $this->auditStatus(
            $totals['dispatched'] + $totals['audit_replayed'] + $totals['callback_dispatched'],
            $totals['skipped'] + $totals['audit_dead_lettered'] + $totals['callback_dead_lettered'],
            $hasUnresolvedTransient,
        );
    }

    /**
     * The frozen command.relay evidence fields, without status.
     *
     * @param  list<string>  $selectedTypeValues
     * @param  RelayTotals  $totals
     * @param  array<string, true>  $contended
     * @return array<string, mixed>
     */
    private function relayEvidence(array $selectedTypeValues, int $limit, bool $drainUntilEmpty, ?int $maxAttempts, int $attempts, array $totals, array $contended): array
    {
        return [
            'types' => $selectedTypeValues,
            'limit' => $limit,
            'drain_until_empty' => $drainUntilEmpty,
            'max_attempts' => $maxAttempts,
            'attempts' => $attempts,
            'dispatched_count' => $totals['dispatched'],
            'skipped_count' => $totals['skipped'],
            'failed_count' => $totals['failed'],
            'claimed_count' => $totals['claimed'],
            'reclaimed_count' => $totals['reclaimed'],
            'direct_reclaimed_count' => $totals['reclaimed_direct'],
            'audit_replayed_count' => $totals['audit_replayed'],
            'audit_dead_lettered_count' => $totals['audit_dead_lettered'],
            'callback_dispatched_count' => $totals['callback_dispatched'],
            'callback_dead_lettered_count' => $totals['callback_dead_lettered'],
            'contended_lanes' => $this->contendedLanes($contended),
        ];
    }

    /**
     * Print the summary lines for drained totals.
     *
     * @param  RelayTotals  $totals
     * @param  array<string, int>  $lastFailed  each lane's transient failures in its last drained batch
     */
    private function reportTotals(array $totals, array $lastFailed, ?int $maxAttempts, int $attempts): void
    {
        if ($totals['dispatched'] > 0) {
            $this->components->info('Dispatched '.$totals['dispatched'].' outbox entr'.($totals['dispatched'] === 1 ? 'y' : 'ies').'.');
        }

        if ($totals['audit_replayed'] > 0) {
            $this->components->info('Replayed '.$totals['audit_replayed'].' audit record'.($totals['audit_replayed'] === 1 ? '' : 's').'.');
        }

        if ($totals['callback_dispatched'] > 0) {
            $this->components->info('Dispatched '.$totals['callback_dispatched'].' terminal callback deliver'.($totals['callback_dispatched'] === 1 ? 'y' : 'ies').'.');
        }

        if ($totals['callback_dead_lettered'] > 0) {
            $this->components->warn('Dead-lettered '.$totals['callback_dead_lettered'].' terminal callback'.($totals['callback_dead_lettered'] === 1 ? '' : 's').' that exceeded swarm.callbacks.max_attempts.');
        }

        if ($totals['audit_dead_lettered'] > 0) {
            $this->components->warn('Dead-lettered '.$totals['audit_dead_lettered'].' audit record'.($totals['audit_dead_lettered'] === 1 ? '' : 's').' that exceeded swarm.audit.outbox.max_attempts.');
        }

        if ($totals['skipped'] > 0) {
            $this->components->warn('Skipped '.$totals['skipped'].' invalid outbox entr'.($totals['skipped'] === 1 ? 'y' : 'ies').'. Check your error tracker for details.');
        }

        $legacyStuck = ($lastFailed[RelayLane::Durable->value] ?? 0) + ($lastFailed[RelayLane::Audit->value] ?? 0);
        $callbackStuck = $lastFailed[RelayLane::Callback->value] ?? 0;

        if ($legacyStuck > 0) {
            $this->components->warn(
                $legacyStuck.' outbox entr'.($legacyStuck === 1 ? 'y' : 'ies').' could not be dispatched due to a transient error'
                .($maxAttempts !== null ? ' after '.$attempts.' attempt'.($attempts === 1 ? '' : 's') : '')
                .'. The '.($legacyStuck === 1 ? 'entry' : 'entries').' will be re-claimed after the reservation timeout.'
                .' Check your error tracker and queue driver.'
            );
        }

        if ($callbackStuck > 0) {
            $this->components->warn(
                $callbackStuck.' callback deliver'.($callbackStuck === 1 ? 'y' : 'ies').' could not be dispatched due to a transient error'
                .($maxAttempts !== null ? ' after '.$attempts.' attempt'.($attempts === 1 ? '' : 's') : '')
                .'. The '.($callbackStuck === 1 ? 'delivery remains' : 'deliveries remain').' eligible according to the callback retry delay or delivery lease timeout.'
                .' Check your error tracker and queue driver.'
            );
        }
    }

    /**
     * Validate every daemon option and setting before anything is drained.
     *
     * @return array{min_sleep_ms: int, max_sleep_ms: int, backoff_multiplier: float, max_time: int, memory: int}|false
     *                                                                                                                  false signals a validation failure
     */
    private function resolveDaemonSettings(ConfigRepository $config): array|false
    {
        if (! $this->supportsAsyncSignals()) {
            $this->components->error('swarm:relay --daemon requires the pcntl extension so SIGTERM and SIGINT can stop it between drains without abandoning its lane leases. Schedule swarm:relay instead.');

            return false;
        }

        $maxTime = $this->resolveDaemonLimit('max-time', 0);
        $memory = $this->resolveDaemonLimit('memory', 128);

        if ($maxTime === false || $memory === false) {
            return false;
        }

        $min = $this->resolveSleepSetting($config, 'min_sleep_ms', 100, 1);
        $max = $this->resolveSleepSetting($config, 'max_sleep_ms', 3000, is_int($min) ? $min : 1);

        if ($min === false || $max === false) {
            return false;
        }

        $rawMultiplier = $config->get('swarm.durable.relay.daemon.backoff_multiplier', 2.0);
        $multiplier = is_int($rawMultiplier) || is_float($rawMultiplier) || (is_string($rawMultiplier) && is_numeric($rawMultiplier))
            ? (float) $rawMultiplier
            : null;

        if ($multiplier === null || ! is_finite($multiplier) || $multiplier < 1.0 || $multiplier > 10.0) {
            $this->components->error('[swarm.durable.relay.daemon.backoff_multiplier] must be a finite number from 1 to 10.');

            return false;
        }

        return [
            'min_sleep_ms' => $min,
            'max_sleep_ms' => $max,
            'backoff_multiplier' => $multiplier,
            'max_time' => $maxTime,
            'memory' => $memory,
        ];
    }

    /**
     * A daemon-only limit option: a whole number, 0 meaning no limit.
     */
    private function resolveDaemonLimit(string $option, int $default): int|false
    {
        $raw = $this->option($option);

        if ($raw === null) {
            return $default;
        }

        // A string from the command line, or an int from Artisan::call() and the scheduler.
        $value = is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;

        if ($value === false) {
            $this->components->error("--{$option} must be a whole number of ".($option === 'memory' ? 'megabytes' : 'seconds').' (0 for no limit).');

            return false;
        }

        return $value;
    }

    /**
     * An idle-sleep bound: a whole number of milliseconds within [$floor, 60000].
     */
    private function resolveSleepSetting(ConfigRepository $config, string $key, int $default, int $floor): int|false
    {
        $raw = $config->get("swarm.durable.relay.daemon.{$key}", $default);

        $value = is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $floor, 'max_range' => self::DAEMON_MAX_SLEEP_CEILING_MS]])
            : false;

        if ($value === false) {
            $this->components->error("[swarm.durable.relay.daemon.{$key}] must be a whole number of milliseconds from {$floor} to ".self::DAEMON_MAX_SLEEP_CEILING_MS.'.');

            return false;
        }

        return $value;
    }

    /**
     * Whether stop signals can be trapped (queue:work's check of the same name).
     *
     * @internal A test seam, not an extension point.
     */
    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * The lanes this run found held by another invocation, in lane order.
     *
     * @param  array<string, true>  $contended
     * @return list<string>
     */
    private function contendedLanes(array $contended): array
    {
        return array_values(array_filter(
            array_column(RelayLane::cases(), 'value'),
            static fn (string $lane): bool => isset($contended[$lane]),
        ));
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
