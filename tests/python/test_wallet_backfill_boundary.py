"""Run the real backfill CLI with synthetic database and write sinks only."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class WalletBackfillBoundary(unittest.TestCase):
    def run_backfill(self, *args):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'scripts').mkdir()
            (root / 'includes').mkdir()
            shutil.copyfile(ROOT / 'scripts/backfill-wallet-themes.php', root / 'scripts/backfill-wallet-themes.php')
            (root / 'config.php').write_text('''<?php
define('INCLUDES_DIR', __DIR__ . '/includes');
class Database {
    public static function getInstance() { return new self; }
    public function fetchAll($sql) {
        return [
            ['id'=>'synthetic-a','slug'=>'audit-a','name'=>'Audit A','brand'=>'#123456'],
            ['id'=>'synthetic-b','slug'=>'audit-b','name'=>'Audit B','brand'=>'#abcdef']
        ];
    }
}
''')
            (root / 'includes/DatabaseAdapter.php').write_text('''<?php
class DatabaseAdapter {
    public static function seedDefaultWalletTheme($id, $brand) {
        file_put_contents(__DIR__ . '/../writes.jsonl', json_encode([$id,$brand]) . "\\n", FILE_APPEND);
        return true;
    }
}
''')
            result = subprocess.run(['php', str(root / 'scripts/backfill-wallet-themes.php'), *args], capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            writes = root / 'writes.jsonl'
            return result.stdout, [json.loads(x) for x in writes.read_text().splitlines()] if writes.exists() else []

    def test_default_and_unrecognized_option_do_not_write(self):
        for args in [(), ('--dry-run',), ('--appl',)]:
            with self.subTest(args=args):
                output, writes = self.run_backfill(*args)
                self.assertEqual(writes, [])
                self.assertIn('Dry run.', output)

    def test_explicit_apply_keeps_each_tenant_and_brand_bound(self):
        _, writes = self.run_backfill('--apply')
        self.assertEqual(writes, [['synthetic-a','#123456'],['synthetic-b','#abcdef']])


if __name__ == '__main__':
    unittest.main()
