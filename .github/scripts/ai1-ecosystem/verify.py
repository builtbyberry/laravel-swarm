#!/usr/bin/env python3
"""Validate an existing proof's lock/install identity; never install or modify it."""
import argparse
import json
from pathlib import Path
import sys
sys.dont_write_bytecode = True
from proof import source_map, verify

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--app', type=Path, required=True)
parser.add_argument('--sources', type=Path, required=True)
args = parser.parse_args()
try:
    print(json.dumps(verify(args.app, source_map(json.loads(args.sources.read_text()))), indent=2))
except Exception as error:
    print(f'PROVENANCE FAILED: {error}', file=sys.stderr)
    sys.exit(1)
