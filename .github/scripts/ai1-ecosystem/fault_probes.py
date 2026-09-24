#!/usr/bin/env python3
"""Run discriminating provenance faults and a wrong-output smoke against a disposable proof.

Use only a completed proof.py output, never a real application. Restores exact bytes,
including the fixture database. Logs preserve expected red and restored green outputs.
"""
import argparse
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
sys.dont_write_bytecode = True
from proof import VERSIONS, check, digest, save, source_map, verify

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--proof', type=Path, required=True)
args = parser.parse_args()
root = args.proof.resolve()
report = json.loads((root / 'report.json').read_text())
check(report['status'] == 'passed' and Path(report['app']) == root / 'app', 'Not a completed disposable proof')
app = root / 'app'
logs = root / 'fault-probes'
check(not logs.exists(), 'Fault probe output already exists; preserve previous evidence')
logs.mkdir()
expected = report['expected']
source = {n: expected[n] for n in VERSIONS}
lock_path, installed_path = app / 'composer.lock', app / 'vendor/composer/installed.json'
original = {p: p.read_bytes() for p in [lock_path, installed_path, app / 'database/database.sqlite']}
original_hashes = {str(p.relative_to(app)): digest(p) for p in original}


def probe(label, lock, installed, targets, message):
    with tempfile.TemporaryDirectory(prefix='c5-provenance-fault-') as directory:
        fixture = Path(directory)
        (fixture / 'vendor/composer').mkdir(parents=True)
        save(fixture / 'composer.lock', lock)
        save(fixture / 'vendor/composer/installed.json', installed)
        try:
            verify(fixture, source_map(targets))
        except RuntimeError as error:
            check(message in str(error), f'{label} failed for wrong reason: {error}')
            (logs / (label + '-red.log')).write_text(str(error) + '\n')
        else:
            raise RuntimeError(label + ' unexpectedly accepted corrupted provenance')
    verify(app, expected)
    (logs / (label + '-restored-green.log')).write_text('Original lock/install identity passes; original bytes unchanged.\n')


try:
    lock = json.loads(original[lock_path])
    installed = json.loads(original[installed_path])
    bad = copy.deepcopy(lock)
    package = next(p for p in bad['packages'] if p['name'] == 'builtbyberry/laravel-swarm')
    package['source']['reference'] = '0' * 40
    peer = copy.deepcopy(installed)
    next(p for p in peer['packages'] if p['name'] == package['name'])['source'] = package['source']
    probe('wrong-ref', bad, peer, source, 'Wrong source ref')
    bad_source = copy.deepcopy(source)
    bad_source['builtbyberry/laravel-swarm']['version'] = '0.26.2'
    probe('wrong-version', lock, installed, bad_source, 'Wrong expected version')
    bad = copy.deepcopy(lock)
    bad['packages'] = [p for p in bad['packages'] if p['name'] != 'builtbyberry/laravel-swarm-pulse']
    probe('missing-companion', bad, installed, source, 'Missing locked/installed package')
    peer = copy.deepcopy(installed)
    next(p for p in peer['packages'] if p['name'] == 'builtbyberry/laravel-swarm')['version'] = '0.26.2'
    probe('lock-installed-disagreement', lock, peer, source, 'Lock/installed disagreement')
    # The baseline is a post-migration, pre-smoke database, so exact counters remain meaningful.
    baseline = (root / 'pre-smoke.sqlite').read_bytes()
    database = app / 'database/database.sqlite'
    env = {k: os.environ[k] for k in ['PATH', 'HOME', 'TMPDIR', 'SYSTEMROOT'] if k in os.environ}
    for suffix, expected_output, expected_status in [('red', 'deliberately-wrong-output', 1), ('restored-green', 'smoke-answer', 0)]:
        database.write_bytes(baseline)
        result = subprocess.run(['php', 'smoke.php'], cwd=app, env=env | {'C5_EXPECTED_OUTPUT': expected_output}, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        (logs / ('wrong-smoke-output-' + suffix + '.log')).write_text(result.stdout + f'\nexit={result.returncode}\n')
        check((result.returncode != 0) if expected_status else result.returncode == 0, 'Wrong smoke output guard did not discriminate: ' + suffix)
        check('prompt output' in result.stdout if expected_status else json.loads(result.stdout)['status'] == 'passed', 'Smoke failed for unrelated reason')
finally:
    for path, value in original.items():
        path.write_bytes(value)
    check(original_hashes == {str(p.relative_to(app)): digest(p) for p in original}, 'Exact restoration failed')
    save(logs / 'restoration.json', original_hashes)
print(logs)
