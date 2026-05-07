<?php
/**
 * Legacy /company/logout.php redirect.
 *
 * The canonical logout endpoint is /logout.php (root). It handles CSRF +
 * the auto-submit-POST trick that defeats logout-CSRF (caught 2026-05-07
 * E2E loop iter 34: this file was GET-callable with no CSRF check, so any
 * cross-site `<img src="...company/logout.php">` or link-preview prefetch
 * would log the user out).
 *
 * Plus it called logoutCompanyAdmin() which is undefined anywhere in the
 * codebase, so the original file would have errored out under any user
 * actually hitting it. Kept the file as a redirect for backwards-compat
 * in case any old bookmark / docs page links here.
 */
require_once __DIR__ . '/../config.php';

header('Location: ' . getBasePath() . 'logout.php', true, 302);
exit;
