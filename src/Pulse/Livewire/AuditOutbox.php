<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Livewire;

use BuiltByBerry\LaravelSwarm\Contracts\AuditOutbox as AuditOutboxContract;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * @internal
 */
#[Lazy]
class AuditOutbox extends Card
{
    public function render(): Renderable
    {
        $outbox = app(AuditOutboxContract::class);

        if (! $outbox->isAvailable()) {
            return View::make('swarm::pulse.livewire.audit-outbox', [
                'available' => false,
                'snapshot' => null,
            ]);
        }

        $snapshot = $this->resolveSnapshot();

        return View::make('swarm::pulse.livewire.audit-outbox', [
            'available' => true,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * @return object{
     *     pending: int,
     *     stalePending: int,
     *     deadLetter: int,
     *     oldestPendingAge: ?string,
     *     oldestDeadLetterAge: ?string,
     *     retentionDays: ?int,
     *     staleWindowSeconds: int,
     * }
     */
    protected function resolveSnapshot(): object
    {
        $config = app(ConfigRepository::class);
        $connection = DB::connection();

        $outboxTable = (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');
        $reservationTimeoutSeconds = (int) $config->get('swarm.durable.relay.reservation_timeout_seconds', 60);
        $retentionDays = $config->get('swarm.audit.outbox.dead_letter_retention_days');
        $retentionDays = is_int($retentionDays) && $retentionDays > 0 ? $retentionDays : null;

        $now = Carbon::now('UTC');
        $staleWindowSeconds = $reservationTimeoutSeconds * 2;
        $staleThreshold = $now->copy()->subSeconds($staleWindowSeconds);

        $pending = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'pending')
            ->count();

        $stalePending = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'pending')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $staleThreshold)
            ->count();

        $deadLetter = $this->outboxQuery($connection, $outboxTable)
            ->where('status', 'dead_letter')
            ->count();

        return (object) [
            'pending' => $pending,
            'stalePending' => $stalePending,
            'deadLetter' => $deadLetter,
            'oldestPendingAge' => $this->oldestAge($connection, $outboxTable, 'pending', $now),
            'oldestDeadLetterAge' => $this->oldestAge($connection, $outboxTable, 'dead_letter', $now),
            'retentionDays' => $retentionDays,
            'staleWindowSeconds' => $staleWindowSeconds,
        ];
    }

    protected function oldestAge(ConnectionInterface $connection, string $outboxTable, string $status, Carbon $now): ?string
    {
        $row = $this->outboxQuery($connection, $outboxTable)
            ->where('status', $status)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['created_at']);

        if ($row === null) {
            return null;
        }

        return Carbon::parse($row->created_at, 'UTC')->diffForHumans($now, [
            'parts' => 2,
            'short' => true,
            'syntax' => Carbon::DIFF_ABSOLUTE,
        ]);
    }

    protected function outboxQuery(ConnectionInterface $connection, string $outboxTable): Builder
    {
        return $connection->table($outboxTable);
    }
}
