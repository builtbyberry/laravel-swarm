#!/usr/bin/env bash
# Scaffold a local Laravel 13 application and path-link this package for interactive development.
# Usage: run from a directory that will contain both the package clone and the dev app (e.g. ~/Code).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_NAME="${SWARM_DEV_APP_NAME:-laravel-swarm-app}"
APP_DIR="$(pwd)/$APP_NAME"

if [[ -e "$APP_DIR" ]]; then
    echo "Refusing to overwrite existing directory: $APP_DIR" >&2
    exit 1
fi

echo "→ Creating Laravel 13 dev app at $APP_DIR ..."
composer create-project laravel/laravel "$APP_DIR" "^13.0"

echo "→ Wiring local package into dev app..."
cd "$APP_DIR"

php <<PHP
<?php
declare(strict_types=1);

\$composerPath = __DIR__ . '/composer.json';
\$composer = json_decode(file_get_contents(\$composerPath), true);

\$repo = [
    'type' => 'path',
    'url' => '$PACKAGE_ROOT',
];

\$composer['repositories'] ??= [];
\$repositories = \$composer['repositories'];

if (! is_array(\$repositories)) {
    \$repositories = [];
}

\$found = false;
foreach (\$repositories as \$entry) {
    if (is_array(\$entry) && isset(\$entry['type'], \$entry['url']) && \$entry['type'] === 'path' && \$entry['url'] === '$PACKAGE_ROOT') {
        \$found = true;
        break;
    }
}

if (! \$found) {
    \$repositories[] = \$repo;
}

\$composer['repositories'] = \$repositories;
\$composer['require']['builtbyberry/laravel-swarm'] = '@dev';

file_put_contents(\$composerPath, json_encode(\$composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
PHP

composer require builtbyberry/laravel-swarm

echo "→ Installing Laravel AI..."
composer require laravel/ai

echo "→ Publishing swarm config..."
php artisan vendor:publish --tag=swarm-config

echo ""
echo "✓ Done. Dev environment ready."
echo "  Package:  $PACKAGE_ROOT"
echo "  Dev app:  $APP_DIR"
