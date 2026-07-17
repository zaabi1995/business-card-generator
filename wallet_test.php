<?php
/**
 * CLI test for the Wallet pass backend (ScanPassService + MockApnsProvider).
 * Verifies the complete flow WITH A MOCKED APNs provider - this is NOT real
 * Apple push verification. Run: php wallet_test.php
 */
define('WALLET_APNS_MOCK', true);
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/ScanPassService.php';
require_once INCLUDES_DIR . '/ApnsProvider.php';

$EMP = 'wallet_test_emp_' . substr(bin2hex(random_bytes(4)), 0, 8);
$COMP = 'wallet_test_co';
$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "  FAIL $label\n"; } }

// 1. create
$p = ScanPassService::getOrCreateForEmployee($EMP, $COMP);
ok(!empty($p['serial']) && !empty($p['token']) && $p['version'] === 1, 'create pass -> serial+token+version1');
$serial = $p['serial']; $token = $p['token'];

// 2. idempotent get returns same serial + token
$p2 = ScanPassService::getOrCreateForEmployee($EMP, $COMP);
ok($p2['serial'] === $serial && $p2['token'] === $token, 'get again -> same serial+token (idempotent)');

// 3. authorize
ok(ScanPassService::authorize($serial, $token) === true, 'authorize valid token');
ok(ScanPassService::authorize($serial, 'wrong') === false, 'reject invalid token');
ok(ScanPassService::authorize('nope', $token) === false, 'reject unknown serial');

// 4. register device A
ok(ScanPassService::register($serial, 'DEV_A', 'PUSH_A', 'production') === 'created', 'register device A -> created (201)');
ok(ScanPassService::register($serial, 'DEV_A', 'PUSH_A2', 'production') === 'existing', 'register A again -> existing (200, idempotent)');
// multiple devices per pass
ok(ScanPassService::register($serial, 'DEV_B', 'PUSH_B', 'production') === 'created', 'register device B -> created (multi-device)');

// 5. serials-since (all new -> both), then since-now -> none
$s1 = ScanPassService::serialsForDevice('DEV_A', null);
ok(in_array($serial, $s1['serialNumbers'], true), 'serials since null -> includes our serial');
$future = date('Y-m-d H:i:s', time() + 3600);
$s2 = ScanPassService::serialsForDevice('DEV_A', $future);
ok(empty($s2['serialNumbers']), 'serials since future -> none (204)');

// 6. card change bumps version + returns the 2 registrations
sleep(1); // ensure last_modified advances past the "since future" boundary logic
$before = ScanPassService::findBySerial($serial)['version'];
$regs = ScanPassService::onCardChanged($EMP);
$after = ScanPassService::findBySerial($serial)['version'];
ok((int)$after === (int)$before + 1, 'card change bumps version');
ok(count($regs) === 2, 'card change returns both registered devices');

// 7. mock APNs push
$apns = apnsProvider();
ok($apns instanceof MockApnsProvider, 'factory returns MockApnsProvider under WALLET_APNS_MOCK');
$res = $apns->pushPassUpdates(APPLE_WALLET_PASS_TYPE_ID, $regs);
ok(count($apns->sent) === 2, 'mock APNs recorded 2 sends');
ok($apns->sent[0]['topic'] === APPLE_WALLET_PASS_TYPE_ID, 'push topic == Pass Type ID (not bundle id)');

// 8. invalid + transient device tokens
$mixed = [
  ['push_token' => 'PUSH_OK', 'environment' => 'production', 'device_library_id' => 'DEV_OK'],
  ['push_token' => 'INVALID_TOK', 'environment' => 'production', 'device_library_id' => 'DEV_BAD'],
  ['push_token' => 'TRANSIENT_TOK', 'environment' => 'production', 'device_library_id' => 'DEV_TX'],
];
$m2 = new MockApnsProvider();
$r2 = $m2->pushPassUpdates(APPLE_WALLET_PASS_TYPE_ID, $mixed);
$byDev = [];
foreach ($r2 as $x) { $byDev[$x['device_library_id']] = $x['result']; }
ok($byDev['DEV_OK'] === 'sent' && $byDev['DEV_BAD'] === 'invalid' && $byDev['DEV_TX'] === 'error', 'mock reports sent/invalid/error correctly');

// 9. dead registration cleanup (invalid token -> remove)
ScanPassService::removeRegistration($serial, 'DEV_BAD');
$afterClean = ScanPassService::serialsForDevice('DEV_BAD', null);
ok(empty($afterClean['serialNumbers']), 'dead registration removed');

// 10. unregister + replay
ok(ScanPassService::unregister($serial, 'DEV_A') === true, 'unregister A');
ok(ScanPassService::unregister($serial, 'DEV_A') === true, 'unregister A replay (idempotent, no error)');

// 11. revoke -> excluded from serials
ScanPassService::revoke($EMP);
$s3 = ScanPassService::serialsForDevice('DEV_B', null);
ok(empty($s3['serialNumbers']), 'revoked pass excluded from serials');

// 12. production provider without credential fails closed (never fake 'sent')
$prod = new TokenApnsProvider(true);
$pr = $prod->pushPassUpdates(APPLE_WALLET_PASS_TYPE_ID, [['push_token' => 'X', 'environment' => 'production', 'device_library_id' => 'D']]);
ok($pr[0]['result'] === 'error', 'prod APNs without credential -> error (fails closed, no false success)');

// 13. token secrecy: onCardChanged / registrations never expose the auth token
ok(!in_array('auth_token', array_keys($regs[0] ?? []), true), 'registration rows do not carry the auth token');

// cleanup
ScanPassService::deleteForEmployee($EMP);
$gone = ScanPassService::findBySerial($serial);
ok($gone === null, 'account/card deletion cleans up the pass');

echo "\n== $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);
