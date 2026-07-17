<?php
/**
 * Wallet ownership + isolation tests. Server-side authority: a pass token
 * authorizes ONLY its own serial; users cannot cross-access; serials are not
 * enumerable; deletion/revocation are enforced. Run: php wallet_isolation_test.php
 */
define('WALLET_APNS_MOCK', true);
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/ScanPassService.php';

$A = 'iso_A_' . bin2hex(random_bytes(3));
$B = 'iso_B_' . bin2hex(random_bytes(3));
$pass = 0; $fail = 0;
function ok($c, $l) { global $pass, $fail; if ($c) { $pass++; echo "  ok  $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }

$pa = ScanPassService::getOrCreateForEmployee($A, 'coA');
$pb = ScanPassService::getOrCreateForEmployee($B, 'coB');
[$sa, $ta] = [$pa['serial'], $pa['token']];
[$sb, $tb] = [$pb['serial'], $pb['token']];

// 1. A cannot retrieve/act on B's pass with A's token, and vice versa
ok(ScanPassService::authorize($sa, $ta) === true, 'A token authorizes A serial');
ok(ScanPassService::authorize($sb, $ta) === false, "A token CANNOT authorize B's serial");
ok(ScanPassService::authorize($sa, $tb) === false, "B token CANNOT authorize A's serial");

// 2. token cannot be reused across passes
ok($ta !== $tb, 'distinct passes have distinct tokens');
ok(ScanPassService::authorize($sb, $tb) === true && ScanPassService::authorize($sa, $tb) === false, 'token bound to its own serial only');

// 3. serials are high-entropy (128-bit hex) -> not enumerable
ok(preg_match('/^[0-9a-f]{32}$/', $sa) === 1, 'serial is 32-hex (128-bit random, non-enumerable)');
ok($sa !== $sb, 'serials are unique per pass');

// 4. unknown serial + wrong pass type are rejected at the service layer
ok(ScanPassService::authorize('deadbeef', $ta) === false, 'unknown serial rejected');
ok(ScanPassService::findBySerial('nonexistent-serial') === null, 'unknown serial has no pass row');

// 5. revoked pass is excluded from device serials (cannot keep pulling updates)
ScanPassService::register($sb, 'DEV_B', 'PUSH_B', 'production');
ScanPassService::revoke($B);
$row = ScanPassService::findBySerial($sb);
ok((int)$row['revoked'] === 1, 'revoke sets the flag');
$ser = ScanPassService::serialsForDevice('DEV_B', null);
ok(empty($ser['serialNumbers']), 'revoked pass excluded from changed-serials (no more updates)');

// 6. registration attaches ONLY to the given serial (no unrelated serial)
ScanPassService::register($sa, 'DEV_A', 'PUSH_A', 'production');
$serA = ScanPassService::serialsForDevice('DEV_A', null);
ok($serA['serialNumbers'] === [$sa], 'device sees exactly its registered serial, not others');

// 7. deleted account cleans up its pass + registrations
ScanPassService::deleteForEmployee($A);
ok(ScanPassService::findBySerial($sa) === null, 'deleted account: pass gone');
ok(empty(ScanPassService::serialsForDevice('DEV_A', null)['serialNumbers']), 'deleted account: registrations gone');

// cleanup B
ScanPassService::deleteForEmployee($B);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);
