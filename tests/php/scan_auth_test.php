<?php
// tests/php/scan_auth_test.php
// Pure-logic test for ScanAuth token generation and hashing. No DB
// connection needed, config.php only exists on the server, so this is the
// verification ceiling for local runs.
require_once __DIR__ . '/../../includes/ScanAuth.php';

function check($label, $actual, $expected) {
    $ok = $actual === $expected;
    echo ($ok ? "PASS" : "FAIL") . " $label\n";
    if (!$ok) { var_dump($actual); exit(1); }
}

$token = ScanAuth::generateToken();
check('token length 43 (base64url of 32 bytes)', strlen($token), 43);
check('token is url safe', (bool)preg_match('/^[A-Za-z0-9_-]+$/', $token), true);
check('hash is sha256 hex', strlen(ScanAuth::hashToken($token)), 64);
check('hash deterministic', ScanAuth::hashToken($token), ScanAuth::hashToken($token));

$token2 = ScanAuth::generateToken();
check('tokens are not repeated', $token === $token2, false);
check('different tokens hash differently', ScanAuth::hashToken($token) === ScanAuth::hashToken($token2), false);

echo "ALL PASS\n";
