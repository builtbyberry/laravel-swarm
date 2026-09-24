<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Upgrade\ManifestEditor;
use BuiltByBerry\LaravelSwarm\Upgrade\UpgradeRecipe;

beforeEach(function () {
    $this->upgradeRoot = sys_get_temp_dir().'/swarm-manifest-editor-'.bin2hex(random_bytes(8));
    mkdir($this->upgradeRoot, 0700);
    $this->upgradeRoot = realpath($this->upgradeRoot);
    $this->beforeManifest = "{\n  \"require\": {\"builtbyberry/laravel-swarm\": \"^0.25\"},\n  \"description\": \"keep spaces  and unicode café\"\n}\n";
    $this->afterManifest = str_replace('^0.25', '^0.26.1', $this->beforeManifest);
    file_put_contents($this->upgradeRoot.'/composer.json', $this->beforeManifest);
    chmod($this->upgradeRoot.'/composer.json', 0640);
    $this->prepareManifest = fn (): array => ['before' => $this->beforeManifest, 'after' => $this->afterManifest];
});

afterEach(function () {
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && ! is_link($path)) {
            chmod($path, 0700);
            foreach (new FilesystemIterator($path) as $entry) {
                $remove($entry->getPathname());
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $remove($this->upgradeRoot);
});

it('new recipe backups preserve bytes permissions and require matching restore identity', function () {
    $editor = new ManifestEditor(new UpgradeRecipe(UpgradeRecipe::NATIVE_ONE));
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    $path = $this->upgradeRoot.'/.swarm-upgrade/'.$result['backup_id'].'.json';
    $backup = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($backup)->toMatchArray(['schema_version' => 1, 'recipe' => '0.26-to-0.27', 'target' => '0.27.0', 'mode' => 0640])
        ->and($backup['before_sha256'])->toBe(hash('sha256', $this->beforeManifest))
        ->and($backup['after_sha256'])->toBe(hash('sha256', $this->afterManifest))
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(fileperms($this->upgradeRoot.'/composer.json') & 0777)->toBe(0640);
    expect(fn () => (new ManifestEditor)->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class, 'recipe')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->afterManifest);
    expect($editor->restore($this->upgradeRoot, $result['backup_id']))->toBe($result)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest);
});

it('existing old backups remain restorable with an explicit old recipe and refuse the new recipe', function () {
    $result = (new ManifestEditor)->apply($this->upgradeRoot, $this->prepareManifest);
    expect(fn () => (new ManifestEditor(new UpgradeRecipe(UpgradeRecipe::NATIVE_ONE)))->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class, 'recipe')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->afterManifest);
    (new ManifestEditor(new UpgradeRecipe(UpgradeRecipe::DEFAULT)))->restore($this->upgradeRoot, $result['backup_id']);
    expect(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest);
});

it('new backup restore rejects changed target identity and later manifest bytes', function (string $case) {
    $editor = new ManifestEditor(new UpgradeRecipe(UpgradeRecipe::NATIVE_ONE));
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    if ($case === 'target') {
        $path = $this->upgradeRoot.'/.swarm-upgrade/'.$result['backup_id'].'.json';
        $backup = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $backup['target'] = '0.27.1';
        file_put_contents($path, json_encode($backup, JSON_THROW_ON_ERROR));
    } else {
        file_put_contents($this->upgradeRoot.'/composer.json', "\n", FILE_APPEND);
    }
    $before = file_get_contents($this->upgradeRoot.'/composer.json');
    expect(fn () => $editor->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($before);
})->with(['target', 'later-edit']);

it('atomically applies exact bytes and privately backs up both images and original ownership', function () {
    $path = $this->upgradeRoot.'/composer.json';
    $original = stat($path);
    $result = (new ManifestEditor)->apply($this->upgradeRoot, $this->prepareManifest);
    $directory = $this->upgradeRoot.'/.swarm-upgrade';
    $backupPath = $directory.'/'.$result['backup_id'].'.json';
    $backup = json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR);
    clearstatcache();
    expect($result['backup_id'])->toMatch('/^[a-f0-9]{32}$/')
        ->and(file_get_contents($path))->toBe($this->afterManifest)
        ->and(stat($path)['ino'])->not->toBe($original['ino'])
        ->and(stat($path)['uid'])->toBe($original['uid'])
        ->and(stat($path)['gid'])->toBe($original['gid'])
        ->and(fileperms($path) & 07777)->toBe(0640)
        ->and(fileperms($directory) & 0777)->toBe(0700)
        ->and(fileperms($directory.'/lock') & 0777)->toBe(0600)
        ->and(fileperms($backupPath) & 0777)->toBe(0600)
        ->and($backup['schema_version'])->toBe(1)
        ->and($backup['recipe'])->toBe('0.25-to-0.26')
        ->and($backup['target'])->toBe('0.26.1')
        ->and(base64_decode($backup['before_base64'], true))->toBe($this->beforeManifest)
        ->and(base64_decode($backup['after_base64'], true))->toBe($this->afterManifest)
        ->and($backup['before_sha256'])->toBe(hash('sha256', $this->beforeManifest))
        ->and($backup['after_sha256'])->toBe(hash('sha256', $this->afterManifest))
        ->and($backup['mode'])->toBe(0640)
        ->and($backup['uid'])->toBe($original['uid'])
        ->and($backup['gid'])->toBe($original['gid']);
});

