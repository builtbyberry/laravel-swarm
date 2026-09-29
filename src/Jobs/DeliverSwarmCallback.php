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
 * The job carries ONLY the outbox row id — never the sealed closure, which stays at
 * rest in swarm_callback_deliveries. It hands the id straight to the outbox, which
 * owns unsealing, signature verification, invocation, and the row's terminal fate
 * (delete on success; increment/dead-letter on failure).
 *
 * `tries = 1`: the outbox reservation + attempt counter own retry, so the queue does
 * not add a second, competing retry mechanism. Delivery is at-least-once — a callback
 * must be idempotent.
 *
 * @internal
 */
class DeliverSwarmCallback implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $id) {}

    public function handle(CallbackDeliveryOutbox $outbox): void
    {
        $outbox->deliver($this->id);
    }
}
