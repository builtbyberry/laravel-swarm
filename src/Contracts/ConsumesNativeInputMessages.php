<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Contracts;

use Closure;

interface ConsumesNativeInputMessages
{
    /** @param list<string> $configurationIds */
    public function consumeMessages(string $id, string $runId, array $configurationIds): void;

    /**
     * Execute the terminal history write and message consumption in one database transaction.
     *
     * @template TCallbackReturnType
     *
     * @param  Closure(): TCallbackReturnType  $callback
     * @return TCallbackReturnType
     */
    public function transaction(Closure $callback): mixed;
}
