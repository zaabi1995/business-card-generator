<?php
/**
 * One-shot CLI helper, mint a PHP session as a target user.
 *
 * Usage:
 *   /www/server/php/83/bin/php scripts/mint-session.php <user-email>
 *
 * Output: a single line with the PHPSESSID cookie value. Use it to drive
 * authenticated requests from Playwright / curl without typing a password.
 *
 * Use with care, this bypasses login. Only run on the VPS as root. Sessions
 * created here have the same permissions as a normal login, scoped to the
 * matched user's company + role.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config.php';

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/mint-session.php <user-email>\n");
    exit(2);
}

$db = Database::getInstance();
$user = $db->fetchOne('SELECT * FROM users WHERE email = :e LIMIT 1', ['e' => $email]);
if (!is_array($user)) {
    fwrite(STDERR, "User not found: $email\n");
    exit(2);
}

// config.php emits startup warnings (Session ini settings cannot be changed
// after headers already sent, etc) that flush the output buffer, which makes
// session_start() unusable from CLI. Bypass session_start entirely and write
// the file directly using PHP's session_encode wire format:
//   key1|<serialized value>key2|<serialized value>...
$payload = [
    'user_id'         => $user['id'],
    'user_email'      => $user['email'],
    'user_name'       => $user['name'],
    'user_role'       => $user['role'],
    'user_company_id' => $user['company_id'] ?? null,
];
if (!empty($user['company_id'])) {
    $payload['company_id'] = $user['company_id'];
    $company = $db->fetchOne('SELECT slug, name FROM companies WHERE id = :i', ['i' => $user['company_id']]);
    if ($company) {
        $payload['company_slug'] = $company['slug'];
        $payload['company_name'] = $company['name'];
    }
}

$encoded = '';
foreach ($payload as $k => $v) {
    $encoded .= $k . '|' . serialize($v);
}

$sid = bin2hex(random_bytes(16));
$sessFile = '/tmp/sess_' . $sid;
if (file_put_contents($sessFile, $encoded) === false) {
    fwrite(STDERR, "Failed to write $sessFile\n");
    exit(2);
}

// PHP-FPM runs as www:www, chown so it can read on next HTTP request.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($sessFile, 'www');
    @chgrp($sessFile, 'www');
    @chmod($sessFile, 0600);
}

echo $sid . "\n";
