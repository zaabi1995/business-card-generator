<?php
/**
 * Monthly analytics report cron.
 *
 * For each active company with at least one admin email and a
 * notifications preference (or none, default = on), computes last
 * calendar-month KPIs and dispatches the monthly_report.email.{locale}
 * template.
 *
 * Idempotent per (company_id, year-month): refuses to re-send for a
 * month that was already dispatched, recorded in audit_logs under
 * entity_type='monthly_report'.
 *
 * Run from cron on the 1st of each month at 07:00 Muscat:
 *   5 7 1 * *  /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/monthly-analytics-report.php
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/AuditLog.php';

$db = Database::getInstance();

// Previous calendar month, e.g. running Apr 1 → 'March 2026'
$periodStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
$periodEnd   = date('Y-m-t 23:59:59', strtotime('last day of last month'));
$monthKey    = date('Y-m', strtotime('last month'));
$monthLabelEn = date('F Y', strtotime('last month'));
// Simple Arabic month labels (no intl dep required)
$arMonths = [1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
$monthLabelAr = $arMonths[(int) date('n', strtotime('last month'))] . ' ' . date('Y', strtotime('last month'));

$companies = $db->fetchAll(
    "SELECT id, name, admin_email, locale, brand_color, logo_url
     FROM companies
     WHERE admin_email IS NOT NULL AND admin_email != ''
       AND COALESCE(status, 'active') = 'active'"
);

$sent = 0; $skipped = 0; $failed = 0;

foreach ($companies as $company) {
    $companyId = $company['id'];

    // Idempotency: skip if already dispatched this month
    $already = $db->fetchOne(
        "SELECT 1 FROM audit_logs WHERE entity_type = 'monthly_report'
           AND entity_id = :id AND action = :month LIMIT 1",
        ['id' => $companyId, 'month' => 'sent_' . $monthKey]
    );
    if ($already) { $skipped++; continue; }

    // KPIs
    $kpi = $db->fetchOne(
        "SELECT
            COUNT(DISTINCT CASE WHEN ce.event_type IN ('view','tap') THEN ce.id END) AS taps,
            COUNT(DISTINCT CASE WHEN ce.event_type = 'save_contact' THEN ce.id END) AS saves,
            COUNT(DISTINCT CASE WHEN ce.event_type = 'lead_submit' THEN ce.id END) AS leads,
            COUNT(DISTINCT ce.employee_id) AS active_cards
         FROM card_events ce
         JOIN employees e ON e.id = ce.employee_id
         WHERE e.company_id = :cid
           AND ce.created_at BETWEEN :s AND :e",
        ['cid' => $companyId, 's' => $periodStart, 'e' => $periodEnd]
    ) ?: ['taps'=>0,'saves'=>0,'leads'=>0,'active_cards'=>0];

    $newEmployees = (int) ($db->fetchOne(
        "SELECT COUNT(*) c FROM employees
         WHERE company_id = :cid AND created_at BETWEEN :s AND :e",
        ['cid' => $companyId, 's' => $periodStart, 'e' => $periodEnd]
    )['c'] ?? 0);

    $topEmployees = $db->fetchAll(
        "SELECT COALESCE(e.name_en, e.name_ar, e.email) AS name,
                COUNT(ce.id) AS taps
         FROM card_events ce
         JOIN employees e ON e.id = ce.employee_id
         WHERE e.company_id = :cid AND ce.created_at BETWEEN :s AND :e
           AND ce.event_type IN ('view','tap')
         GROUP BY e.id
         ORDER BY taps DESC
         LIMIT 5",
        ['cid' => $companyId, 's' => $periodStart, 'e' => $periodEnd]
    ) ?: [];

    $locale = $company['locale'] === 'ar' ? 'ar' : 'en';
    $month  = $locale === 'ar' ? $monthLabelAr : $monthLabelEn;

    $ctx = [
        'contactName'  => $company['name'],
        'companyName'  => $company['name'],
        'month'        => $month,
        'taps'         => (int) $kpi['taps'],
        'saves'        => (int) $kpi['saves'],
        'leads'        => (int) $kpi['leads'],
        'activeCards'  => (int) $kpi['active_cards'],
        'newEmployees' => $newEmployees,
        'dashboardUrl' => 'https://cardify.om/admin/analytics',
        'topEmployees' => $topEmployees,
        'brandColor'   => $company['brand_color'] ?? null,
        'logoUrl'      => $company['logo_url'] ?? null,
    ];

    $path = __DIR__ . "/../includes/notifications/templates/monthly_report.email.{$locale}.php";
    if (!is_file($path)) $path = __DIR__ . "/../includes/notifications/templates/monthly_report.email.en.php";

    extract($ctx, EXTR_SKIP);
    $subject = ''; $body = '';
    require $path;

    $ok = Mailer::send($company['admin_email'], $subject, $body);

    if ($ok) {
        $sent++;
        if (class_exists('AuditLog')) {
            try {
                AuditLog::log('sent_' . $monthKey, 'monthly_report', $companyId,
                    "Monthly report dispatched for {$monthKey}: taps={$ctx['taps']} saves={$ctx['saves']} leads={$ctx['leads']}");
            } catch (Throwable $e) { /* non-fatal */ }
        }
    } else {
        $failed++;
        error_log("[monthly-report] send failed for company {$companyId} <{$company['admin_email']}>");
    }
}

echo "[monthly-report] {$monthKey}: sent={$sent} skipped={$skipped} failed={$failed}\n";
