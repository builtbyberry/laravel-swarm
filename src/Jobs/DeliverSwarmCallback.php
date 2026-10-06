<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\CallbackDeliveryOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single terminal workflow callback claimed by `swarm:relay --type=callback`.
 *
 * The job carries ONLY the outbox row id and opaque claim token — never the sealed
 * closure — and hands them to {@see CallbackDeliveryOutbox::deliver()}.
 *
 * `tries = 1`: the outbox lease and delivery-acquisition attempt counter own retry, so the
 * queue does not add a second, competing retry mechanism.
 *
 * @internal
 */
class DeliverSwarmCallback implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $id, public string $claimToken) {}

    public function handle(CallbackDeliveryOutbox $outbox): void
    {
        $outbox->deliver($this->id, $this->claimToken);
    }
}
