"""Direct local HTTP tests of real scan handlers and SQL against two tenants.

Authentication is a fixture boundary here. ScanAuth has its separate tests.
No production config, customer records, mail or external providers are loaded.
"""
import base64
import json
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


class ScanApiBoundaries(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='cardify-api-fixtures-')
        cls.root = Path(cls.tmp.name)
        (cls.root / 'api/scan').mkdir(parents=True)
        (cls.root / 'includes').mkdir()
        for filename in ['list.php', 'update.php', 'delete.php', 'vcf.php', 'image.php']:
            shutil.copy(ROOT / 'api/scan' / filename, cls.root / 'api/scan' / filename)
        for filename in ['ScanParser.php', 'ScanVcf.php', 'VCardRfc.php', 'ScanImageAccess.php']:
            shutil.copy(ROOT / 'includes' / filename, cls.root / 'includes' / filename)
        (cls.root / 'config.php').write_text("<?php define('INCLUDES_DIR',__DIR__.'/includes'); require INCLUDES_DIR.'/Database.php';")
        (cls.root / 'includes/Database.php').write_text(r'''<?php
class Database {
 private $pdo;
 function __construct() {$this->pdo=new PDO('sqlite:'.dirname(__DIR__).'/fixture.sqlite'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
 static function getInstance(){return new self();}
 function getConnection(){return $this->pdo;}
 function fetchAll($q,$p=[]){$s=$this->pdo->prepare($q);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}
 function fetchOne($q,$p=[]){$rows=$this->fetchAll($q,$p);return $rows[0]??null;}
}
''')
        (cls.root / 'includes/ScanAuth.php').write_text(r'''<?php
class ScanAuth {
 static function requireEmployee(){
  $header=$_SERVER['HTTP_AUTHORIZATION']??'';
  if(!in_array($header,['Bearer synthetic-a','Bearer synthetic-b'],true)){http_response_code(401);exit;}
  $suffix=substr($header,-1);return ['employee_id'=>'employee-'.$suffix,'company_id'=>'tenant-'.$suffix];
 }
 static function requireEmployeeMutation(){return self::requireEmployee();}
}
''')
        (cls.root / 'includes/ShadowProfileService.php').write_text('<?php class ShadowProfileService {static function upsertFromParsed($p){return null;}}')
        (cls.root / 'api/scan/_ratelimit.php').write_text('<?php function scanRateLimit(...$args){}')
        cls.db = sqlite3.connect(cls.root / 'fixture.sqlite')
        cls.db.execute('CREATE TABLE scans(id INTEGER PRIMARY KEY, employee_id TEXT, company_id TEXT, parsed TEXT, tags TEXT, met_at TEXT, met_where TEXT, status TEXT, image_path TEXT, image_path_back TEXT, created_at TEXT, merge_provenance TEXT)')
        cls.image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJ9sAAAAASUVORK5CYII=')
        for suffix in ['a', 'b']:
            p = cls.root / 'uploads/scans' / ('employee-' + suffix) / (suffix * 24 + '.png')
            p.parent.mkdir(parents=True)
            p.write_bytes(cls.image)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        cls.base = 'http://127.0.0.1:' + str(port)
        cls.server = subprocess.Popen(['php', '-S', '127.0.0.1:' + str(port), '-t', str(cls.root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('Local PHP fixture did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate(); cls.server.wait(timeout=5); cls.db.close(); cls.tmp.cleanup()

    def setUp(self):
        self.db.execute('DELETE FROM scans')
        for n, suffix in enumerate(['a', 'b'], 1):
            self.db.execute('INSERT INTO scans(id,employee_id,company_id,parsed,status,image_path,created_at) VALUES(?,?,?,?,?,?,?)',
                            (n, 'employee-' + suffix, 'tenant-' + suffix, json.dumps({'name_en': 'Synthetic ' + suffix.upper()}), 'parsed',
                             'uploads/scans/employee-' + suffix + '/' + suffix * 24 + '.png', '2026-09-20'))
        self.db.commit()

    def request(self, route, suffix='a', query=None, body=None):
        url = self.base + '/api/scan/' + route + '.php'
        if query: url += '?' + urllib.parse.urlencode(query)
        headers = {'Content-Type': 'application/json'}
        if suffix: headers['Authorization'] = 'Bearer synthetic-' + suffix
        req = urllib.request.Request(url, headers=headers, data=json.dumps(body).encode() if body is not None else None)
        try: response = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as error: response = error
        with response: return response.status, response.read(), response.headers

    def test_read_search_and_export_are_owner_scoped(self):
        status, data, _ = self.request('list', query={'employee_id': 'employee-b', 'company_id': 'tenant-b'})
        self.assertEqual(status, 200); self.assertEqual([x['id'] for x in json.loads(data)['scans']], [1])
        status, data, _ = self.request('list', query={'q': "%' OR 1=1 --", 'limit': '100 OR 1=1'})
        self.assertEqual(status, 200); self.assertEqual(json.loads(data)['scans'], [])
        self.assertEqual(self.request('vcf', query={'id': 2})[0], 404)
        self.assertEqual(self.request('vcf', query={'id': 1})[0], 200)
        self.assertEqual(self.request('vcf', 'b', {'id': 2})[0], 200)

    def test_updates_and_deletes_ignore_client_tenant_selectors(self):
        self.assertEqual(self.request('update', body={'id': 2, 'tags': 'changed', 'employee_id': 'employee-b'})[0], 404)
        self.assertEqual(self.request('delete', body={'id': 2, 'company_id': 'tenant-b'})[0], 404)
        self.assertEqual(self.db.execute('SELECT status,tags FROM scans WHERE id=2').fetchone(), ('parsed', None))
        self.assertEqual(self.request('update', body={'id': 1, 'tags': 'own', 'employee_id': 'employee-b', 'company_id': 'tenant-b', 'status': 'deleted'})[0], 200)
        self.assertEqual(self.db.execute('SELECT employee_id,company_id,status,tags FROM scans WHERE id=1').fetchone(), ('employee-a', 'tenant-a', 'parsed', 'own'))
        self.assertEqual(self.request('delete', body={'id': 1})[0], 200)
        self.assertEqual(self.request('delete', body={'id': 1})[0], 200)
        self.assertEqual(json.loads(self.request('list')[1])['scans'], [])

    def test_private_image_route_enforces_ownership_and_no_store(self):
        self.assertEqual(self.request('image', None, {'id': 1})[0], 401)
        self.assertEqual(self.request('image', query={'id': 2})[0], 404)
        status, data, headers = self.request('image', query={'id': 1})
        self.assertEqual(status, 200); self.assertEqual(data, self.image)
        self.assertEqual(headers['Cache-Control'], 'private, no-store')
        self.assertEqual(self.request('image', 'b', {'id': 2})[0], 200)
        self.assertEqual(self.request('image', query={'id': 1, 'side': '../back'})[0], 404)
        self.request('delete', body={'id': 1})
        self.assertEqual(self.request('image', query={'id': 1})[0], 404)

    def test_image_paths_cannot_traverse_or_follow_employee_directory_symlinks(self):
        self.db.execute('UPDATE scans SET image_path=? WHERE id=1', ('uploads/scans/employee-a/../employee-b/' + 'b' * 24 + '.png',))
        self.db.commit()
        self.assertEqual(self.request('image', query={'id': 1})[0], 404)
        self.db.execute('UPDATE scans SET image_path=? WHERE id=1', ('uploads/scans/employee-a/' + 'a' * 24 + '.png',))
        self.db.commit()
        directory = self.root / 'uploads/scans/employee-a'
        saved = self.root / 'uploads/scans/employee-a-original'
        directory.rename(saved)
        directory.symlink_to(self.root / 'uploads/scans/employee-b', target_is_directory=True)
        alternate = self.root / 'uploads/scans/employee-b' / ('a' * 24 + '.png')
        alternate.write_bytes(self.image)
        try: self.assertEqual(self.request('image', query={'id': 1})[0], 404)
        finally:
            alternate.unlink(); directory.unlink(); saved.rename(directory)


if __name__ == '__main__': unittest.main()