it('runs preparation under a persistent nonblocking exclusive lock', function () {
    $editor = new ManifestEditor;
    $result = $editor->apply($this->upgradeRoot, function () use ($editor): array {
        expect(fn () => $editor->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class, 'lock is busy');

        return ($this->prepareManifest)();
    });
    $lockIdentity = stat($this->upgradeRoot.'/.swarm-upgrade/lock')['ino'];
    $editor->restore($this->upgradeRoot, $result['backup_id']);
    expect(stat($this->upgradeRoot.'/.swarm-upgrade/lock')['ino'])->toBe($lockIdentity);
});

it('restores exact bytes and original metadata then refuses a repeated restore', function () {
    $editor = new ManifestEditor;
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    chmod($this->upgradeRoot.'/composer.json', 0600);
    expect($editor->restore($this->upgradeRoot, $result['backup_id']))->toBe($result)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest)
        ->and(fileperms($this->upgradeRoot.'/composer.json') & 07777)->toBe(0640);
    expect(fn () => $editor->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class, 'after-image');
});

it('refuses stale preparation and no-op replacements without backups', function (string $case) {
    $change = match ($case) {
        'stale' => ['before' => 'old', 'after' => $this->afterManifest],
        'noop' => ['before' => $this->beforeManifest, 'after' => $this->beforeManifest],
        'invalid' => ['before' => $this->beforeManifest, 'after' => 7],
    };
    expect(fn () => (new ManifestEditor)->apply($this->upgradeRoot, fn () => $change))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest)
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade/*.json'))->toBe([]);
})->with(['stale', 'noop', 'invalid']);

it('refuses later edits during preparation even when replacement bytes are identical', function (bool $sameBytes) {
    $path = $this->upgradeRoot.'/composer.json';
    $newBytes = $sameBytes ? $this->beforeManifest : 'external edit';
    expect(fn () => (new ManifestEditor)->apply($this->upgradeRoot, function () use ($path, $newBytes): array {
        file_put_contents($this->upgradeRoot.'/external', $newBytes);
        rename($this->upgradeRoot.'/external', $path);

        return ($this->prepareManifest)();
    }))->toThrow(RuntimeException::class, 'changed')
        ->and(file_get_contents($path))->toBe($newBytes)
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade/*.json'))->toBe([]);
})->with([false, true]);

it('refuses symlink nonregular or hardlinked manifests before creating tool state', function (string $case) {
    $path = $this->upgradeRoot.'/composer.json';
    if ($case === 'hardlink') {
        link($path, $this->upgradeRoot.'/other');
    } else {
        rename($path, $this->upgradeRoot.'/other');
        if ($case === 'symlink') {
            symlink($this->upgradeRoot.'/other', $path);
        } else {
            mkdir($path);
        }
    }
    expect(fn () => (new ManifestEditor)->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class, 'regular files')
        ->and(file_exists($this->upgradeRoot.'/.swarm-upgrade'))->toBeFalse()
        ->and(file_get_contents($this->upgradeRoot.'/other'))->toBe($this->beforeManifest);
})->with(['symlink', 'hardlink', 'directory']);

it('refuses a symlink project root', function () {
    $alias = $this->upgradeRoot.'/alias';
    symlink($this->upgradeRoot, $alias);
    expect(fn () => (new ManifestEditor)->apply($alias, $this->prepareManifest))->toThrow(RuntimeException::class, 'Project root');
});

it('refuses unsafe backup directories and lock files without changing the manifest', function (string $case) {
    $directory = $this->upgradeRoot.'/.swarm-upgrade';
    if ($case === 'directory-symlink') {
        mkdir($this->upgradeRoot.'/outside', 0700);
        symlink($this->upgradeRoot.'/outside', $directory);
    } elseif ($case === 'directory-file') {
        file_put_contents($directory, 'not a directory');
    } else {
        mkdir($directory, $case === 'permissions' ? 0755 : 0700);
        if ($case === 'lock-symlink') {
            symlink($this->upgradeRoot.'/composer.json', $directory.'/lock');
        } elseif ($case === 'lock-hardlink') {
            link($this->upgradeRoot.'/composer.json', $directory.'/lock');
        } elseif ($case === 'lock-permissions') {
            file_put_contents($directory.'/lock', '');
            chmod($directory.'/lock', 0644);
        }
    }
    expect(fn () => (new ManifestEditor)->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest);
})->with(['directory-symlink', 'directory-file', 'permissions', 'lock-symlink', 'lock-hardlink', 'lock-permissions']);

it('does not overwrite post-upgrade edits on restore', function () {
    $editor = new ManifestEditor;
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    file_put_contents($this->upgradeRoot.'/composer.json', 'later edit');
    expect(fn () => $editor->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class, 'after-image')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe('later edit');
});

it('rejects traversal and malformed backup IDs before creating tool state', function (string $id) {
    expect(fn () => (new ManifestEditor)->restore($this->upgradeRoot, $id))->toThrow(RuntimeException::class, 'Invalid backup ID')
        ->and(file_exists($this->upgradeRoot.'/.swarm-upgrade'))->toBeFalse();
})->with(['../composer.json', str_repeat('a', 32).'/../lock', str_repeat('A', 32), '', str_repeat('a', 31)]);

it('rejects corrupted backup schema hashes and ownership before restore', function (string $case) {
    $editor = new ManifestEditor;
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    $path = $this->upgradeRoot.'/.swarm-upgrade/'.$result['backup_id'].'.json';
    $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    match ($case) {
        'schema' => $record['schema_version'] = 2,
        'recipe' => $record['recipe'] = 'other',
        'target' => $record['target'] = '0.27.0',
        'hash' => $record['before_sha256'] = str_repeat('0', 64),
        'after-hash' => $record['after_sha256'] = str_repeat('0', 64),
        'base64' => $record['before_base64'] = '!',
        'mode' => $record['mode'] = 010000,
        'uid' => $record['uid'] = -1,
        'gid' => $record['gid'] = '0',
        'missing' => $record = [],
        'json' => null,
    };
    file_put_contents($path, $case === 'json' ? '{' : json_encode($record));
    expect(fn () => $editor->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->afterManifest);
})->with(['schema', 'recipe', 'target', 'hash', 'after-hash', 'base64', 'mode', 'uid', 'gid', 'missing', 'json']);

it('refuses insecure symlink and hardlinked backup files', function (string $case) {
    $editor = new ManifestEditor;
    $result = $editor->apply($this->upgradeRoot, $this->prepareManifest);
    $path = $this->upgradeRoot.'/.swarm-upgrade/'.$result['backup_id'].'.json';
    if ($case === 'permissions') {
        chmod($path, 0644);
    } elseif ($case === 'hardlink') {
        link($path, $this->upgradeRoot.'/backup-copy');
    } else {
        rename($path, $this->upgradeRoot.'/backup-copy');
        symlink($this->upgradeRoot.'/backup-copy', $path);
    }
    expect(fn () => $editor->restore($this->upgradeRoot, $result['backup_id']))->toThrow(RuntimeException::class)
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->afterManifest);
})->with(['permissions', 'hardlink', 'symlink']);

it('keeps a recoverable backup and original manifest when atomic replacement fails', function () {
    $editor = new class extends ManifestEditor
    {
        protected function renameFile(string $from, string $to): bool
        {
            return false;
        }
    };
    expect(fn () => $editor->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class, 'Atomic manifest replacement failed')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest)
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade-manifest-*'))->toBe([]);
    $backups = glob($this->upgradeRoot.'/.swarm-upgrade/*.json');
    expect($backups)->toHaveCount(1);
    $record = json_decode(file_get_contents($backups[0]), true, flags: JSON_THROW_ON_ERROR);
    expect(base64_decode($record['before_base64']))->toBe($this->beforeManifest);
});

it('releases its lock after failed preparation and rejects a repeated stale application', function () {
    $editor = new ManifestEditor;
    expect(fn () => $editor->apply($this->upgradeRoot, fn () => throw new RuntimeException('preparation failed')))->toThrow(RuntimeException::class, 'preparation failed');
    $editor->apply($this->upgradeRoot, $this->prepareManifest);
    expect(fn () => $editor->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class, 'stale')
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade/*.json'))->toHaveCount(1);
});

it('does not change the manifest when saving its backup fails', function () {
    $editor = new class extends ManifestEditor
    {
        protected function writeExclusive(string $path, string $bytes)
        {
            throw new RuntimeException('backup write unavailable');
        }
    };
    expect(fn () => $editor->apply($this->upgradeRoot, $this->prepareManifest))->toThrow(RuntimeException::class, 'backup write unavailable')
        ->and(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($this->beforeManifest)
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade/*.json'))->toBe([])
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade-manifest-*'))->toBe([]);
});

test('atomic replacement stages in the manifest directory even when backup storage could be mounted separately', function (): void {
    $editor = new class extends ManifestEditor
    {
        public array $renames = [];

        protected function renameFile(string $from, string $to): bool
        {
            $this->renames[] = [$from, $to];

            return parent::renameFile($from, $to);
        }
    };
    $before = file_get_contents($this->upgradeRoot.'/composer.json');
    $result = $editor->apply($this->upgradeRoot, fn (): array => ['before' => $before, 'after' => $before."\n"]);
    $editor->restore($this->upgradeRoot, $result['backup_id']);
    expect($editor->renames)->toHaveCount(2);
    foreach ($editor->renames as [$from, $to]) {
        expect(dirname($from))->toBe(dirname($to));
    }
    expect(file_get_contents($this->upgradeRoot.'/composer.json'))->toBe($before)
        ->and(glob($this->upgradeRoot.'/.swarm-upgrade-manifest-*'))->toBe([]);
});
