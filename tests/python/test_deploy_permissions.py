import os
import pathlib
import subprocess
import tempfile
import unittest

REPO = pathlib.Path(__file__).resolve().parents[2]

class DeploymentPermissionTests(unittest.TestCase):
    def test_private_runtime_permissions_survive_source_repair(self):
        with tempfile.TemporaryDirectory() as path:
            root = pathlib.Path(path)
            subprocess.run(['git', 'init', '-q', path], check=True)
            for name, mode in [('index.php', 0o640), ('config.php', 0o600), ('data/wallet/key.pem', 0o600), ('private/log.txt', 0o600)]:
                target = root / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('synthetic fixture')
                target.chmod(mode)
            (root / 'private').chmod(0o700)
            subprocess.run(['git', 'add', 'index.php', 'config.php', 'data/wallet/key.pem'], cwd=root, check=True)
            subprocess.run(['bash', str(REPO / 'ops/repair-source-permissions.sh')], cwd=root, check=True)
            self.assertEqual((root / 'index.php').stat().st_mode & 0o777, 0o644)
            for name in ['config.php', 'data/wallet/key.pem', 'private/log.txt']:
                self.assertEqual((root / name).stat().st_mode & 0o777, 0o600)
            self.assertEqual((root / 'private').stat().st_mode & 0o777, 0o700)

if __name__ == '__main__': unittest.main()
