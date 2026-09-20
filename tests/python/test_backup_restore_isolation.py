import contextlib
import gzip
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/backup-restore-isolated.py'
spec = importlib.util.spec_from_file_location('isolated_restore', SCRIPT)
restore = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore)


class RestoreIsolation(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.backups = self.root / 'backups'
        self.backups.mkdir()
        self.evidence = self.root / 'evidence'
        self.tables = [f'fixture_{i}' for i in range(21)]
        with gzip.open(self.backups / 'cardify-fixture.sql.gz', 'wb') as f:
            f.write('\n'.join(f'CREATE TABLE `{t}` (id int);' for t in self.tables).encode())
        self.calls = []
        self.fail_import = False
        self.present = False

    def tearDown(self):
        self.temp.cleanup()

    def fake_run(self, args, **kwargs):
        self.calls.append(args)
        self.assertEqual(args[0], 'docker', 'No host SQL client may run')
        stdout = b''
        status = 0
        if args[1:3] == ['image', 'inspect']:
            self.assertEqual(args[3], restore.RESTORE_IMAGE)
            stdout = restore.RESTORE_IMAGE.encode()
        elif args[1] == 'run':
            self.present = True
        elif args[1] == 'inspect':
            if not self.present:
                status = 1
            else:
                stdout = json.dumps([{'HostConfig':{'NetworkMode':'none','ReadonlyRootfs':True,
                    'CapDrop':['ALL'],'Memory':1073741824},'Config':{'User':'999:999'}}]).encode()
        elif args[1] == 'exec':
            if '/proc/1/comm' in args:
                stdout = b'mysqld'
            elif '-i' in args:
                self.assertIn(b'CREATE TABLE', kwargs['input'])
                status = 1 if self.fail_import else 0
            elif 'SELECT 1 FROM' in args[-1]:
                stdout = b'1'
            elif 'SELECT table_name' in args[-1]:
                stdout = '\n'.join(self.tables).encode()
            elif args[-1].startswith('CHECK TABLE '):
                stdout = '\n'.join(f'bc.{t}\tcheck\tstatus\tOK' for t in self.tables).encode()
            elif '@@event_scheduler' in args[-1]:
                stdout = b'OFF\t0\t1'
            else:
                self.fail('Unexpected database command')
        elif args[1] == 'rm':
            self.present = False
        elif args[1] != 'logs':
            self.fail('Unexpected Docker action')
        return subprocess.CompletedProcess(args, status, stdout, b'')

    def execute(self):
        original = Path
        def mapped(path):
            if path == '/var/backups/cardify': return self.backups
            if path == '/var/lib/cardify-backup-verification': return self.evidence
            return original(path)
        with patch.object(restore.pathlib, 'Path', side_effect=mapped), \
             patch.object(restore.os, 'geteuid', return_value=0), \
             patch.object(restore.os, 'getloadavg', return_value=(0,0,0)), \
             patch.object(restore.subprocess, 'run', side_effect=self.fake_run), \
             contextlib.redirect_stdout(io.StringIO()):
            restore.main()

    def test_success_is_networkless_ephemeral_and_bounded(self):
        self.execute()
        run = next(c for c in self.calls if c[1] == 'run')
        for option, value in [('--network','none'),('--user','999:999'),('--cap-drop','ALL'),
                              ('--cpus','0.5'),('--memory','1g'),('--memory-swap','1g'),('--pids-limit','100')]:
            self.assertEqual(run[run.index(option)+1], value)
        self.assertIn('--read-only', run)
        for forbidden in ['--mount','--volume','-v','--publish','-p','--privileged']:
            self.assertNotIn(forbidden, run)
        self.assertNotIn('pull', [c[1] for c in self.calls])
        self.assertFalse(self.present)
        result=json.loads(next(self.evidence.rglob('result.json')).read_text())
        self.assertTrue(any(r['label']=='restore_passed' for r in result))
        self.assertTrue(result[-1]['container_absent'])

    def test_failed_import_is_a_failure_and_removes_only_its_container(self):
        self.fail_import=True
        with self.assertRaisesRegex(RuntimeError, 'import failed'):
            self.execute()
        self.assertFalse(self.present)
        name=next(c for c in self.calls if c[1]=='run')[4]
        removals=[c for c in self.calls if c[1]=='rm']
        self.assertEqual(removals, [['docker','rm','--force','--volumes',name]])

    def test_missing_backup_fails_before_docker(self):
        next(self.backups.iterdir()).unlink()
        with self.assertRaisesRegex(AssertionError, 'no database backup'):
            self.execute()
        self.assertFalse(self.calls)

    def test_stale_backup_fails_before_docker(self):
        os.utime(next(self.backups.iterdir()), (1,1))
        with self.assertRaisesRegex(AssertionError, 'older than 48 hours'):
            self.execute()
        self.assertFalse(self.calls)

    def test_encrypted_backup_is_not_silently_ignored(self):
        next(self.backups.iterdir()).rename(self.backups/'cardify-fixture.sql.gz.gpg')
        with self.assertRaisesRegex(AssertionError, 'approved recovery identity'):
            self.execute()
        self.assertFalse(self.calls)

    def test_symlink_backup_is_refused(self):
        source=next(self.backups.iterdir())
        destination=self.root/'source.sql.gz'
        source.rename(destination)
        source.symlink_to(destination)
        with self.assertRaisesRegex(AssertionError, 'symlink refused'):
            self.execute()
        self.assertFalse(self.calls)


if __name__ == '__main__':
    unittest.main()
