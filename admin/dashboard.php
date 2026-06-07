<?php
/**
 * Back-compat shim. The admin dashboard lives at index.php, not dashboard.php.
 * Older onboarding JS (cached in browsers before the auto_generate.php
 * return-target fix) and stale bookmarks pointed at dashboard.php?generated=1
 * and hit a 404. Redirect to index.php, preserving the query string, so those
 * in-flight sessions land on the real dashboard instead.
 */
require_once __DIR__ . '/../config.php';

$ext = (defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php';
$qs  = $_SERVER['QUERY_STRING'] ?? '';
$target = getAdminBasePath() . 'index' . $ext . ($qs !== '' ? '?' . $qs : '');

header('Location: ' . $target, true, 302);
exit;
