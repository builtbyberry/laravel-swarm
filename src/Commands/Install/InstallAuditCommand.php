<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands\Install;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\DetectsInteractiveConsole;
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Scaffolds the audit pipeline wiring inside the host application.
 *
 * This is the targeted setup for audit evidence routing. It writes container
 * bindings into `app/Providers/AppServiceProvider::register()` behind clearly
 * marked sentinel comments so the command is idempotent and safe to re-run.
 *
 * The default `SwarmAuditSink` binding shipped by the package is
 * `NoOpSwarmAuditSink`, which silently discards every emitted evidence
 * record. Without explicit wiring no audit chain exists. This installer makes
 * that wiring a one-command operation:
 *
 *  - Bind a sink (`readable` for dev/staging, `noop` to opt back into the
 *    default discard, or `custom` which scaffolds a TODO marker pointing at
 *    where the user will plug their own sink in).
 *  - Optionally bind `SwarmAuditSigner`, `ActorResolver`, and `CapturePolicy`
 *    stubs for regulated deployments.
 *  - Confirm the `swarm_audit_outbox` table is present (or offer to run
 *    `php artisan migrate` to create it).
 *  - Print the current `SWARM_AUDIT_FAILURE_POLICY` and `SWARM_CAPTURE_*`
 *    flags with a one-line explainer so the operator understands what is
 *    being recorded before they ship.
 *  - Cross-link to `swarm:audit:status`, `swarm:audit:reconcile`, and
 *    `swarm:trace` for verification after install.
 *
 * AppServiceProvider mutation is sentinel-based rather than AST-based: every
 * scaffolded binding is wrapped in a unique `// swarm:audit ...` marker pair
 * so re-running the installer (or running it after manual edits) detects the
 * existing block and skips re-writing.
 */
#[AsCommand(name: 'swarm:install:audit')]
class InstallAuditCommand extends Command
{
    use DetectsInteractiveConsole;

    /**
     * @var string
     */
    protected $signature = 'swarm:install:audit
                            {--sink= : Sink to bind — one of: readable, noop, custom}
                            {--with-signer : Scaffold a SwarmAuditSigner stub binding}
                            {--with-actor-resolver : Scaffold an ActorResolver stub binding}
                            {--with-capture-policy : Scaffold a CapturePolicy stub binding}';

    /**
     * @var string
     */
    protected $description = 'Wire the swarm audit pipeline into the host application';

    private const SENTINEL_OPEN = '// swarm:install:audit — managed bindings (do not edit between markers)';

    private const SENTINEL_CLOSE = '// swarm:install:audit — end managed bindings';

