<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Foundation\Application;
use Throwable;

abstract class ProcessConcurrencyTestCase extends TestCase
{
    private static ?bool $processDriverAvailable = null;

    private static string $processDriverUnavailableReason = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('concurrency.default', 'process');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Process concurrency requires proc_open (disabled or unavailable in this PHP build).');

            return;
        }

        if (self::$processDriverAvailable === null) {
            try {
                /** @var ConcurrencyManager $concurrency */
                $concurrency = $this->app->make(ConcurrencyManager::class);
                $results = $concurrency->driver('process')->run([fn (): int => 42]);
                $ok = ($results[0] ?? null) === 42;
                self::$processDriverAvailable = $ok;
                if (! $ok) {
                    self::$processDriverUnavailableReason = 'process driver did not return expected probe result.';
                }
            } catch (Throwable $e) {
                self::$processDriverAvailable = false;
                self::$processDriverUnavailableReason = $e->getMessage();
            }
        }

        if (self::$processDriverAvailable === false) {
            $this->markTestSkipped('Process concurrency driver unavailable: '.self::$processDriverUnavailableReason);
        }
    }
}
