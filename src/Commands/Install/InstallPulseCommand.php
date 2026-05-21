<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Laravel\Pulse\Pulse;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;

/**
 * Installer for the Laravel Pulse integration.
 *
 * Detects Pulse (via class_exists() — not composer), refuses gracefully if
 * absent, then performs two idempotent file edits with .bak backups:
 *
 *   1. Registers the SwarmRuns + SwarmStepDurations recorders in
 *      config/pulse.php inside a sentinel-fenced managed block.
 *   2. Injects the requested livewire cards into
 *      resources/views/vendor/pulse/dashboard.blade.php inside a
 *      sentinel-fenced managed block.
 *
 * Re-runs detect the managed blocks and skip both edits. Use --force to
 * rewrite even when the markers are present.
 *
 * @see docs/pulse.md
 */
#[AsCommand(name: 'swarm:install:pulse')]
class InstallPulseCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'swarm:install:pulse
        {--cards= : Comma-separated card list (runs,steps,audit-outbox). Defaults to all available.}
        {--force : Rewrite even when the swarm:install:pulse managed blocks are already present.}';

    /**
     * @var string
     */
    protected $description = 'Wire up the Laravel Swarm Pulse recorders and dashboard cards.';

    /**
     * Sentinel for the managed recorders block inside config/pulse.php.
     */
    private const RECORDERS_BEGIN = '// swarm:install:pulse recorders — managed (do not edit between markers)';

    private const RECORDERS_END = '// end swarm:install:pulse recorders';

    /**
     * Sentinel for the managed cards block inside the dashboard view.
     */
    private const CARDS_BEGIN = '{{-- swarm:install:pulse cards — managed (do not edit between markers) --}}';

    private const CARDS_END = '{{-- end swarm:install:pulse cards --}}';

    /**
     * Canonical list of cards this installer can register, keyed by short
     * name (the value used in the --cards flag) to its livewire tag.
     *
     * @var array<string, string>
     */
    private const AVAILABLE_CARDS = [
        'runs' => '<livewire:swarm.runs cols="6" />',
        'steps' => '<livewire:swarm.steps cols="6" />',
        'audit-outbox' => '<livewire:swarm.audit-outbox cols="6" />',
    ];

    public function handle(Filesystem $files): int
    {
        if (! $this->pulseIsInstalled()) {
            $this->error('Laravel Pulse is not installed.');
            $this->line('');
            $this->line('Install Pulse first, then re-run this command:');
            $this->line('');
            $this->line('  composer require laravel/pulse');
            $this->line('  php artisan vendor:publish --provider="Laravel\\Pulse\\PulseServiceProvider"');
            $this->line('  php artisan migrate');
            $this->line('');

            return self::FAILURE;
        }

        $base = $this->laravel->basePath();
        $configPath = $base.'/config/pulse.php';
        $force = (bool) $this->option('force');

        if (! $files->exists($configPath)) {
            $this->error('config/pulse.php was not found.');
            $this->line('');
            $this->line('Publish the Pulse config first, then re-run this command:');
            $this->line('');
            $this->line('  php artisan vendor:publish --provider="Laravel\\Pulse\\PulseServiceProvider" --tag=pulse-config');
            $this->line('');

            return self::FAILURE;
        }

        $cards = $this->resolveCards();
        if ($cards === null) {
            // resolveCards() already emitted a clear error.
            return self::FAILURE;
        }

        $recordersUpdated = $this->editConfig($files, $configPath, $force);
        $cardsUpdated = $this->editDashboard($files, $base, $cards, $force);

        if (! $recordersUpdated && ! $cardsUpdated) {
            $this->info('Pulse integration is already installed. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Pulse integration installed.');
        $this->line('Open `/'.config('pulse.path', 'pulse').'` to see the swarm cards.');

        return self::SUCCESS;
    }

    /**
     * Detect Pulse via class_exists (not composer show — class_exists is
     * cheaper, works under PSR-4 dev installs, and matches the runtime
     * detection used everywhere else in the package).
     *
     * `protected` so installer tests can simulate the Pulse-absent path by
     * extending the command and returning false; in production the package's
     * autoloader settles this.
     */
    protected function pulseIsInstalled(): bool
    {
        return class_exists(Pulse::class);
    }

    /**
     * Resolve the cards the operator wants enabled.
     *
     * - --cards=a,b,c always wins (also satisfies --no-interaction).
     * - In an interactive TTY with no flag, prompt the operator.
     * - In non-interactive mode with no flag, default to all available cards.
     *
     * Returns null on validation failure (after emitting an error to the
     * console) so the caller can short-circuit with FAILURE.
     *
     * @return list<string>|null
     */
    private function resolveCards(): ?array
    {
        $flag = $this->option('cards');

        if (is_string($flag) && $flag !== '') {
            $requested = array_values(array_filter(array_map('trim', explode(',', $flag))));
            $invalid = array_diff($requested, array_keys(self::AVAILABLE_CARDS));
            if ($invalid !== []) {
                $this->error('Unknown card(s): '.implode(', ', $invalid));
                $this->line('Available: '.implode(', ', array_keys(self::AVAILABLE_CARDS)));

                return null;
            }

            return $requested;
        }

        if (! $this->input->isInteractive()) {
            return array_keys(self::AVAILABLE_CARDS);
        }

        /** @var array<int, string> $chosen */
        $chosen = multiselect(
            label: 'Which Pulse cards should be enabled?',
            options: [
                'runs' => 'swarm.runs — per-swarm totals, failures, durations, topology mix',
                'steps' => 'swarm.steps — slowest average step durations by agent',
                'audit-outbox' => 'swarm.audit-outbox — live state of the audit outbox',
            ],
            default: array_keys(self::AVAILABLE_CARDS),
            hint: 'Skip audit-outbox if you do not run the audit outbox.',
        );

        if ($chosen === []) {
            $this->warn('No cards selected. The recorders will still be registered.');
        }

        return array_values($chosen);
    }

    /**
     * Inject the managed recorders block into config/pulse.php.
     *
     * Returns true if the file was modified (and a .bak written), false if
     * the managed block already exists and --force was not passed.
     */
    private function editConfig(Filesystem $files, string $configPath, bool $force): bool
    {
        $contents = (string) $files->get($configPath);

        $hasMarker = str_contains($contents, self::RECORDERS_BEGIN);
        if ($hasMarker && ! $force) {
            $this->line('config/pulse.php — managed recorders block already present, skipping.');

            return false;
        }

        $managedBlock = $this->buildRecordersBlock();

        if ($hasMarker) {
            // --force: replace the existing managed block in place.
            $pattern = '/'.preg_quote(self::RECORDERS_BEGIN, '/').'.*?'.preg_quote(self::RECORDERS_END, '/').'/s';
            $rewritten = preg_replace($pattern, $managedBlock, $contents, 1);
            if ($rewritten === null || $rewritten === $contents) {
                $this->error('Failed to rewrite managed recorders block in config/pulse.php.');

                return false;
            }
        } else {
            $rewritten = $this->insertIntoRecordersArray($contents, $managedBlock);
            if ($rewritten === null) {
                $this->error("Could not locate the 'recorders' array in config/pulse.php. Edit the file by hand and add:");
                $this->line('');
                $this->line($managedBlock);

                return false;
            }
        }

        $this->backup($files, $configPath);
        $files->put($configPath, $rewritten);
        $this->line('Registered SwarmRuns + SwarmStepDurations in config/pulse.php.');

        return true;
    }

    /**
     * Inject the managed cards block into the dashboard view, publishing the
     * stock Pulse dashboard first if absent.
     *
     * Returns true if the file was modified (and a .bak written, when an
     * older copy existed), false if the managed block is already present and
     * --force was not passed.
     *
     * @param  list<string>  $cards
     */
    private function editDashboard(Filesystem $files, string $base, array $cards, bool $force): bool
    {
        $dashboardPath = $base.'/resources/views/vendor/pulse/dashboard.blade.php';

        if (! $files->exists($dashboardPath)) {
            $this->publishDefaultDashboard($files, $dashboardPath);
        }

        $contents = (string) $files->get($dashboardPath);

        $hasMarker = str_contains($contents, self::CARDS_BEGIN);
        if ($hasMarker && ! $force) {
            $this->line('Pulse dashboard — managed cards block already present, skipping.');

            return false;
        }

        $managedBlock = $this->buildCardsBlock($cards);

        if ($hasMarker) {
            $pattern = '/'.preg_quote(self::CARDS_BEGIN, '/').'.*?'.preg_quote(self::CARDS_END, '/').'/s';
            $rewritten = preg_replace($pattern, $managedBlock, $contents, 1);
            if ($rewritten === null || $rewritten === $contents) {
                $this->error('Failed to rewrite managed cards block in the Pulse dashboard view.');

                return false;
            }
        } else {
            $rewritten = $this->insertIntoDashboard($contents, $managedBlock);
            if ($rewritten === null) {
                $this->error('Could not locate the <x-pulse> wrapper in the Pulse dashboard view. Edit the file by hand and add:');
                $this->line('');
                $this->line($managedBlock);

                return false;
            }
        }

        $this->backup($files, $dashboardPath);
        $files->put($dashboardPath, $rewritten);
        $this->line('Injected swarm cards into resources/views/vendor/pulse/dashboard.blade.php.');

        return true;
    }

    /**
     * Insert the managed recorders block at the end of the 'recorders' array.
     *
     * Returns null when the array can't be located so the caller can emit a
     * copy-paste fallback.
     *
     * NOTE: this scanner is character-level, not token-aware. It does not
     * skip `[` / `]` characters inside string literals or comments inside the
     * recorders array. For the stock pulse config (mostly class references)
     * this is safe; if reality produces a false positive in the wild, swap in
     * a real PHP tokenizer (token_get_all() or nikic/php-parser).
     */
    private function insertIntoRecordersArray(string $contents, string $managedBlock): ?string
    {
        // Find "'recorders' => [" or "\"recorders\" => [" then balance brackets
        // to its matching closer. This avoids regex sadness on nested arrays.
        if (preg_match('/[\'"]recorders[\'"]\s*=>\s*\[/', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $openOffset = $m[0][1] + strlen($m[0][0]) - 1; // position of '['
        $depth = 0;
        $length = strlen($contents);
        $closeOffset = null;

        for ($i = $openOffset; $i < $length; $i++) {
            $char = $contents[$i];
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    $closeOffset = $i;
                    break;
                }
            }
        }

        if ($closeOffset === null) {
            return null;
        }

        $indent = '        ';
        $indented = $indent.str_replace("\n", "\n".$indent, rtrim($managedBlock));
        $insertion = "\n".$indented."\n    ";

        return substr($contents, 0, $closeOffset).$insertion.substr($contents, $closeOffset);
    }

    /**
     * Insert the managed cards block just before the closing </x-pulse> tag.
     */
    private function insertIntoDashboard(string $contents, string $managedBlock): ?string
    {
        $closing = '</x-pulse>';
        $offset = strrpos($contents, $closing);
        if ($offset === false) {
            return null;
        }

        $insertion = "\n    ".str_replace("\n", "\n    ", rtrim($managedBlock))."\n";

        return substr($contents, 0, $offset).$insertion.substr($contents, $offset);
    }

    /**
     * Build the managed recorders block (with sentinels).
     */
    private function buildRecordersBlock(): string
    {
        $runs = SwarmRuns::class;
        $steps = SwarmStepDurations::class;
        $begin = self::RECORDERS_BEGIN;
        $end = self::RECORDERS_END;

        return <<<PHP
{$begin}
\\{$runs}::class => [
    'enabled' => env('PULSE_SWARM_RUNS_ENABLED', true),
],
\\{$steps}::class => [
    'enabled' => env('PULSE_SWARM_STEP_DURATIONS_ENABLED', true),
],
{$end}
PHP;
    }

    /**
     * Build the managed cards block (with sentinels).
     *
     * @param  list<string>  $cards
     */
    private function buildCardsBlock(array $cards): string
    {
        $begin = self::CARDS_BEGIN;
        $end = self::CARDS_END;

        if ($cards === []) {
            return $begin."\n".$end;
        }

        $lines = [];
        foreach ($cards as $card) {
            $lines[] = self::AVAILABLE_CARDS[$card];
        }

        return $begin."\n".implode("\n", $lines)."\n".$end;
    }

    /**
     * Publish a copy of the stock Pulse dashboard to the conventional path.
     *
     * Mirrors `php artisan vendor:publish --tag=pulse-dashboard` without
     * shelling out to a second command (which would lose stdin in non-
     * interactive contexts and obscures the file mutation we're about to do).
     */
    private function publishDefaultDashboard(Filesystem $files, string $dashboardPath): void
    {
        $source = $this->stockDashboardSource();

        $files->ensureDirectoryExists(dirname($dashboardPath));
        $files->put($dashboardPath, $source);
        $this->line('Published the default Pulse dashboard to '.$this->relativeTo($dashboardPath).'.');
    }

    /**
     * Locate the canonical stock dashboard shipped by laravel/pulse so we can
     * publish a faithful copy. Falls back to a minimal `<x-pulse></x-pulse>`
     * shell if (somehow) the file is missing from the installed vendor tree.
     */
    private function stockDashboardSource(): string
    {
        $pulseFile = (new \ReflectionClass(Pulse::class))->getFileName();

        if (is_string($pulseFile)) {
            $candidate = dirname($pulseFile, 2).'/resources/views/dashboard.blade.php';

            if (is_file($candidate)) {
                $contents = file_get_contents($candidate);
                if (is_string($contents)) {
                    return $contents;
                }
            }
        }

        return "<x-pulse>\n</x-pulse>\n";
    }

    /**
     * Write a `<file>.bak` snapshot of $path before mutating it. Idempotent:
     * the .bak is only created the first time (so re-running with --force
     * does not clobber the original pre-installer state).
     */
    private function backup(Filesystem $files, string $path): void
    {
        $backupPath = $path.'.bak';
        if ($files->exists($backupPath)) {
            return;
        }

        $files->copy($path, $backupPath);
    }

    /**
     * Make $path human-readable in console output by stripping the app base
     * path when possible.
     */
    private function relativeTo(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }
}
