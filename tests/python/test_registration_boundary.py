"""Exercise the real registration POST with synthetic persistence and transport.

No application config, production database, mail service or external network is
loaded. Dependencies at the persistence/transport boundary are local fixtures.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RegistrationBoundary(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / 'company').mkdir()
        (self.root / 'includes').mkdir()
        shutil.copy(ROOT / 'company/register.php', self.root / 'company/register.php')
        shutil.copy(ROOT / 'includes/CompanyJoinVerification.php', self.root / 'includes/CompanyJoinVerification.php')
        (self.root / 'state.json').write_text(json.dumps({'employees': [], 'codes': []}))
        (self.root / 'config.php').write_text(r'''<?php
define('INCLUDES_DIR', __DIR__ . '/includes');
$_SESSION = [];
function stateRead() { return json_decode(file_get_contents(__DIR__.'/state.json'), true); }
function stateWrite($s) { file_put_contents(__DIR__.'/state.json', json_encode($s)); }
function getBasePath() { return '/'; }
function t($s) { return $s; }
function validateCSRFToken($s) { return $s === 'synthetic-csrf'; }
function getClientIp() { return '127.0.0.1'; }
function sanitizeEmail($s) { return strtolower(trim($s)); }
function isValidEmail($s) { return (bool)filter_var($s, FILTER_VALIDATE_EMAIL); }
function isBusinessEmailDomain($s) { return true; }
function extractEmailDomain($s) { return substr(strrchr($s, '@'), 1); }
function generateSlugFromEmail($s) { return str_replace('.', '-', extractEmailDomain($s)); }
function findCompanyByDomain($domain) {
  return in_array($domain, ['a.invalid','b.invalid'], true)
    ? ['id'=> $domain === 'a.invalid' ? 'tenant-a' : 'tenant-b', 'name'=>'Synthetic company'] : null;
}
function generateUUID() { return bin2hex(random_bytes(16)); }
function dbNow() { return '2026-09-20 12:00:00'; }
function addEmployee($row, $company) {
  $s=stateRead();
  // Persist only the synthetic fields asserted by these tests.
  $s['employees'][] = ['company_id'=>$company, 'email'=>$row['email'], 'status'=>$row['status']];
  stateWrite($s); return ['success'=>true, 'id'=>$row['id']];
}
function createCompany(...$args) {
  echo json_encode(['create_branch'=>true]); exit;
}
''')
        stubs = {
            'Auth.php': "class Auth { static function isLoggedIn(){return false;} static function emailExists($e){return ['exists'=>false];}}",
            'Referral.php': "class Referral { static function readPending(){return null;}}",
            'Mailer.php': 'class Mailer {}',
            'ScanClaimTicket.php': 'class ScanClaimTicket {}',
            'SecurityHeaders.php': "function cspNonceAttr(){return '';}",
            'RateLimiter.php': 'class RateLimiter { static function check(...$a){return true;} static function refund(...$a){} }',
            'UrlSafety.php': '',
            'Recaptcha.php': "class Recaptcha { static function verify(...$a){return ['ok'=>true];}}",
            'ui-header.php': "echo json_encode(['error'=>$error,'info'=>$info,'choice'=>$needsDomainChoice,'verification'=>$needsJoinVerification]); exit;",
            'OtpService.php': r'''
class OtpService {
 static function send($email,$channel,$purpose) {
  $s=stateRead(); $s['codes'][]=['email'=>$email,'purpose'=>$purpose,'code'=>'194862','used'=>false];
  stateWrite($s); return ['ok'=>true];
 }
 static function verify($email,$code,$purpose) {
  $s=stateRead();
  foreach($s['codes'] as &$c) {
   if(!$c['used'] && $c['email']===$email && $c['purpose']===$purpose && $c['code']===$code) {
    $c['used']=true; stateWrite($s); return ['ok'=>true];
   }
  }
  return ['ok'=>false];
 }
}
''',
        }
        for name, body in stubs.items():
            (self.root / 'includes' / name).write_text('<?php\n' + body)
        (self.root / 'runner.php').write_text("<?php $_SERVER['REQUEST_METHOD']='POST'; $_POST=json_decode(stream_get_contents(STDIN),true); require __DIR__.'/company/register.php';")

    def tearDown(self):
        self.tmp.cleanup()

    def post(self, **fields):
        data = {'csrf_token': 'synthetic-csrf', 'company_name': 'Synthetic company',
                'admin_email': 'applicant@a.invalid', 'password': 'synthetic-only-fixture',
                'company_slug': 'new-synthetic-company', 'phone_skipped': '1', **fields}
        result = subprocess.run([os.environ.get('PHP_BINARY', 'php'), '-d', 'display_errors=0',
                                 str(self.root / 'runner.php')], input=json.dumps(data),
                                text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, 'Registration fixture execution failed')
        self.assertEqual(result.stderr, '', 'Unexpected registration fixture diagnostic')
        return json.loads(result.stdout)

    def state(self):
        return json.loads((self.root / 'state.json').read_text())

    def test_join_requires_verified_mailbox_and_is_single_use(self):
        self.assertTrue(self.post()['choice'])
        self.assertEqual(self.state()['employees'], [])
        self.assertTrue(self.post(domain_choice='join')['verification'])
        self.assertEqual(self.state()['employees'], [])
        self.assertTrue(self.post(domain_choice='join', join_code='000000')['verification'])
        self.assertTrue(self.post(admin_email='other@a.invalid', domain_choice='join', join_code='194862')['verification'])
        self.assertTrue(self.post(admin_email='applicant@b.invalid', domain_choice='join', join_code='194862')['verification'])
        self.assertEqual(self.state()['employees'], [])
        self.assertIsNone(self.post(domain_choice='join', join_code='194862')['error'])
        self.assertEqual(self.state()['employees'], [{'company_id': 'tenant-a', 'email': 'applicant@a.invalid', 'status': 'pending'}])
        self.assertTrue(self.post(domain_choice='join', join_code='194862')['verification'])
        self.assertEqual(len(self.state()['employees']), 1)

    def test_tenant_b_and_separate_company_creation_still_work(self):
        self.post(admin_email='applicant@b.invalid', domain_choice='join')
        self.post(admin_email='applicant@b.invalid', domain_choice='join', join_code='194862')
        self.assertEqual(self.state()['employees'][0]['company_id'], 'tenant-b')
        self.assertTrue(self.post(domain_choice='create')['create_branch'])
        self.assertEqual(len(self.state()['employees']), 1)

    def test_invalid_csrf_and_invalid_input_never_issue_or_persist(self):
        self.assertIsNotNone(self.post(csrf_token='invalid', domain_choice='join')['error'])
        self.assertIsNotNone(self.post(admin_email='invalid', domain_choice='join')['error'])
        self.assertEqual(self.state(), {'employees': [], 'codes': []})


if __name__ == '__main__':
    unittest.main()
