<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Upgrade;

use RuntimeException;
use Throwable;

/** Static dependency inspection and the v0.25 to v0.26 manifest recipe. */
final class UpgradeAssistant
{
    public const RECIPE = '0.25-to-0.26';

    public const TARGET = '0.26.1';

    private const PACKAGES = [
        'builtbyberry/laravel-swarm' => '0.26.1',
        'laravel/ai' => '0.11.2',
        'builtbyberry/laravel-swarm-pulse' => '0.1.7',
        'builtbyberry/laravel-swarm-filament' => '0.2.3',
        'builtbyberry/laravel-swarm-mcp' => '0.1.2',
        'builtbyberry/laravel-swarm-memory-vector' => '0.1.4',
    ];

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        $root = $this->root($path);
        $manifestBytes = $this->read($root, 'composer.json', true);
        $manifest = new JsonDocument($manifestBytes ?? '');
        $report = [
            'schema_version' => 1,
            'recipe' => self::RECIPE,
            'target' => self::TARGET,
            'status' => 'preview',
            'root' => $root,
            'preview_digest' => '',
            'can_apply' => false,
            'runtime_verified' => false,
            'backup_id' => null,
            'inventory' => [],
            'actions' => [],
            'findings' => [],
        ];
        $findings = [];
        $actions = [];
        $add = static function (string $id, string $level, string $message) use (&$findings): void {
            $findings[] = compact('id', 'level', 'message');
        };
        $requirements = [];
        $object = $manifest->object;
        foreach (['config', 'require', 'require-dev', 'replace', 'provide'] as $field) {
            if (property_exists($object, $field) && ! $object->{$field} instanceof \stdClass) {
                throw new RuntimeException("Manifest {$field} must be a JSON object.");
            }
        }
        if (isset($object->config) && property_exists($object->config, 'platform') && ! $object->config->platform instanceof \stdClass) {
            throw new RuntimeException('Manifest config.platform must be a JSON object.');
        }
        foreach (['require', 'require-dev'] as $section) {
            foreach (($manifest->data[$section] ?? []) as $package => $constraint) {
                if (! is_string($constraint)) {
                    throw new RuntimeException('Dependency constraints must be strings.');
                }
                if (isset(self::PACKAGES[$package])) {
                    if (isset($requirements[$package])) {
                        $add('duplicate-requirement', 'blocker', "{$package} is declared in both requirement sections; reconcile it manually.");
                    }
                    $requirements[$package] = ['section' => $section, 'constraint' => $constraint];
                }
            }
        }
        foreach (['repositories', 'replace', 'provide'] as $field) {
            if (! empty($manifest->data[$field])) {
                $add('custom-'.$field, 'blocker', "Manifest {$field} requires manual dependency review; automatic edits are unavailable.");
            }
        }
        if (property_exists($object, 'repositories') && ! is_array($object->repositories) && ! $object->repositories instanceof \stdClass) {
            throw new RuntimeException('Manifest repositories must be a JSON array or object.');
        }
        if (isset($object->config) && property_exists($object->config, 'vendor-dir') && ! is_string($object->config->{'vendor-dir'})) {
            throw new RuntimeException('Manifest vendor-dir must be a string.');
        }
        if (isset($object->config->platform)) {
            foreach (get_object_vars($object->config->platform) as $value) {
                if (! is_string($value) && $value !== false) {
                    throw new RuntimeException('Manifest platform versions must be strings or false.');
                }
            }
        }
        $vendor = $manifest->data['config']['vendor-dir'] ?? 'vendor';
        if (! is_string($vendor) || ! preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~', $vendor)
            || array_intersect(explode('/', $vendor), ['.', '..']) !== []) {
            throw new RuntimeException('vendor-dir must be a safe relative path within the application.');
        }
        $lockBytes = $this->read($root, 'composer.lock', false);
        $installedBytes = $this->read($root, $vendor.'/composer/installed.json', false);
        $locked = [];
        $installed = [];
        if ($lockBytes === null) {
            $add('missing-lock', 'blocker', 'composer.lock is missing. Resolve and verify the current application dependencies before selecting this recipe.');
        } else {
            try {
                $lock = new JsonDocument($lockBytes);
                if (! isset($lock->object->packages) || ! is_array($lock->object->packages)) {
                    throw new RuntimeException('The lock must contain a packages array.');
                }
                if (property_exists($lock->object, 'packages-dev') && ! is_array($lock->object->{'packages-dev'})) {
                    throw new RuntimeException('The lock packages-dev must be an array.');
                }
                $locked = $this->packages(array_merge($lock->object->packages, $lock->object->{'packages-dev'} ?? []));
                if (property_exists($lock->object, 'aliases') && ! is_array($lock->object->aliases)) {
                    throw new RuntimeException('Lock aliases must be an array.');
                }
                if (! empty($lock->data['aliases'])) {
                    $add('lock-aliases', 'blocker', 'The lock contains aliases; select an official stable dependency pair manually.');
                }
            } catch (Throwable $e) {
                $add('invalid-lock', 'blocker', 'Cannot safely read composer.lock: '.$e->getMessage());
            }
        }
        if ($installedBytes === null) {
            $add('installed-unverified', 'manual', 'Installed metadata is absent. The lock is not proof of what is installed; verify after Composer resolution.');
        } else {
            try {
                $installedDocument = new JsonDocument($installedBytes);
                $installed = $this->packages($installedDocument->object->packages ?? null);
            } catch (Throwable $e) {
                $add('invalid-installed', 'blocker', 'Cannot safely read installed.json: '.$e->getMessage());
            }
        }
        $core = $locked['builtbyberry/laravel-swarm']['version'] ?? '';
        if (! is_string($core) || ! preg_match('/\Av?0\.(?:25|26)\.\d+\z/', $core)) {
            $add('unsupported-source', 'blocker', 'This recipe requires a lock with stable Swarm 0.25.x or 0.26.x. Other or unknown source versions require manual upgrade guidance.');
        }
        foreach (self::PACKAGES as $package => $minimum) {
            $requirement = $requirements[$package] ?? null;
            $lockedPackage = $locked[$package] ?? null;
            $installedPackage = $installed[$package] ?? null;
            $report['inventory'][$package] = [
                'constraint' => $requirement['constraint'] ?? null,
                'locked' => $lockedPackage['version'] ?? null,
                'installed' => $installedPackage['version'] ?? null,
                'locked_source_reference' => $lockedPackage['source']['reference'] ?? null,
                'installed_source_reference' => $installedPackage['source']['reference'] ?? null,
                'recommended_minimum' => $minimum,
            ];
            if ($installedBytes !== null && ($lockedPackage !== null || $installedPackage !== null)) {
                foreach (['version', 'source'] as $field) {
                    if (($lockedPackage[$field] ?? null) !== ($installedPackage[$field] ?? null)) {
                        $add('installed-mismatch:'.$package, 'blocker', "{$package} lock and installed {$field} differ. Reconcile the current installation before automatic fixes.");
                        break;
                    }
                }
            }
            if ($lockedPackage !== null) {
                $version = $lockedPackage['version'];
                if (! is_string($version) || ! preg_match('/\Av?\d+\.\d+\.\d+\z/', $version)) {
                    $add('unstable:'.$package, 'blocker', "{$package} is not locked to an ordinary stable release; inspect its provenance manually.");
                } elseif (version_compare(ltrim($version, 'v'), $minimum, '<')) {
                    $add('resolve:'.$package, 'manual', "{$package} is locked below {$minimum}. After reviewing constraints, resolve with Composer and verify the resulting lock and installation.");
                }
            }
            if ($requirement === null) {
                continue;
            }
            if ($lockedPackage === null) {
                $add('unlocked:'.$package, 'blocker', "{$package} is declared but absent from the lock; resolve this mismatch manually.");
            }
            $constraint = $requirement['constraint'];
            if (! preg_match('/\A(\^?)(\d+\.\d+\.\d+)\z/', $constraint, $parts)) {
                $add('constraint:'.$package, 'blocker', "{$package} uses an unsupported constraint. Keep its intent and review the required version manually.");

                continue;
            }
            $version = $parts[2];
            $allowedMinor = $package === 'builtbyberry/laravel-swarm' ? ['0.25', '0.26']
                : ($package === 'laravel/ai' ? ['0.10', '0.11'] : [$this->minor($minimum)]);
            if (! in_array($this->minor($version), $allowedMinor, true)) {
                $add('constraint-line:'.$package, 'blocker', "{$package} constraint is outside this recipe's supported version lines; no change is inferred.");

                continue;
            }
            if (version_compare($version, $minimum, '<')) {
                // A caret on the target minor already permits its newer patches.
                $sameMinor = $this->minor($version) === $this->minor($minimum);
                if ($parts[1] === '^' && $sameMinor) {
                    continue;
                }
                $actions[] = [
                    'id' => 'dependency:'.$package,
                    'package' => $package,
                    'section' => $requirement['section'],
                    'from' => $constraint,
                    'to' => $parts[1].$minimum,
                ];
            }
        }
        if (! isset($requirements['builtbyberry/laravel-swarm'])) {
            $add('core-not-direct', 'blocker', 'Swarm is not a direct root requirement; update the owning dependency manually.');
        }
        if (! isset($locked['laravel/ai'])) {
            $add('ai-missing', 'blocker', 'The lock does not include laravel/ai; verify the existing application dependency graph.');
        }
        $platform = $manifest->data['config']['platform']['php'] ?? null;
        if ($platform === false) {
            $add('php-platform-disabled', 'blocker', 'Composer platform.php is disabled. Review the deployment platform manually.');
        } elseif (is_string($platform) && (! preg_match('/\A\d+\.\d+(?:\.\d+)?\z/', $platform) || version_compare($platform, '8.4.0', '<'))) {
            $add('php-platform', 'blocker', 'Composer platform.php is below PHP 8.4 or is not a recognized stable version. Review the deployment platform manually.');
        }
        $framework = $locked['laravel/framework']['version'] ?? null;
        if (is_string($framework) && (! preg_match('/\Av?13\.\d+\.\d+\z/', $framework) || version_compare(ltrim($framework, 'v'), '13.16.0', '<'))) {
            $add('framework-version', 'blocker', 'The locked Laravel framework is outside the supported 13.x line or below 13.16. Review framework requirements manually.');
        }
        foreach ($this->manualSteps() as $id => $message) {
            $add($id, 'manual', $message);
        }
        $report['findings'] = $findings;
        $report['actions'] = $actions;
        $report['can_apply'] = $actions !== [] && ! in_array('blocker', array_column($findings, 'level'), true);
        $report['preview_digest'] = hash('sha256', json_encode([self::RECIPE, self::TARGET, $root, $manifestBytes, $lockBytes, $installedBytes, $actions, $findings], JSON_THROW_ON_ERROR));

