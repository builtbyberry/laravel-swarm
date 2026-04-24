<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Facades;

use BuiltByBerry\LaravelSwarm\Support\SwarmHistory as SwarmHistoryService;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin SwarmHistoryService
 */
class SwarmHistory extends Facade
{
    /**
     * @return class-string<SwarmHistoryService>
     */
    protected static function getFacadeAccessor(): string
    {
        return SwarmHistoryService::class;
    }
}
