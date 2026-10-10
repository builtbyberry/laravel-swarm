<?php

declare(strict_types=1);

function replayCommitExecutablePhp(string $source): string
{
    return collect(token_get_all($source))
        ->reject(fn (array|string $token): bool => is_array($token) && in_array($token[0], [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
        ], true))
        ->map(fn (array|string $token): string => is_array($token) ? $token[1] : $token)
        ->implode('');
}

/** @return array<string, string> */
function replayGuardPhpSources(): array
{
    $packageRoot = dirname(__DIR__, 3);
    $sources = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageRoot.'/src', FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if ($source === false) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($packageRoot) + 1));
        $sources[$relative] = $source;
    }

    return $sources;
}

/** @return list<string> */
function replayCoordinatorReceivers(string $code): array
{
    preg_match_all('/MemoryReplayCoordinator\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $code, $typed);
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*MemoryReplayCoordinator::class/', $code, $resolved);

    return collect([...$typed[1], ...$resolved[1]])
        ->flatMap(fn (string $name): array => ['$'.$name, '$this->'.$name])
        ->unique()
        ->values()
        ->all();
}

/** @return array{opens: int, saves: int} */
function replayCommitCounts(string $source): array
{
    $code = replayCommitExecutablePhp($source);
    $receivers = replayCoordinatorReceivers($code);

    if ($receivers === []) {
        return ['opens' => 0, 'saves' => 0];
    }

    $receiver = '(?:'.implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $receivers)).')';

    return [
        'opens' => preg_match_all('/'.$receiver.'\s*->\s*(?:during|begin)\s*\(/', $code),
        'saves' => preg_match_all('/'.$receiver.'\s*->\s*(?:commit|commitMemory|apply)\s*\(/', $code),
    ];
}

it('pins every replay boundary open to a save site after its success gate', function (): void {
    $expected = [
        'src/Runners/Durable/DurableBranchAdvancer.php' => ['opens' => 1, 'saves' => 1],
        'src/Runners/Durable/DurableHierarchicalCoordinator.php' => ['opens' => 1, 'saves' => 1],
        'src/Runners/Durable/DurableSequentialStepAdvancer.php' => ['opens' => 2, 'saves' => 2],
        'src/Runners/SequentialRunner.php' => ['opens' => 1, 'saves' => 1],
        'src/Runners/StaticHierarchicalStreamRunner.php' => ['opens' => 2, 'saves' => 3],
    ];
    $actual = [];

    foreach (replayGuardPhpSources() as $relative => $source) {
        if ($relative === 'src/Memory/MemoryReplayCoordinator.php') {
            continue;
        }

        $counts = replayCommitCounts($source);

        if ($counts['opens'] === 0 && $counts['saves'] === 0) {
            continue;
        }

        $actual[$relative] = $counts;
    }

    ksort($actual);

    expect($actual)->toBe(
        $expected,
        'Every replay boundary must be saved after its success gate with commit(), commitMemory(), or apply(); update the pinned map when a runner path changes.',
    );
});

it('pins every streamed ToolResult handler to decline normalization as its first action', function (): void {
    $expected = [
        'src/Runners/StaticHierarchicalStreamRunner.php' => ['handlers' => 1, 'normalized_first' => 1],
        'src/Streaming/StreamEventMapper.php' => ['handlers' => 1, 'normalized_first' => 1],
    ];
    $actual = [];

    foreach (replayGuardPhpSources() as $relative => $source) {
        $code = replayCommitExecutablePhp($source);

        if (preg_match('/use\s+Laravel\\\\Ai\\\\Streaming\\\\Events\\\\ToolResult(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/', $code, $import) !== 1) {
            continue;
        }

        $alias = $import[1] ?? 'ToolResult';
        $handler = '/(?:if|elseif)\s*\(\s*(?<event>\$[A-Za-z_][A-Za-z0-9_]*)\s+instanceof\s+'.preg_quote($alias, '/').'\s*\)\s*\{/';
        $normalizedFirst = '/(?:if|elseif)\s*\(\s*(?<event>\$[A-Za-z_][A-Za-z0-9_]*)\s+instanceof\s+'.preg_quote($alias, '/').'\s*\)\s*\{\s*DeclinedToolResults::applyToStreamEvent\s*\(\s*\k<event>\s*\)\s*;/';
        $counts = [
            'handlers' => preg_match_all($handler, $code),
            'normalized_first' => preg_match_all($normalizedFirst, $code),
        ];

        if ($counts['handlers'] === 0 && $counts['normalized_first'] === 0) {
            continue;
        }

        $actual[$relative] = $counts;
    }

    ksort($actual);

    expect($actual)->toBe(
        $expected,
        'Every Laravel AI ToolResult stream-event handler must call DeclinedToolResults::applyToStreamEvent() first; update the pinned map when a handler changes.',
    );
});
