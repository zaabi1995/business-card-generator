#!/usr/bin/env python3
"""Check project-specific scanner rules using generated, inactive fixtures."""
import argparse
import json
from pathlib import Path
import secrets
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--gitleaks', default='gitleaks')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cardify-secret-rules-') as temporary:
    directory = Path(temporary)
    fixture = secrets.token_hex(16)
    (directory / 'sample.sh').write_text('DB_PASS="' + fixture + '"\n')
    (directory / 'sample.md').write_text('Password: `' + fixture + '`\n')
    report = directory.parent / (directory.name + '.json')
    try:
        run = subprocess.run([args.gitleaks, 'dir', str(directory), '--config', str(root / '.gitleaks.toml'),
                              '--redact=100', '--no-banner', '--report-format', 'json', '--report-path', str(report)],
                             capture_output=True, timeout=30)
        findings = json.loads(report.read_text()) if report.exists() else []
        rules = {finding['RuleID'] for finding in findings}
        required = {'literal-database-password', 'documented-password'}
        if run.returncode != 1 or not required.issubset(rules):
            raise SystemExit('FAIL: project-specific secret rules did not reject synthetic fixtures')
        if fixture in report.read_text() or fixture.encode() in run.stdout + run.stderr:
            raise SystemExit('FAIL: scanner output was not redacted')
        print('PASS: project-specific rules detect fixtures and redact every value')
    finally:
        report.unlink(missing_ok=True)
