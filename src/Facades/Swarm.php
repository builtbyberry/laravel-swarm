<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Facades;

use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin SwarmRunner
 */
class Swarm extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return class-string<SwarmRunner>
     */
    protected static function getFacadeAccessor(): string
    {
        return SwarmRunner::class;
    }
}
