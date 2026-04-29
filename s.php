<?php
/**
 * Branded Link Shortener, redirect handler.
 *
 * Route: /s/<slug>  →  302 to destination (with atomic click counter + event log).
 *
 * Mounted via .htaccess RewriteRule ^s/([A-Za-z0-9_-]+)/?$ s.php?slug=$1.
 *
 * Behaviour:
 *   - Unknown / expired / empty slug  → 404.
 *   - Atomic UPDATE increments click_count in one statement (no race).
 *   - Logs a 'short_link_click' event into card_events for analytics
 *     (company_id preserved; employee_id is not applicable, so we pass
 *     the slug in there so we can GROUP BY it later).
 *   - 302 so the underlying destination can be rotated without cache pinning.
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

// Basic validation, slug charset mirrors the admin-side regex.
if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $slug)) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $stmt = $pdo->prepare(
        "SELECT id, company_id, destination, expires_at, approved
           FROM short_links
          WHERE slug = :s
          LIMIT 1"
    );
    $stmt->execute([':s' => $slug]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$link) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }

    // Moderation gate: unapproved links return 404 (not a leaky 403) until a
    // super admin approves. Stops tenant admins from turning cardify.om into a
    // free phishing redirector. Existing links are grandfathered as approved=1
    // by migration 062 so this change is zero-impact for live traffic.
    if (empty($link['approved'])) {
        http_response_code(404);
        include __DIR__ . '/404.php';
        exit;
    }

    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        http_response_code(410); // Gone
        include __DIR__ . '/404.php';
        exit;
    }

    // Atomic click increment, no read-modify-write race.
    $upd = $pdo->prepare(
        "UPDATE short_links SET click_count = click_count + 1 WHERE id = :id"
    );
    $upd->execute([':id' => $link['id']]);

    // Best-effort analytics log (never blocks the redirect).
    try {
        CardAnalytics::log(
            $slug,                // employee_id column, we reuse it as slug bucket
            $link['company_id'],
            'short_link_click',
            substr($link['destination'], 0, 512)
        );
    } catch (Throwable $e) {
        error_log('short_link_click log failed: ' . $e->getMessage());
    }

    // Final hardening: refuse to emit a javascript:/data: destination, even if
    // a legacy row somehow escaped the admin-side validator.
    $dest = (string) $link['destination'];
    $scheme = strtolower(parse_url($dest, PHP_URL_SCHEME) ?: '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        http_response_code(400);
        echo 'Invalid destination.';
        exit;
    }

    header('Cache-Control: private, no-store, max-age=0');
    header('Location: ' . $dest, true, 302);
    exit;
} catch (Throwable $e) {
    error_log('short-link redirect failed: ' . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/500.php';
    exit;
}
