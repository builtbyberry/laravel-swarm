<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
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
                            {--drain-until-empty : Keep draining in a loop until no entries remain.}';

    protected $description = 'Drain the durable swarm outbox and dispatch pending jobs';

    protected $help = <<<'HELP'
        This command drains the swarm_durable_outbox table and dispatches the
        corresponding queue jobs. It must be scheduled to run regularly so that
        durable runs can advance:

          Schedule::command('swarm:relay')->everyMinute();

        Without the relay, durable runs will stall permanently after writing to
        the outbox. Use --drain-until-empty to clear backlogs in a single invocation.

        Examples:
          php artisan swarm:relay
          php artisan swarm:relay --type=step --type=branch
          php artisan swarm:relay --limit=500 --drain-until-empty
        HELP;

    public function handle(DurableOutbox $outbox, SwarmAuditDispatcher $audit, ConfigRepository $config): int
    {
        $types = $this->resolveTypes();

        if ($types === false) {
            return self::FAILURE;
        }

        $limit = $this->resolveLimit($config);
        $drainUntilEmpty = (bool) $this->option('drain-until-empty');

        $total = 0;

        try {
            do {
                $count = $outbox->drain($types, $limit);
                $total += $count;
            } while ($drainUntilEmpty && $count > 0);
        } catch (Throwable $exception) {
            $audit->emit('command.relay', [
                'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
                'limit' => $limit,
                'drain_until_empty' => $drainUntilEmpty,
                'actor' => 'artisan',
                'status' => 'failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $audit->emit('command.relay', [
            'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
            'limit' => $limit,
            'drain_until_empty' => $drainUntilEmpty,
            'actor' => 'artisan',
            'dispatched_count' => $total,
            'status' => $total > 0 ? 'dispatched' : 'none_found',
        ]);

        if ($total === 0) {
            $this->components->info('No pending outbox entries were found.');

            return self::SUCCESS;
        }

        $this->components->info("Dispatched {$total} outbox entr".($total === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    /**
     * @return array<OutboxDispatchType>|false  false signals a validation failure
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
}