    public function handle(Filesystem $files, ConfigRepository $config, Connection $connection): int
    {
        $this->components->info('Installing the Swarm audit pipeline.');

        $sink = $this->resolveSinkChoice();
        if ($sink === null) {
            return self::FAILURE;
        }

        $withSigner = $this->resolveOptionFlag(
            'with-signer',
            'Scaffold a SwarmAuditSigner stub (for tamper-evident signatures)?',
        );
        $withActorResolver = $this->resolveOptionFlag(
            'with-actor-resolver',
            'Scaffold an ActorResolver stub (for non-default actor attribution)?',
        );
        $withCapturePolicy = $this->resolveOptionFlag(
            'with-capture-policy',
            'Scaffold a CapturePolicy stub (for per-run capture overrides)?',
        );

        $providerPath = $this->appServiceProviderPath();
        if (! $files->exists($providerPath)) {
            $this->components->error(
                "Could not find [{$providerPath}]. Is this a Laravel application root?",
            );

            return self::FAILURE;
        }

        $providerContents = (string) $files->get($providerPath);

        if (str_contains($providerContents, self::SENTINEL_OPEN)) {
            $this->components->info(
                'AppServiceProvider already contains a swarm:install:audit block — leaving it untouched.',
            );
        } else {
            $rewritten = $this->insertManagedBlock(
                $providerContents,
                $this->buildManagedBlock($sink, $withSigner, $withActorResolver, $withCapturePolicy),
            );

            if ($rewritten === null) {
                $this->components->error(
                    "Could not locate a register() method body in [{$providerPath}]. "
                    .'Add the binding manually using the snippet printed below.',
                );
                $this->line('');
                $this->line($this->buildManagedBlock($sink, $withSigner, $withActorResolver, $withCapturePolicy));

                return self::FAILURE;
            }

            $files->put($providerPath, $rewritten);
            $this->components->task('Updated app/Providers/AppServiceProvider.php');
        }

        $this->verifyAuditOutboxTable($config, $connection);

        $this->printCurrentPolicyAndFlags($config);
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * @return string|null one of {readable, noop, custom}, or null on invalid input
     */
    private function resolveSinkChoice(): ?string
    {
        $option = $this->option('sink');

        if (is_string($option) && $option !== '') {
            $option = strtolower($option);
            if (! in_array($option, ['readable', 'noop', 'custom'], true)) {
                $this->components->error(
                    "Invalid --sink [{$option}]. Choose one of: readable, noop, custom.",
                );

                return null;
            }

            return $option;
        }

        if (! $this->consoleCanPrompt()) {
            // Non-interactive default: scaffold the marker for a custom sink so
            // the operator is forced to think about what they want to bind
            // before evidence starts flowing. `readable` would silently route
            // every audit record into the application log, which is friendly
            // in dev but the wrong default for a CI-run installer pass.
            return 'custom';
        }

        $choice = select(
            label: 'Which SwarmAuditSink should the installer bind?',
            options: [
                'readable' => 'LogChannelSwarmAuditSink — log-channel-backed; great for dev/staging',
                'noop' => 'NoOpSwarmAuditSink — explicit binding to the silent default',
                'custom' => 'Custom sink — scaffold a TODO marker, you wire it later',
            ],
            default: 'readable',
            hint: 'Production deployments should ship a bounded backend (database, queue, SIEM export).',
        );

        return is_string($choice) ? $choice : 'custom';
    }

    private function resolveOptionFlag(string $option, string $prompt): bool
    {
        if ((bool) $this->option($option) === true) {
            return true;
        }

        if (! $this->consoleCanPrompt()) {
            return false;
        }

        return confirm(label: $prompt, default: false);
    }

    /**
     * Indent used for the managed block contents inside register().
     *
     * Matches the existing register() body convention (8 spaces — 2 levels
     * deep from class scope).
     */
    private const BODY_INDENT = '        ';

    private function buildManagedBlock(
        string $sink,
        bool $withSigner,
        bool $withActorResolver,
        bool $withCapturePolicy,
    ): string {
        $sinkLine = match ($sink) {
            'readable' => '$this->app->singleton(\\'.SwarmAuditSink::class.'::class, \\BuiltByBerry\\LaravelSwarm\\Audit\\LogChannelSwarmAuditSink::class);'
                ."\n        // TIP: LogChannelSwarmAuditSink is dev/staging-friendly. Production should ship a bounded backend.",
            'noop' => '$this->app->singleton(\\'.SwarmAuditSink::class.'::class, \\BuiltByBerry\\LaravelSwarm\\Audit\\NoOpSwarmAuditSink::class);',
            'custom' => '// TODO(swarm:install:audit): bind your SwarmAuditSink implementation here.'
                ."\n        // \$this->app->singleton(\\".SwarmAuditSink::class.'::class, YourAppAuditSink::class);',
            default => '',
        };

        $optional = [];

        if ($withSigner) {
            $optional[] = '// TODO(swarm:install:audit): bind your SwarmAuditSigner for tamper-evident chains.'
                ."\n        // \$this->app->singleton(\\".SwarmAuditSigner::class.'::class, YourSwarmAuditSigner::class);';
        }

        if ($withActorResolver) {
            $optional[] = '// TODO(swarm:install:audit): bind a custom ActorResolver if the default does not fit.'
                ."\n        // \$this->app->singleton(\\".ActorResolver::class.'::class, YourActorResolver::class);';
        }

        if ($withCapturePolicy) {
            $optional[] = '// TODO(swarm:install:audit): bind a CapturePolicy for per-run capture overrides.'
                ."\n        // \$this->app->singleton(\\".CapturePolicy::class.'::class, YourCapturePolicy::class);';
        }

        $body = $sinkLine;
        if ($optional !== []) {
            $body .= "\n\n".implode("\n\n", $optional);
        }

        $indented = self::BODY_INDENT.str_replace("\n", "\n".self::BODY_INDENT, $body);

        return self::SENTINEL_OPEN."\n".$indented."\n".self::BODY_INDENT.self::SENTINEL_CLOSE;
    }

    /**
     * Insert the managed block at the top of the register() method body.
     *
     * Matches `public function register() {` (with or without the modern
     * `: void` return type) so the installer works against Laravel 10 / 11 /
     * 12 / 13 AppServiceProvider variants. Insertion places the block on its
     * own line at the configured body indent, preserving existing register()
     * body content unchanged.
     */
    private function insertManagedBlock(string $providerContents, string $block): ?string
    {
        $pattern = '/(public function register\(\)\s*(?::\s*void\s*)?\{)([\t ]*\n)/';

        if (preg_match($pattern, $providerContents) !== 1) {
            return null;
        }

        $replacement = '$1$2'.self::BODY_INDENT.$block."\n\n";

        $result = preg_replace($pattern, $replacement, $providerContents, 1);

        return is_string($result) ? $result : null;
    }

    private function verifyAuditOutboxTable(ConfigRepository $config, Connection $connection): void
    {
        $table = (string) $config->get('swarm.tables.audit_outbox', 'swarm_audit_outbox');
        $persistenceDriver = (string) $config->get('swarm.persistence.driver', 'cache');

        if ($persistenceDriver !== 'database') {
            $this->components->info(
                "Persistence driver is [{$persistenceDriver}]. The audit outbox requires the database driver — "
                .'sink failures will degrade to log-and-swallow until you switch.',
            );

            return;
        }

        try {
            $exists = $connection->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            $this->components->warn(
                "Could not check whether [{$table}] exists: ".$e->getMessage()
            );

            return;
        }

        if ($exists) {
            $this->components->task("Audit outbox table [{$table}] is present");

            return;
        }

        $this->components->warn(
            "Audit outbox table [{$table}] is missing. Sink failures cannot be queued for retry until you run migrations.",
        );

        $shouldMigrate = $this->consoleCanPrompt()
            && confirm(label: 'Run `php artisan migrate` now?', default: true);

        if ($shouldMigrate) {
            $this->call('migrate');
        } else {
            $this->line('  Run `php artisan migrate` when ready.');
        }
    }

    private function printCurrentPolicyAndFlags(ConfigRepository $config): void
    {
        $failurePolicy = (string) $config->get('swarm.audit.failure_policy', 'queue');

        $this->newLine();
        $this->components->info('Current audit configuration');
        $this->components->twoColumnDetail(
            'SWARM_AUDIT_FAILURE_POLICY',
            $failurePolicy.' '.$this->explainFailurePolicy($failurePolicy),
        );

        foreach (['inputs', 'outputs', 'artifacts', 'active_context'] as $captureFlag) {
            $value = $config->get("swarm.capture.{$captureFlag}", false) === true ? 'true' : 'false';
            $key = 'SWARM_CAPTURE_'.strtoupper($captureFlag);
            $this->components->twoColumnDetail(
                $key,
                $value.' '.$this->explainCaptureFlag($captureFlag),
            );
        }
    }

    private function explainFailurePolicy(string $policy): string
    {
        return match ($policy) {
            'swallow' => '(silently discard sink exceptions)',
            'log' => '(record sink exceptions via logger, then continue)',
            'queue' => '(persist failed records to swarm_audit_outbox for retry)',
            'dead_letter' => '(persist failed records directly to dead-letter)',
            'halt' => '(throw AuditSinkHaltedException — run fails)',
            default => '',
        };
    }

    private function explainCaptureFlag(string $flag): string
    {
        return match ($flag) {
            'inputs' => '(persist agent prompts/inputs)',
            'outputs' => '(persist agent responses/outputs)',
            'artifacts' => '(persist generated artifacts)',
            'active_context' => '(persist run context snapshots)',
            default => '',
        };
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  • Verify the chain end-to-end: <comment>php artisan swarm:audit:status</comment>');
        $this->line('  • Triage dead-letter records: <comment>php artisan swarm:audit:reconcile</comment>');
        $this->line('  • Reconstruct a single run: <comment>php artisan swarm:trace &lt;run_id&gt;</comment>');
        $this->line('  • Full contract: <comment>docs/audit-evidence-contract.md</comment>');
    }

    private function appServiceProviderPath(): string
    {
        return $this->laravel->basePath('app/Providers/AppServiceProvider.php');
    }
}
