<?php
/**
 * Unified Logout.
 *
 * POST + valid CSRF token: logs the user out.
 * GET (same-origin link from the app): renders a tiny auto-submit POST form
 *   so the actual logout still happens via POST+CSRF. This preserves the
 *   existing <a href="logout.php"> UX everywhere while blocking cross-site
 *   CSRF logout attacks such as <img src="/logout.php"> or malicious link
 *   previews. SameSite=Lax session cookies already provide a first line of
 *   defense; this is the second.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . getBasePath() . 'login.php?err=csrf');
        exit;
    }
    if (Auth::isLoggedIn() && isset($_SESSION['user_id'])) {
        AuditLog::log('logout', 'user', $_SESSION['user_id'], null, [
            'email' => $_SESSION['user_email'] ?? 'unknown',
            'role'  => $_SESSION['user_role'] ?? 'unknown',
        ], $_SESSION['company_id'] ?? null);
    }
    // Clear print-shop operator session keys and the 30-day remember-me
    // cookie before the global Auth::logout wipes the session.
    PrintShopAuth::logout();
    if (isset($_COOKIE['pso_remember'])) {
        $secure = !empty($_SERVER['HTTPS']);
        setcookie('pso_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/printshop/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['pso_remember']);
    }
    Auth::logout();
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$csrf = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
$action = htmlspecialchars(getBasePath() . 'logout.php', ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Signing out</title>
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="same-origin">
<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;color:#374151;background:#f9fafb}</style>
</head><body>
<form id="lo" method="POST" action="<?= $action ?>">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <noscript><button type="submit">Click to sign out</button></noscript>
</form>
<p>Signing out&hellip;</p>
<script>document.getElementById('lo').submit();</script>
</body></html>
