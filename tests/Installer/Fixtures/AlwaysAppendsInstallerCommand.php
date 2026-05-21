<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures;

use BuiltByBerry\LaravelSwarm\Tests\Installer\DoubleRunResult;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Deliberately non-idempotent installer fixture.
 *
 * Every invocation appends a fresh unique line to `.env`. There is no marker
 * check and no `--force` flag — re-running always mutates disk. Used to
 * prove that {@see DoubleRunResult::assertSecondRunIsNoOp()}
 * fails loudly against a real misbehaving installer (not just a test-side
 * file mutation).
 */
final class AlwaysAppendsInstallerCommand extends Command
{
    protected $signature = 'swarm-fixture:always-appends';

    protected $description = 'Test fixture installer that is intentionally non-idempotent.';

    public function handle(Filesystem $files): int
    {
        $envPath = $this->laravel->basePath('.env');
        $env = $files->exists($envPath) ? (string) $files->get($envPath) : '';

        if (! str_ends_with($env, "\n") && $env !== '') {
            $env .= "\n";
        }

        $env .= 'ALWAYS_APPENDS_'.bin2hex(random_bytes(4)).'=1'."\n";
        $files->put($envPath, $env);

        return 0;
    }
}
