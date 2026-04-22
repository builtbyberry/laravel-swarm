#!/usr/bin/env bash
# Install dependencies and run quality checks for the laravel-swarm package.
# Usage: from the package repository root, run: ./bin/setup-package.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "→ Installing package dependencies..."
composer install

echo "→ Running Pint..."
./vendor/bin/pint

echo "→ Running Pest..."
./vendor/bin/pest tests/Feature tests/Unit

echo ""
echo "✓ Package ready."
