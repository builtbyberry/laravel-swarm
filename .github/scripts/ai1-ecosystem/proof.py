#!/usr/bin/env python3
"""Fresh installed ecosystem proof. Python 3, PHP 8.4+, Composer 2, git and network required.

Candidate: proof.py candidate --output /new/path --sources sources.json
Published: proof.py published --output /new/path --sources published-sources.json
Each sources.json entry is package-name: {"version": "0.27.0", "reference": "40hex"}.
Published mode requires the same immutable map but NEVER creates repositories.
Outputs are private disposable fixtures, not production configuration or publication proof.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import urllib.request

VERSIONS = {
    'builtbyberry/laravel-swarm': '0.27.0',
    'builtbyberry/laravel-swarm-pulse': '0.1.8',
    'builtbyberry/laravel-swarm-filament': '0.3.0',
    'builtbyberry/laravel-swarm-mcp': '0.2.0',
    'builtbyberry/laravel-swarm-memory-vector': '0.2.0',
}
NATIVE = {
    'laravel/ai': {'version': '1.0.0', 'reference': '101c7ea33cd8569d82570f753fbf38e48b7d3d95'},
    'laravel/framework': {'version': '13.33.0', 'reference': '91188a17ceaa3dbace6e8a5f7abd0d042e466359'},
    'laravel/mcp': {'version': '1.0.0', 'reference': 'cfa4f38f82873eeb6848527883545f98f871e229'},
}


def check(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def save(path, value):
    Path(path).write_text(json.dumps(value, indent=2) + '\n')


def source_map(value):
    check(set(value) == set(VERSIONS), 'Expected exactly five ecosystem packages')
    for name, version in VERSIONS.items():
        check(value[name].get('version') == version, f'Wrong expected version: {name}')
        check(re.fullmatch('[0-9a-f]{40}', value[name].get('reference', '')), f'Immutable 40hex ref required: {name}')
    return value | NATIVE


def verify(app, expected):
    locked = json.loads((app / 'composer.lock').read_text())
    installed = json.loads((app / 'vendor/composer/installed.json').read_text())
    lock = {p['name']: p for p in locked['packages']}
    install = {p['name']: p for p in installed['packages']}
    result = {}
    for name, target in expected.items():
        check(name in lock and name in install, f'Missing locked/installed package: {name}')
        a, b = lock[name], install[name]
        for field in ['version', 'source', 'dist']:
            check(a.get(field) == b.get(field), f'Lock/installed disagreement: {name} {field}')
        check(a['version'].removeprefix('v') == target['version'], f'Wrong version: {name}')
        url = f'https://github.com/{name}.git'
        check(a['source']['url'] == url and a['source']['type'] == 'git', f'Unofficial source: {name}')
        check(a['source']['reference'] == target['reference'], f'Wrong source ref: {name}')
        check(a['dist']['reference'] == target['reference'], f'Wrong archive ref: {name}')
        check(a['dist']['type'] == 'zip' and a['dist']['url'] == f'https://api.github.com/repos/{name}/zipball/{target["reference"]}', f'Unofficial archive: {name}')
        check(not (app / 'vendor' / name).is_symlink(), f'Symlink install: {name}')
        result[name] = {k: a[k] for k in ['version', 'source', 'dist']}
    check(not locked.get('aliases'), 'Alias lock cannot establish this proof')
    return result


def run(command, app, env, logs, name, accepted=(0,)):
    result = subprocess.run(command, cwd=app, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    (logs / (name + '.log')).write_text('$ ' + ' '.join(command) + '\n' + result.stdout + f'\nexit={result.returncode}\n')
    check(result.returncode in accepted, f'{name} failed ({result.returncode}); see {logs / (name + ".log")}')
    return result


def assistant(app, env, logs, mode):
    prefixes = [['php', 'vendor/bin/swarm-upgrade'], ['php', 'artisan', 'swarm:upgrade']]
    reports = []
    for index, prefix in enumerate(prefixes):
        help_result = run(prefix + ['--help'], app, env, logs, f'assistant-help-{index}')
        check('0.26-to-0.27' in help_result.stdout, 'Recipe absent from help')
        result = run(prefix + ['--recipe=0.26-to-0.27', '--json'], app, env, logs, f'assistant-preview-{index}', (1,))
        report = json.loads(result.stdout)
        check(report['runtime_verified'] is False, 'Assistant overstates runtime proof')
        check('already-target' in [f['id'] for f in report['findings']] and not report['actions'], 'Already-target verification-only behavior')
        reports.append(report)
    check(reports[0] == reports[1], 'Standalone/Artisan report parity')
    if mode == 'candidate':
        report = reports[0]
        check(not report['can_apply'] and 'custom-repositories' in [f['id'] for f in report['findings']], 'Custom repository apply should be unavailable')
        before = {p: digest(app / p) for p in ['composer.json', 'composer.lock', 'vendor/composer/installed.json']}
        for index, prefix in enumerate(prefixes):
            result = run(prefix + ['--recipe=0.26-to-0.27', '--json', '--apply=dependency:builtbyberry/laravel-swarm', '--expect=' + report['preview_digest'], '--yes'], app, env, logs, f'assistant-refusal-{index}', (2,))
            check(json.loads(result.stdout)['status'] == 'error', 'Candidate apply did not refuse')
        check(before == {p: digest(app / p) for p in before}, 'Refused apply changed metadata')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['candidate', 'published'])
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--sources', type=Path, required=True)
    args = parser.parse_args()
    expected = source_map(json.loads(args.sources.read_text()))
    output = args.output.resolve()
    check(not output.exists() or (output.is_dir() and not any(output.iterdir())), 'Output must not exist or must be empty')
    output.mkdir(parents=True, exist_ok=True)
    app, logs = output / 'app', output / 'logs'
    logs.mkdir()
    fixture = Path(__file__).resolve().parent / 'app'
    shutil.copytree(fixture, app)
    for directory in ['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'database', 'routes']:
        (app / directory).mkdir(parents=True, exist_ok=True)
    (app / 'database/database.sqlite').touch()
    # Allow-list avoids inherited APP_*, DB_*, AI/provider credentials, Composer overrides and .env.
    env = {k: os.environ[k] for k in ['PATH', 'HOME', 'TMPDIR', 'SYSTEMROOT'] if k in os.environ}
    env.update(COMPOSER_HOME=str(output / 'composer-home'), COMPOSER_CACHE_DIR=str(output / 'composer-cache'), COMPOSER_NO_INTERACTION='1', APP_ENV='testing', APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode())
    # Persist only the disposable fixture key so every PHP process boots the same encrypted DB.
    (app / '.env').write_text('APP_ENV=testing\nAPP_KEY=' + env['APP_KEY'] + '\n')
    os.chmod(app / '.env', 0o600)
    manifest = {'name': 'local/ai1-ecosystem-proof', 'type': 'project', 'require': {'php': '^8.4', **{k: v['version'] for k, v in expected.items()}}, 'autoload': {'psr-4': {'App\\': 'app/'}}, 'minimum-stability': 'stable', 'prefer-stable': True, 'config': {'allow-plugins': {}, 'sort-packages': True}}
    originals = output / 'source-manifests'
    originals.mkdir()
    overrides = []
    if args.mode == 'candidate':
        for name in VERSIONS:
            target = expected[name]
            url = f'https://raw.githubusercontent.com/{name}/{target["reference"]}/composer.json'
            request = urllib.request.Request(url, headers={'User-Agent': 'Laravel-Swarm-Ecosystem-Proof'})
            raw = urllib.request.urlopen(request, timeout=60).read()
            (originals / (name.split('/')[1] + '.json')).write_bytes(raw)
            package = json.loads(raw)
            check(package['name'] == name, 'Source manifest name mismatch')
            package.pop('version', None)
            package.pop('repositories', None)
            if 'extra' in package:
                package['extra'].pop('branch-alias', None)
            package.update(version=target['version'], source={'type': 'git', 'url': f'https://github.com/{name}.git', 'reference': target['reference']}, dist={'type': 'zip', 'url': f'https://api.github.com/repos/{name}/zipball/{target["reference"]}', 'reference': target['reference']})
            overrides.append({'type': 'package', 'package': package})
        manifest['repositories'] = overrides
    save(app / 'composer.json', manifest)
    save(output / 'expected.json', expected)
    save(output / 'overrides.json', overrides)
    check(not (app / 'vendor').exists() and not (app / 'composer.lock').exists(), 'App must begin without vendor and lock')
    save(output / 'freshness.json', {'app': str(app), 'vendor_absent': True, 'lock_absent': True, 'composer_home_absent': not Path(env['COMPOSER_HOME']).exists(), 'composer_cache_absent': not Path(env['COMPOSER_CACHE_DIR']).exists()})
    run(['composer', 'update', '--prefer-dist', '--no-progress', '--no-interaction', '--no-scripts'], app, env, logs, 'composer')
    identities = verify(app, expected)
    for name in VERSIONS:
        installed_manifest = app / 'vendor' / name / 'composer.json'
        if args.mode == 'candidate':
            check(digest(installed_manifest) == digest(originals / (name.split('/')[1] + '.json')), f'Installed/source manifest mismatch: {name}')
    run(['php', 'artisan', 'package:discover', '--ansi'], app, env, logs, 'discovery')
    run(['php', 'artisan', 'migrate', '--force'], app, env, logs, 'migrate')
    run(['php', 'artisan', 'migrate', '--force', '--path=vendor/laravel/pulse/database/migrations'], app, env, logs, 'migrate-pulse')
    run(['php', 'artisan', 'list', '--format=json'], app, env, logs, 'commands')
    for command in ['swarm:prune', 'swarm:recover', 'swarm:status', 'swarm:history', 'swarm:upgrade', 'swarm:install:pulse', 'swarm-mcp:install', 'swarm-filament:install', 'swarm-memory-vector:install']:
        run(['php', 'artisan', command, '--help'], app, env, logs, 'help-' + command.replace(':', '-'))
    assistant(app, env, logs, args.mode)
    shutil.copyfile(app / 'database/database.sqlite', output / 'pre-smoke.sqlite')
    smoke = run(['php', 'smoke.php'], app, env, logs, 'smoke')
    save(output / 'report.json', {'status': 'passed', 'mode': args.mode, 'published_install_proof': args.mode == 'published', 'app': str(app), 'expected': expected, 'identities': identities, 'hashes': {p: digest(app / p) for p in ['composer.json', 'composer.lock', 'vendor/composer/installed.json']}, 'source_manifest_hashes': {p.name: digest(p) for p in originals.iterdir()}, 'installed_manifest_hashes': {n: digest(app / 'vendor' / n / 'composer.json') for n in expected}, 'smoke': json.loads(smoke.stdout)})
    print(output / 'report.json')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'PROOF FAILED: {error}', file=sys.stderr)
        sys.exit(1)
