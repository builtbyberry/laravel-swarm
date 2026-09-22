<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Upgrade;

use RuntimeException;
use Throwable;

/** Guarded, manifest-only replacement with operator-managed backups. */
class ManifestEditor
{
    /**
     * The preparation callback must rederive the selected changes while this lock
     * is held. The lock serializes this tool only: external editors and Composer
     * must not write concurrently; the final identity check cannot eliminate that
     * external check-to-rename race.
     *
     * @param  callable(): array{before: string, after: string}  $prepare
     * @return array{backup_id: string}
     */
    public function apply(string $root, callable $prepare): array
    {
        return $this->locked($root, function (string $root, string $directory, array $rootStat, array $directoryStat) use ($prepare): array {
            $path = $root.'/composer.json';
            [$original, $identity] = $this->readRegular($path);
            $change = $prepare();
            if (! is_array($change) || ! isset($change['before'], $change['after']) || ! is_string($change['before']) || ! is_string($change['after'])) {
                throw new RuntimeException('Manifest preparation must return exact before and after bytes.');
            }
            if ($change['before'] !== $original || $change['before'] === $change['after']) {
                throw new RuntimeException('Manifest preparation is stale or contains no changes.');
            }
            $this->unchanged($path, $original, $identity);
            $this->sameDirectory($root, $rootStat);
            $this->sameDirectory($directory, $directoryStat, true);
            $id = bin2hex(random_bytes(16));
            $record = [
                'schema_version' => 1,
                'recipe' => '0.25-to-0.26',
                'target' => '0.26.1',
                'before_base64' => base64_encode($original),
                'after_base64' => base64_encode($change['after']),
                'before_sha256' => hash('sha256', $original),
                'after_sha256' => hash('sha256', $change['after']),
                'mode' => $identity['mode'] & 07777,
                'uid' => $identity['uid'],
                'gid' => $identity['gid'],
            ];
            $backup = $this->writeExclusive($directory.'/'.$id.'.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            fclose($backup);
            // Persist the backup's directory entry before changing the manifest.
            $this->syncDirectory($directory);
            $this->replace($root, $directory, $rootStat, $directoryStat, $original, $change['after'], $identity, $record);

            return ['backup_id' => $id];
        });
    }

    /** @return array{backup_id: string} */
    public function restore(string $root, string $backupId): array
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $backupId)) {
            throw new RuntimeException('Invalid backup ID.');
        }

        return $this->locked($root, function (string $root, string $directory, array $rootStat, array $directoryStat) use ($backupId): array {
            [$bytes, $backupStat] = $this->readRegular($directory.'/'.$backupId.'.json');
            if (($backupStat['mode'] & 0777) !== 0600 || $backupStat['uid'] !== $directoryStat['uid']) {
                throw new RuntimeException('Backup must be private and owned by the backup directory owner.');
            }
            try {
                $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new RuntimeException('Invalid backup JSON.', 0, $exception);
            }
            if (! is_array($record) || ($record['schema_version'] ?? null) !== 1 || ($record['recipe'] ?? null) !== '0.25-to-0.26' || ($record['target'] ?? null) !== '0.26.1') {
                throw new RuntimeException('Unsupported backup schema or recipe.');
            }
            foreach (['mode', 'uid', 'gid'] as $field) {
                if (! isset($record[$field]) || ! is_int($record[$field]) || $record[$field] < 0 || ($field === 'mode' && $record[$field] > 07777)) {
                    throw new RuntimeException('Invalid backup ownership or permissions.');
                }
            }
            foreach (['before', 'after'] as $image) {
                $encoded = $record[$image.'_base64'] ?? null;
                $hash = $record[$image.'_sha256'] ?? null;
                $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($decoded === false || ! is_string($hash) || ! hash_equals(hash('sha256', $decoded), $hash)) {
                    throw new RuntimeException('Backup bytes or hashes are invalid.');
                }
                $record[$image] = $decoded;
            }
            if ($record['before'] === $record['after']) {
                throw new RuntimeException('Backup does not describe a manifest change.');
            }
            [$current, $identity] = $this->readRegular($root.'/composer.json');
            if ($current !== $record['after']) {
                throw new RuntimeException('Manifest differs from the backup after-image; refusing to overwrite later edits or repeat a restore.');
            }
            $this->replace($root, $directory, $rootStat, $directoryStat, $current, $record['before'], $identity, $record);

            return ['backup_id' => $backupId];
        });
    }

    /**
     * @param  callable(string, string, array<int|string, int>, array<int|string, int>): array{backup_id: string}  $operation
     * @return array{backup_id: string}
     */
    private function locked(string $root, callable $operation): array
    {
        $canonical = realpath($root);
        if ($canonical === false || is_link(rtrim($root, '/')) || ! is_dir($canonical)) {
            throw new RuntimeException('Project root must be an existing nonsymlink directory.');
        }
        $root = rtrim($canonical, '/');
        $rootStat = $this->directoryStat($root);
        // Validate before creating any tool-owned files.
        $this->regularStat($root.'/composer.json');
        $directory = $root.'/.swarm-upgrade';
        clearstatcache(true, $directory);
        if (@lstat($directory) === false && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private upgrade backup directory.');
        }
        $directoryStat = $this->directoryStat($directory, true);
        $lockPath = $directory.'/lock';
        clearstatcache(true, $lockPath);
        if (file_exists($lockPath) || is_link($lockPath)) {
            $this->privateFile($lockPath, $directoryStat['uid']);
        }
        $oldMask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($oldMask);
        }
        if ($lock === false) {
            throw new RuntimeException('Cannot open upgrade lock.');
        }
        try {
            $lockStat = $this->privateFile($lockPath, $directoryStat['uid']);
            if (! $this->sameIdentity($lockStat, fstat($lock) ?: []) || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Upgrade lock is busy or changed.');
            }
            $this->sameDirectory($root, $rootStat);
            $this->sameDirectory($directory, $directoryStat, true);

            return $operation($root, $directory, $rootStat, $directoryStat);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<int|string, int>  $rootStat
     * @param  array<int|string, int>  $directoryStat
     * @param  array<int|string, int>  $identity
     * @param  array<string, mixed>  $metadata
     */
    private function replace(string $root, string $directory, array $rootStat, array $directoryStat, string $before, string $after, array $identity, array $metadata): void
    {
        // Keep source and destination in the same directory: PHP may otherwise
        // emulate an EXDEV rename using a non-atomic copy and unlink.
        $temporary = $root.'/.swarm-upgrade-manifest-'.bin2hex(random_bytes(16));
        $handle = $this->writeExclusive($temporary, $after);
        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['uid'] !== $metadata['uid'] && ! @chown($temporary, $metadata['uid'])) || ($stat['gid'] !== $metadata['gid'] && ! @chgrp($temporary, $metadata['gid'])) || ! @chmod($temporary, $metadata['mode'])) {
                throw new RuntimeException('Cannot preserve manifest ownership and permissions.');
            }
            $stat = $this->regularStat($temporary);
            if ($stat['uid'] !== $metadata['uid'] || $stat['gid'] !== $metadata['gid'] || ($stat['mode'] & 07777) !== $metadata['mode'] || ! fsync($handle)) {
                throw new RuntimeException('Cannot preserve and sync manifest ownership and permissions.');
            }
            $this->sameDirectory($root, $rootStat);
            $this->sameDirectory($directory, $directoryStat, true);
            $this->unchanged($root.'/composer.json', $before, $identity);
            if (! $this->renameFile($temporary, $root.'/composer.json')) {
                throw new RuntimeException('Atomic manifest replacement failed; the backup remains available.');
            }
        } finally {
            fclose($handle);
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function renameFile(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    /** @return resource */
    protected function writeExclusive(string $path, string $bytes)
    {
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create upgrade file.');
        }
        try {
            for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
                $written = @fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write complete upgrade file.');
                }
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot durably save upgrade file.');
            }

            return $handle;
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot sync backup directory.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('Cannot durably save backup directory entry.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{string, array<int|string, int>} */
    private function readRegular(string $path): array
    {
        $identity = $this->regularStat($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read manifest or backup.');
        }
        try {
            if (! $this->sameIdentity($identity, fstat($handle) ?: [])) {
                throw new RuntimeException('File identity changed while opening.');
            }
            $bytes = stream_get_contents($handle);
            if ($bytes === false || ! $this->sameIdentity($identity, $this->regularStat($path))) {
                throw new RuntimeException('File changed while reading.');
            }

            return [$bytes, $identity];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int|string, int> $identity */
    private function unchanged(string $path, string $before, array $identity): void
    {
        [$current, $stat] = $this->readRegular($path);
        if ($current !== $before || ! $this->sameIdentity($identity, $stat)) {
            throw new RuntimeException('Manifest changed during upgrade preparation.');
        }
    }

    /** @return array<int|string, int> */
    private function regularStat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new RuntimeException('Manifest, backup, and lock must be regular files without symlinks or hard links.');
        }

        return $stat;
    }

    /** @return array<int|string, int> */
    private function privateFile(string $path, int $owner): array
    {
        $stat = $this->regularStat($path);
        if (($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== $owner) {
            throw new RuntimeException('Upgrade lock must be private and owned by the backup directory owner.');
        }

        return $stat;
    }

    /** @return array<int|string, int> */
    private function directoryStat(string $path, bool $private = false): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($private && (($stat['mode'] & 0777) !== 0700 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())))) {
            throw new RuntimeException('Upgrade directory must be a nonsymlink private directory owned by the current user.');
        }

        return $stat;
    }

    /** @param array<int|string, int> $original */
    private function sameDirectory(string $path, array $original, bool $private = false): void
    {
        $stat = $this->directoryStat($path, $private);
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $field) {
            if ($stat[$field] !== $original[$field]) {
                throw new RuntimeException('Project or backup directory changed during upgrade.');
            }
        }
    }

    /**
     * @param  array<int|string, int>  $a
     * @param  array<int|string, int>  $b
     */
    private function sameIdentity(array $a, array $b): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
