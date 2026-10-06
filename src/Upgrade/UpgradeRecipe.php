<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Upgrade;

use RuntimeException;

/** Immutable dependency policy for one explicitly selected manifest recipe. */
final readonly class UpgradeRecipe
{
    public const DEFAULT = '0.25-to-0.26';

    public const NATIVE_ONE = '0.26-to-0.27';

    public const NATIVE_FEATURES = '0.27-to-0.28';

    public string $target;

    /** @var array<string, string|null> */
    public array $packages;

    public function __construct(public string $id = self::DEFAULT)
    {
        $this->target = match ($id) {
            self::DEFAULT => '0.26.1',
            self::NATIVE_ONE => '0.27.0',
            self::NATIVE_FEATURES => '0.28.0',
            default => throw new RuntimeException('Unknown upgrade recipe. Select 0.25-to-0.26, 0.26-to-0.27, or 0.27-to-0.28.'),
        };
        $this->packages = match ($id) {
            self::DEFAULT => [
                'builtbyberry/laravel-swarm' => '0.26.1',
                'laravel/ai' => '0.11.2',
                'builtbyberry/laravel-swarm-pulse' => '0.1.7',
                'builtbyberry/laravel-swarm-filament' => '0.2.3',
                'builtbyberry/laravel-swarm-mcp' => '0.1.2',
                'builtbyberry/laravel-swarm-memory-vector' => '0.1.4',
            ],
            self::NATIVE_ONE => [
                'builtbyberry/laravel-swarm' => '0.27.0',
                'laravel/ai' => '1.0.0',
                'builtbyberry/laravel-swarm-pulse' => '0.1.8',
                'builtbyberry/laravel-swarm-filament' => '0.3.0',
                'builtbyberry/laravel-swarm-mcp' => '0.2.0',
                'builtbyberry/laravel-swarm-memory-vector' => '0.2.0',
            ],
            self::NATIVE_FEATURES => [
                'builtbyberry/laravel-swarm' => '0.28.0',
                'laravel/ai' => '1.0.0',
                'builtbyberry/laravel-swarm-pulse' => '0.2.0',
                'builtbyberry/laravel-swarm-filament' => '0.4.0',
                'builtbyberry/laravel-swarm-mcp' => '0.3.0',
                'builtbyberry/laravel-swarm-memory-vector' => '0.3.0',
            ],
        };
    }

    public function acceptsSource(string $version): bool
    {
        return preg_match(match ($this->id) {
            self::DEFAULT => '/\Av?0\.(?:25|26)\.\d+\z/',
            self::NATIVE_ONE => '/\Av?0\.(?:26|27)\.\d+\z/',
            self::NATIVE_FEATURES => '/\Av?0\.(?:27|28)\.\d+\z/',
            default => throw new RuntimeException('Unknown upgrade recipe. Select 0.25-to-0.26, 0.26-to-0.27, or 0.27-to-0.28.'),
        }, $version) === 1;
    }

    public function sourceRequirement(): string
    {
        return match ($this->id) {
            self::DEFAULT => 'stable Swarm 0.25.x or 0.26.x',
            self::NATIVE_ONE => 'stable Swarm 0.26.x, or already-target 0.27.x for verification only',
            self::NATIVE_FEATURES => 'stable Swarm 0.27.x, or already-target 0.28.x for verification only',
            default => throw new RuntimeException('Unknown upgrade recipe. Select 0.25-to-0.26, 0.26-to-0.27, or 0.27-to-0.28.'),
        };
    }

    public function verificationOnly(string $core): bool
    {
        return match ($this->id) {
            self::DEFAULT => false,
            self::NATIVE_ONE => preg_match('/\Av?0\.27\.\d+\z/', $core) === 1,
            self::NATIVE_FEATURES => preg_match('/\Av?0\.28\.\d+\z/', $core) === 1,
            default => throw new RuntimeException('Unknown upgrade recipe. Select 0.25-to-0.26, 0.26-to-0.27, or 0.27-to-0.28.'),
        };
    }

    public function acceptsConstraint(string $package, string $version): bool
    {
        $minor = implode('.', array_slice(explode('.', $version), 0, 2));
        if ($this->id === self::NATIVE_FEATURES) {
            return match ($package) {
                'builtbyberry/laravel-swarm' => in_array($minor, ['0.27', '0.28'], true),
                'laravel/ai' => str_starts_with($version, '1.'),
                'builtbyberry/laravel-swarm-pulse' => in_array($minor, ['0.1', '0.2'], true),
                'builtbyberry/laravel-swarm-filament' => in_array($minor, ['0.3', '0.4'], true),
                'builtbyberry/laravel-swarm-mcp', 'builtbyberry/laravel-swarm-memory-vector' => in_array($minor, ['0.2', '0.3'], true),
                default => false,
            };
        }

        if ($this->id === self::NATIVE_ONE) {
            return match ($package) {
                'builtbyberry/laravel-swarm' => in_array($minor, ['0.26', '0.27'], true),
                'laravel/ai' => $minor === '0.11' || str_starts_with($version, '1.'),
                'builtbyberry/laravel-swarm-pulse' => $minor === '0.1',
                'builtbyberry/laravel-swarm-filament' => in_array($minor, ['0.2', '0.3'], true),
                'builtbyberry/laravel-swarm-mcp', 'builtbyberry/laravel-swarm-memory-vector' => in_array($minor, ['0.1', '0.2'], true),
                default => false,
            };
        }

        return in_array($minor, match ($package) {
            'builtbyberry/laravel-swarm' => ['0.25', '0.26'],
            'laravel/ai' => ['0.10', '0.11'],
            default => [implode('.', array_slice(explode('.', $this->packages[$package] ?? ''), 0, 2))],
        }, true);
    }
}
