<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Responses\DrainResult;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:relay')]
class SwarmRelayCommand extends Command
{
    protected $signature = 'swarm:relay
                            {--type=* : Dispatch types to relay (step, branch, queued_resume). Defaults to all.}
                            {--limit= : Maximum number of outbox entries to drain per invocation (overrides config; capped at 10,000).}
                            {--drain-until-empty : Keep draining in a loop until no entries remain.}
                            {--max-attempts= : Maximum drain iterations when using --drain-until-empty. Includes retrying transient failures. Must be >= 1.}';

    protected $description = 'Drain the durable swarm outbox and dispatch pending jobs';

    protected $help = <<<'HELP'
        This command drains the swarm_durable_outbox table and dispatches the
        corresponding queue jobs. It must be scheduled to run regularly so that
        durable runs can advance:

          Schedule::command('swarm:relay')->everyMinute();

        Without the relay, durable runs will stall permanently after writing to
        the outbox. Use --drain-until-empty to clear backlogs in a single invocation.

        EXIT CODES
          0 (success)  All claimed entries were dispatched or permanently removed.
          1 (failure)  One or more entries could not be dispatched due to a transient
                       error (queue driver unavailable, etc.). The entries remain in the
                       outbox and will be re-claimed after the reservation timeout. Check
                       your error tracker and queue driver health.

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
          php artisan swarm:relay --limit=500 --drain-until-empty
          php artisan swarm:relay --drain-until-empty --max-attempts=10
        HELP;

    public function handle(DurableOutbox $outbox, SwarmAuditDispatcher $audit, ConfigRepository $config): int
    {
        $types = $this->resolveTypes();

        if ($types === false) {
            return self::FAILURE;
        }

        $limit = $this->resolveLimit($config);
        $drainUntilEmpty = (bool) $this->option('drain-until-empty');
        $maxAttempts = $this->resolveMaxAttempts();

        if ($maxAttempts !== null && ! $drainUntilEmpty) {
            $this->components->warn('--max-attempts has no effect without --drain-until-empty.');
        }

        $totalDispatched = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $attempts = 0;
        $result = null;

        try {
            do {
                $attempts++;
                $result = $outbox->drain($types, $limit);
                $totalDispatched += $result->dispatched;
                $totalSkipped += $result->skipped;
                $totalFailed += $result->failed;

                $madeProgress = $result->total() > 0;
                // Only retry transient failures when --max-attempts gives a finite budget.
                // Without it the loop would spin forever during a sustained queue outage.
                $shouldRetryTransient = $result->failed > 0 && $maxAttempts !== null && $attempts < $maxAttempts;

            } while ($drainUntilEmpty && ($madeProgress || $shouldRetryTransient));

        } catch (Throwable $exception) {
            $audit->emit('command.relay', [
                'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
                'limit' => $limit,
                'drain_until_empty' => $drainUntilEmpty,
                'max_attempts' => $maxAttempts,
                'attempts' => $attempts,
                'actor' => 'artisan',
                'dispatched_count' => $totalDispatched,
                'skipped_count' => $totalSkipped,
                'failed_count' => $totalFailed,
                'status' => 'error',
            ]);

            throw $exception;
        }

        $hasUnresolvedTransient = $result !== null && $result->failed > 0;

        $audit->emit('command.relay', [
            'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
            'limit' => $limit,
            'drain_until_empty' => $drainUntilEmpty,
            'max_attempts' => $maxAttempts,
            'attempts' => $attempts,
            'actor' => 'artisan',
            'dispatched_count' => $totalDispatched,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
            'status' => $this->auditStatus($totalDispatched, $totalSkipped, $hasUnresolvedTransient),
        ]);

        if ($totalDispatched === 0 && $totalSkipped === 0 && $totalFailed === 0) {
            $this->components->info('No pending outbox entries were found.');

            return self::SUCCESS;
        }

        if ($totalDispatched > 0) {
            $this->components->info('Dispatched '.$totalDispatched.' outbox entr'.($totalDispatched === 1 ? 'y' : 'ies').'.');
        }

        if ($totalSkipped > 0) {
            $this->components->warn('Skipped '.$totalSkipped.' invalid outbox entr'.($totalSkipped === 1 ? 'y' : 'ies').'. Check your error tracker for details.');
        }

        if ($hasUnresolvedTransient) {
            $stuck = $result->failed;
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
     * @return array<OutboxDispatchType>|false false signals a validation failure
     */
    protected function resolveTypes(): array|false
    {
        $raw = (array) $this->option('type');
        $raw = array_filter($raw, static fn (mixed $v): bool => is_string($v) && $v !== '');

        if ($raw === []) {
            return [];
        }

        $resolved = [];

        foreach (array_values($raw) as $value) {
            $type = OutboxDispatchType::tryFrom($value);

            if ($type === null) {
                $valid = implode(', ', array_column(OutboxDispatchType::cases(), 'value'));
                $this->components->error("Unknown dispatch type [{$value}]. Valid types: {$valid}.");

                return false;
            }

            $resolved[] = $type;
        }

        return $resolved;
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