        return $report;
    }

    /** @param list<string> $ids
     * @return array<string, mixed>
     */
    public function apply(string $path, array $ids, string $expected): array
    {
        if ($ids === [] || count($ids) !== count(array_unique($ids)) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
            throw new RuntimeException('Select unique action IDs and supply the preview digest.');
        }
        $root = $this->root($path);
        $report = [];
        $result = (new ManifestEditor)->apply($root, function () use ($root, $ids, $expected, &$report): array {
            $report = $this->inspect($root);
            if (! hash_equals($report['preview_digest'], $expected)) {
                throw new RuntimeException('The preview is stale or does not match this application. Run a new preview.');
            }
            if (! $report['can_apply']) {
                throw new RuntimeException('This report does not permit automatic fixes. Resolve its blocking findings first.');
            }
            $selected = array_values(array_filter($report['actions'], static fn (array $action): bool => in_array($action['id'], $ids, true)));
            if (count($selected) !== count($ids)) {
                throw new RuntimeException('An action ID is not present in the reviewed preview.');
            }
            $before = $this->read($root, 'composer.json', true) ?? '';
            $after = (new JsonDocument($before))->replace($selected);

            return compact('before', 'after');
        });
        $report['status'] = 'applied';
        $report['backup_id'] = $result['backup_id'];
        $report['applied_actions'] = $ids;
        $report['can_apply'] = false;
        $report['next_step'] = 'Run a fresh preview, resolve dependencies with Composer, then complete the manual verification checklist. The lock and installed files were not changed.';

        return $report;
    }

    /** @return array<string, mixed> */
    public function restore(string $path, string $backupId): array
    {
        $root = $this->root($path);
        $result = (new ManifestEditor)->restore($root, $backupId);

        return ['schema_version' => 1, 'recipe' => self::RECIPE, 'target' => self::TARGET, 'status' => 'restored', 'root' => $root, 'backup_id' => $result['backup_id'], 'runtime_verified' => false,
            'next_step' => 'Only composer.json was restored. Reconcile the lock and installed dependencies separately; this is not a data or package rollback.'];
    }

    public function root(string $path): string
    {
        $root = realpath($path);
        if ($root === false || ! is_dir($root) || is_link($path)) {
            throw new RuntimeException('The application path must be an existing directory, not a symbolic link.');
        }

        return $root;
    }

    private function read(string $root, string $relative, bool $required): ?string
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Refusing a symbolic link in '.$relative.'.');
            }
        }
        if (! file_exists($path)) {
            if ($required) {
                throw new RuntimeException('Missing '.$relative.'.');
            }

            return null;
        }
        if (! is_file($path)) {
            throw new RuntimeException($relative.' must be a regular file.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read '.$relative.'.');
        }

        return $contents;
    }

    /** @return array<string, array<string, mixed>> */
    private function packages(mixed $packages): array
    {
        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new RuntimeException('Expected a package list.');
        }
        $result = [];
        foreach ($packages as $package) {
            if (! $package instanceof \stdClass || ! isset($package->name, $package->version) || ! is_string($package->name) || ! is_string($package->version)) {
                throw new RuntimeException('Invalid package metadata.');
            }
            if (property_exists($package, 'source')) {
                if (! $package->source instanceof \stdClass) {
                    throw new RuntimeException('Package source metadata must be an object.');
                }
                foreach (['type', 'url', 'reference'] as $field) {
                    if (! isset($package->source->{$field}) || ! is_string($package->source->{$field}) || $package->source->{$field} === '') {
                        throw new RuntimeException('Package source metadata requires string type, url and reference.');
                    }
                }
            }
            $package = get_object_vars($package);
            if (isset($package['source'])) {
                $package['source'] = get_object_vars($package['source']);
            }
            if (isset($result[$package['name']])) {
                throw new RuntimeException('Duplicate package metadata.');
            }
            $result[$package['name']] = $package;
        }

        return $result;
    }

    private function minor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /** @return array<string, string> */
    private function manualSteps(): array
    {
        return [
            'composer-resolution' => 'Previewed manifest edits do not resolve dependencies. Review composer update --with-all-dependencies --dry-run, then perform the intended Composer update and verify lock/installed provenance and platform requirements. Composer may execute application plugins/scripts.',
            'upstream-api' => 'Review native AI connection/stream exceptions, event and Request constructor overrides, queued fake behavior and provider model defaults: https://github.com/laravel/ai/blob/v0.11.2/UPGRADE.md. No application source has been scanned or rewritten.',
            'approval-effects' => 'Native pending tool approvals fail explicitly and are nonretryable in Swarm. Inspect possible tool effects before manually restarting. Swarm waits/signals remain supported; native approval continuation is unavailable.',
            'rollback-reader' => 'Once corrected denied/failed tool evidence is persisted, an unmodified v0.25 reader is not a supported rollback target. Preserve evidence and use a compatible reader; a manifest backup does not provide dependency or data rollback.',
            'operational-upgrade' => 'Rehearse first. Stop intake, drain calls and queued work, inventory active durable work and custom serialized classes, stop workers, deploy code and lock together, refresh autoload/opcache, restart and smoke-test before resuming intake. Retain APP_KEY and prune/recover schedules.',
            'application-verification' => 'Runtime readiness remains unverified. Test your sync, queue, stream, durable, history/replay and recovery paths and custom native stores. See https://github.com/builtbyberry/laravel-swarm/blob/v0.26.0/UPGRADING.md#upgrading-to-v0260.',
        ];
    }
}
