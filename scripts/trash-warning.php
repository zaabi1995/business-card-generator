<?php
/**
 * 30-day trash warning cron.
 *
 * Scans the 6 soft-deletable tables (employees, departments, templates,
 * print_orders, credit_accounts, card_requests) for rows whose
 * deleted_at falls in the grace window and are:
 *
 *   - Seven days away from the 30-day purge threshold, so each warning
 *     gives the admin a week to restore anything important.
 *
 * Per (company_id, week-ending) pair is idempotent via audit_logs
 * entity_type='trash_warning' action='sent_YYYY-WW' so re-running the
 * cron the same week is a no-op.
 *
 * Pairs with the soft-delete cron (action 397) that actually purges
 * deleted_at < NOW() - 30 days rows.
 *
 * Install: daily at 08:00 Muscat.
 *   0 8 * * *  /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/trash-warning.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/AuditLog.php';

const TRASH_GRACE_DAYS     = 30;
const TRASH_WARN_LEAD_DAYS = 7;

$db = Database::getInstance();

// Items currently in the grace window.
$cutoffOldest = date('Y-m-d H:i:s', strtotime('-' . TRASH_GRACE_DAYS . ' days'));
$cutoffNewest = date('Y-m-d H:i:s', strtotime('-' . (TRASH_GRACE_DAYS - TRASH_WARN_LEAD_DAYS) . ' days'));

$targets = [
    'employees', 'departments', 'templates',
    'print_orders', 'credit_accounts', 'card_requests',
];

// Per-company breakdown of items within the warn window.
$byCompany = []; // [company_id => ['breakdown'=>[...], 'total'=>int, 'oldest'=>ts]]
foreach ($targets as $tbl) {
    // Only scan tables that actually exist on this install.
    $exists = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
        ['t' => $tbl]
    );
    if ((int) ($exists['c'] ?? 0) === 0) continue;

    try {
        $rows = $db->fetchAll(
            "SELECT company_id, COUNT(*) AS n, MIN(deleted_at) AS oldest
             FROM `{$tbl}`
             WHERE deleted_at BETWEEN :s AND :e
             GROUP BY company_id",
            ['s' => $cutoffOldest, 'e' => $cutoffNewest]
        );
    } catch (Throwable $e) {
        // Table might not have company_id (unlikely for these 6).
        continue;
    }
    foreach ($rows as $r) {
        $cid = $r['company_id'];
        if (!$cid) continue;
        if (!isset($byCompany[$cid])) {
            $byCompany[$cid] = ['breakdown' => [], 'total' => 0, 'oldest' => $r['oldest']];
        }
        $byCompany[$cid]['breakdown'][$tbl] = (int) $r['n'];
        $byCompany[$cid]['total'] += (int) $r['n'];
        if (dbTs($r['oldest']) < dbTs($byCompany[$cid]['oldest'])) {
            $byCompany[$cid]['oldest'] = $r['oldest'];
        }
    }
}

$weekKey = date('o-W'); // ISO week, same every 7 days
$sent = 0; $skipped = 0; $failed = 0; $noEmail = 0;

foreach ($byCompany as $companyId => $summary) {
    $company = $db->fetchOne(
        "SELECT id, name, admin_email, locale, brand_color, logo_url
         FROM companies WHERE id = :id",
        ['id' => $companyId]
    );
    if (!$company || empty($company['admin_email'])) { $noEmail++; continue; }

    // Idempotency per ISO week.
    $already = $db->fetchOne(
        "SELECT 1 FROM audit_logs WHERE entity_type = 'trash_warning'
           AND entity_id = :id AND action = :week LIMIT 1",
        ['id' => $companyId, 'week' => 'sent_' . $weekKey]
    );
    if ($already) { $skipped++; continue; }

    // Days remaining for the oldest item in the window.
    $daysElapsed = floor((time() - dbTs($summary['oldest'])) / 86400);
    $daysRemaining = max(1, TRASH_GRACE_DAYS - (int) $daysElapsed);

    $locale = ($company['locale'] === 'ar') ? 'ar' : 'en';
    $ctx = [
        'contactName'   => $company['name'],
        'companyName'   => $company['name'],
        'totalCount'    => (int) $summary['total'],
        'daysRemaining' => (int) $daysRemaining,
        'breakdown'     => $summary['breakdown'],
        'trashUrl'      => 'https://cardify.om/admin/trash',
        'brandColor'    => $company['brand_color'] ?? null,
        'logoUrl'       => $company['logo_url'] ?? null,
    ];
    $path = __DIR__ . "/../includes/notifications/templates/trash_warning.email.{$locale}.php";
    if (!is_file($path)) $path = __DIR__ . "/../includes/notifications/templates/trash_warning.email.en.php";

    extract($ctx, EXTR_SKIP);
    $subject = ''; $body = '';
    require $path;

    $ok = Mailer::send($company['admin_email'], $subject, $body);
    if ($ok) {
        $sent++;
        if (class_exists('AuditLog')) {
            try {
                AuditLog::log('sent_' . $weekKey, 'trash_warning', $companyId,
                    "Trash warning for week {$weekKey}: total={$summary['total']} days_remaining={$daysRemaining}");
            } catch (Throwable $e) { /* non-fatal */ }
        }
    } else {
        $failed++;
        error_log("[trash-warning] send failed for company {$companyId} <{$company['admin_email']}>");
    }
}

echo "[trash-warning] week={$weekKey} sent={$sent} skipped={$skipped} failed={$failed} no_email={$noEmail}\n";
