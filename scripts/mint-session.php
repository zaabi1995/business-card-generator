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

// PHP CLI defaults session.save_path to "" which silently no-ops the write.
// PHP-FPM uses /tmp on this VPS (verified, files like /tmp/sess_*).
session_save_path('/tmp');

// config.php already calls session_start() on its own session ID, close it
// before binding a new one (session_id() is a no-op while a session is open).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Bind a fresh session id under our control.
$sid = bin2hex(random_bytes(16));
session_id($sid);
session_start();

$_SESSION['user_id']         = $user['id'];
$_SESSION['user_email']      = $user['email'];
$_SESSION['user_name']       = $user['name'];
$_SESSION['user_role']       = $user['role'];
$_SESSION['user_company_id'] = $user['company_id'] ?? null;

if (!empty($user['company_id'])) {
    $_SESSION['company_id'] = $user['company_id'];
    $company = $db->fetchOne('SELECT slug, name FROM companies WHERE id = :i', ['i' => $user['company_id']]);
    if ($company) {
        $_SESSION['company_slug'] = $company['slug'];
        $_SESSION['company_name'] = $company['name'];
    }
}

session_write_close();

// PHP-FPM runs as www:www, the session file we just wrote is root:root.
// Chown so PHP-FPM can read it on the next HTTP request.
$sessFile = '/tmp/sess_' . $sid;
if (is_file($sessFile) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($sessFile, 'www');
    @chgrp($sessFile, 'www');
    @chmod($sessFile, 0600);
}

echo $sid . "\n";
