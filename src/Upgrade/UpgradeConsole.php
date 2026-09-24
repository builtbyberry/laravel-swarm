<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Upgrade;

use RuntimeException;
use Throwable;

/** Shared presentation and explicit mutation options for both console entry points. */
final class UpgradeConsole
{
    public const HELP = <<<'TEXT'
Preview a Swarm upgrade recipe. Default: 0.25-to-0.26, target v0.26.1.
Explicit 0.26-to-0.27 targets planned v0.27.0 / Laravel AI 1.x.
Reviewed companion targets remain candidates, not published-install proof.

  swarm-upgrade [--path=APP] [--recipe=RECIPE] [--json]
  swarm-upgrade --path=APP [--recipe=RECIPE] --apply=ID[,ID] --expect=DIGEST --yes [--json]
  swarm-upgrade --path=APP [--recipe=RECIPE] --restore=BACKUP_ID --yes [--json]

Options:
  --path=APP       Application directory; defaults to the current directory.
  --recipe=NAME   Select 0.25-to-0.26 (default) or 0.26-to-0.27 explicitly.
  --json          Print a machine-readable report.
  --apply=IDS     Apply only these comma-separated action IDs from a preview.
  --expect=HASH   Require this exact preview SHA-256 before applying.
  --yes           Explicitly approve the selected apply or restore operation.
  --restore=ID    Restore a manifest backup only if later edits will not be lost.
  --help          Show this help.

Default: read-only preview. No Composer execution or lock edits.
Standalone does not boot the target app; Artisan boots Laravel normally.
Standalone refuses repeated options; Artisan uses Symfony's option parsing.
Use the same recipe for preview, apply and restore of its manifest backup.
Exit codes: 0 file operation completed; 1 report requires manual verification;
2 invalid input, unsafe state, or I/O failure. No result certifies runtime readiness.
Backups: .swarm-upgrade/ in the application; retain privately and remove manually.
TEXT;

