<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:relay')]
class SwarmRelayCommand extends Command
{
    protected $signature = 'swarm:relay
                            {--type=* : Dispatch types to relay (step, branch, queued_resume). Defaults to all.}
                            {--limit=100 : Maximum number of outbox entries to drain per run.}';

    protected $description = 'Drain the durable swarm outbox and dispatch pending jobs';

    public function handle(DurableOutbox $outbox, SwarmAuditDispatcher $audit): int
    {
        $types = $this->resolveTypes();
        $limit = max(1, (int) $this->option('limit'));

        try {
            $count = $outbox->drain($types, $limit);
        } catch (Throwable $exception) {
            $audit->emit('command.relay', [
                'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
                'limit' => $limit,
                'actor' => 'artisan',
                'status' => 'failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $audit->emit('command.relay', [
            'types' => array_map(static fn (OutboxDispatchType $t): string => $t->value, $types),
            'limit' => $limit,
            'actor' => 'artisan',
            'dispatched_count' => $count,
            'status' => $count > 0 ? 'dispatched' : 'none_found',
        ]);

        if ($count === 0) {
            $this->components->info('No pending outbox entries were found.');

            return self::SUCCESS;
        }

        $this->components->info("Dispatched {$count} outbox entr".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    /**
     * @return array<OutboxDispatchType>
     */
    protected function resolveTypes(): array
    {
        $raw = (array) $this->option('type');
        $raw = array_filter($raw, static fn (mixed $v): bool => is_string($v) && $v !== '');

        if ($raw === []) {
            return [];
        }

        return array_values(array_map(
            static fn (string $value): OutboxDispatchType => OutboxDispatchType::from($value),
            array_values($raw),
        ));
    }
}
