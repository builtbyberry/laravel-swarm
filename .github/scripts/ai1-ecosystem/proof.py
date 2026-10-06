#!/usr/bin/env python3
"""Fresh installed ecosystem proof. Python 3, PHP 8.4+, Composer 2, git and network required.

Candidate: proof.py candidate --output /new/path --sources sources.json
Published: proof.py published --output /new/path --sources published-sources.json
Each sources.json maps the five ecosystem package names to {"version", "reference": "40hex"};
an optional "native" block overrides the laravel/ai, laravel/framework and laravel/mcp pins
(defaulting to the AI 1.0 ecosystem), so one harness serves every release's candidate set.
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
import signal
import shutil
import subprocess
import sys
import urllib.request

# The five ecosystem packages (core + four companions). Their versions and refs
# come from sources.json, not this list, so one harness serves every release
# (ai-1 core 0.27, v0.28 core 0.28, ...).
PACKAGES = (
    'builtbyberry/laravel-swarm',
    'builtbyberry/laravel-swarm-pulse',
    'builtbyberry/laravel-swarm-filament',
    'builtbyberry/laravel-swarm-mcp',
    'builtbyberry/laravel-swarm-memory-vector',
)
# Default upstream native pins (the AI 1.0 ecosystem). A sources.json may override
# them with its own "native" block (e.g. AI 1.0.1 for the v0.28 candidate set).
DEFAULT_NATIVE = {
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
    native = value.get('native')
    packages = {name: entry for name, entry in value.items() if name != 'native'}
    check(set(packages) == set(PACKAGES), 'Expected exactly five ecosystem packages')
    native = native if native is not None else DEFAULT_NATIVE
    check(set(native) == set(DEFAULT_NATIVE), 'Native block must pin laravel/ai, laravel/framework and laravel/mcp')
    for name, entry in {**packages, **native}.items():
        check(isinstance(entry, dict) and re.fullmatch(r'\d+\.\d+\.\d+', str(entry.get('version', ''))), f'Expected an exact x.y.z version: {name}')
        check(re.fullmatch('[0-9a-f]{40}', entry.get('reference', '')), f'Immutable 40hex ref required: {name}')
    return packages | native


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


def run(command, app, env, logs, name, accepted=(0,), timeout=300):
    process = subprocess.Popen(
        command,
        cwd=app,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )
    try:
        output, _ = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired as error:
        os.killpg(process.pid, signal.SIGTERM)
        try:
            output, _ = process.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            output, _ = process.communicate()
        (logs / (name + '.log')).write_text('$ ' + ' '.join(command) + '\n' + output + f'\ntimeout={timeout}\n')
        raise RuntimeError(f'{name} timed out after {timeout}s; see {logs / (name + ".log")}') from error
    (logs / (name + '.log')).write_text('$ ' + ' '.join(command) + '\n' + output + f'\nexit={process.returncode}\n')
    check(process.returncode in accepted, f'{name} failed ({process.returncode}); see {logs / (name + ".log")}')
    return subprocess.CompletedProcess(command, process.returncode, output)


def assistant(app, env, logs, mode):
    prefixes = [['php', 'vendor/bin/swarm-upgrade'], ['php', 'artisan', 'swarm:upgrade']]
    reports = []
    for index, prefix in enumerate(prefixes):
        help_result = run(prefix + ['--help'], app, env, logs, f'assistant-help-{index}')
        check('0.26-to-0.27' in help_result.stdout, 'Recipe absent from help')
        result = run(prefix + ['--recipe=0.26-to-0.27', '--json'], app, env, logs, f'assistant-preview-{index}', (1,))
        report = json.loads(result.stdout)
        check(report['runtime_verified'] is False, 'Assistant overstates runtime proof')
        # The 0.26-to-0.27 recipe accepts only 0.26/0.27 sources. A core-0.27 candidate
        # app is 'already-target'; a core-0.28 app is beyond the recipe and reported as
        # 'unsupported-source' (manual upgrade guidance). Either way the assistant is
        # verification-only for a candidate install — findings, never actions.
        check({'already-target', 'unsupported-source'} & {f['id'] for f in report['findings']} and not report['actions'], 'Verification-only behavior for the pinned recipe')
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
        for name in PACKAGES:
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
    run(['composer', 'update', '--prefer-dist', '--no-progress', '--no-interaction', '--no-scripts'], app, env, logs, 'composer', timeout=900)
    identities = verify(app, expected)
    for name in PACKAGES:
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