    /** @param list<string> $arguments
     * @return array<string, string|bool>
     */
    public static function parse(array $arguments): array
    {
        $options = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '-h') {
                $argument = '--help';
            }
            if (! str_starts_with($argument, '--')) {
                throw new RuntimeException('Unexpected argument; use --help for supported options.');
            }
            $parts = explode('=', substr($argument, 2), 2);
            $key = $parts[0];
            if (array_key_exists($key, $options)) {
                throw new RuntimeException('Duplicate option: --'.$key.'.');
            }
            if (in_array($key, ['json', 'yes', 'help'], true)) {
                if (count($parts) !== 1) {
                    throw new RuntimeException('--'.$key.' does not take a value.');
                }
                $options[$key] = true;
            } elseif (in_array($key, ['path', 'recipe', 'apply', 'expect', 'restore'], true)) {
                $value = $parts[1] ?? ($arguments[++$index] ?? '');
                if ($value === '' || str_starts_with($value, '--')) {
                    throw new RuntimeException('--'.$key.' requires a value.');
                }
                $options[$key] = $value;
            } else {
                throw new RuntimeException('Unknown option: --'.$key.'.');
            }
        }

        return $options;
    }

    /** Identify a standalone selector for error reporting only, never for execution.
     * @param  list<string>  $arguments
     */
    public static function errorRecipe(array $arguments): ?string
    {
        $values = [];
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '--recipe=')) {
                $values[] = substr($argument, strlen('--recipe='));
            } elseif ($argument === '--recipe') {
                $value = $arguments[$index + 1] ?? '';
                $values[] = str_starts_with($value, '--') ? '' : $value;
            }
        }

        return count($values) > 1 ? null : ($values[0] ?? UpgradeRecipe::DEFAULT);
    }

    /** @param array<string, mixed> $options
     * @param  callable(string): void  $write
     */
    public function run(array $options, string $defaultPath, callable $write): int
    {
        $selected = array_key_exists('recipe', $options) ? $options['recipe'] : UpgradeRecipe::DEFAULT;
        try {
            if (! is_string($selected) || $selected === '') {
                throw new RuntimeException('--recipe requires a nonempty recipe name.');
            }
            $recipe = new UpgradeRecipe($selected);
            if ($options['help'] ?? false) {
                $write(self::HELP);

                return 0;
            }
            $apply = $options['apply'] ?? null;
            $restore = $options['restore'] ?? null;
            $expected = $options['expect'] ?? null;
            $approved = $options['yes'] ?? false;
            if ($apply !== null && $restore !== null) {
                throw new RuntimeException('Apply and restore cannot be combined.');
            }
            if (($apply !== null || $restore !== null) && ! $approved) {
                throw new RuntimeException('Review the preview first, then explicitly approve the operation with --yes.');
            }
            if ($apply === null && $expected !== null) {
                throw new RuntimeException('--expect requires --apply.');
            }
            if ($apply === null && $restore === null && $approved) {
                throw new RuntimeException('--yes requires an explicit --apply or --restore operation.');
            }
            $assistant = new UpgradeAssistant($recipe);
            $path = $options['path'] ?? $defaultPath;
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('The application path must be a nonempty string.');
            }
            if ($apply !== null) {
                if (! is_string($apply) || ! is_string($expected)) {
                    throw new RuntimeException('--apply requires action IDs and --expect requires the reviewed preview digest.');
                }
                $report = $assistant->apply($path, explode(',', $apply), $expected);
            } elseif ($restore !== null) {
                if (! is_string($restore)) {
                    throw new RuntimeException('--restore requires a backup ID.');
                }
                $report = $assistant->restore($path, $restore);
            } else {
                $report = $assistant->inspect($path);
            }
            $this->render($report, (bool) ($options['json'] ?? false), $write);

            return $report['status'] === 'preview' ? 1 : 0;
        } catch (Throwable $e) {
            $this->error($e, (bool) ($options['json'] ?? false), $write, $selected);

            return 2;
        }
    }

    /** @param callable(string): void $write */
    public function error(Throwable $error, bool $json, callable $write, mixed $selected = UpgradeRecipe::DEFAULT): void
    {
        $recipe = null;
        if (is_string($selected)) {
            try {
                $recipe = new UpgradeRecipe($selected);
            } catch (RuntimeException) {
                // An invalid selection has no valid target identity.
            }
        }
        $this->render(['schema_version' => 1, 'recipe' => is_string($selected) ? $selected : null, 'target' => $recipe?->target, 'status' => 'error', 'runtime_verified' => false, 'message' => $error->getMessage()], $json, $write);
    }

    /** @param array<string, mixed> $report
     * @param  callable(string): void  $write
     */
    private function render(array $report, bool $json, callable $write): void
    {
        if ($json) {
            $write(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $write('Swarm upgrade '.$report['status'].' — recipe '.($report['recipe'] ?? '(invalid)').' — target '.($report['target'] ?? '(unavailable)'));
        if (isset($report['message'])) {
            $write($report['message']);
        }
        foreach ($report['inventory'] ?? [] as $package => $details) {
            $write($package.': constraint '.($details['constraint'] ?? '(transitive/absent)').'; locked '.($details['locked'] ?? 'unknown').'; installed '.($details['installed'] ?? 'unknown'));
        }
        foreach ($report['actions'] ?? [] as $action) {
            $write($action['id'].' ['.$action['section'].']: '.$action['from'].' -> '.$action['to']);
        }
        foreach ($report['findings'] ?? [] as $finding) {
            $write('['.$finding['level'].'] '.$finding['id'].': '.$finding['message']);
        }
        if (isset($report['preview_digest'])) {
            $write('Preview digest: '.$report['preview_digest']);
            $write('Automatic fixes: '.($report['can_apply'] ? 'available with selected IDs, --expect and --yes' : 'unavailable; inspect findings or run a fresh preview'));
        }
        if (isset($report['backup_id'])) {
            $write('Backup ID: '.$report['backup_id']);
        }
        if (isset($report['next_step'])) {
            $write($report['next_step']);
        }
        $write('Runtime readiness is unverified.');
    }
}
