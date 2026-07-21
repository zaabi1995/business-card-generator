<?php
/**
 * Cron: re-attempt any Cardify print order stuck at erp_sync_status
 * 'quote_failed' / 'invoice_failed' (transient ERP outage, expired token,
 * timeout). Idempotent. Alerts Ali on WhatsApp (throttled to once/hour) when
 * failures persist, so a silent ERP-sync breakage surfaces instead of hiding.
 *
 * Suggested crontab (every 15 min):
 *   0,15,30,45 * * * * /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/erp-sync-backfill.php >> /var/log/cardify-erp-backfill.log 2>&1
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ERPSync.php';

$r = ERPSync::backfillFailedSyncs(100);
$data = $r['data'] ?? [];
echo date('c') . ' backfill: ' . json_encode($data) . "\n";

$stillFailed = (int)($data['still_failed'] ?? 0);
if ($stillFailed > 0) {
    // Throttle the alert to once per hour so a persistent outage does not spam.
    $marker = sys_get_temp_dir() . '/cardify-erp-backfill-alert';
    $last = is_file($marker) ? (int)@file_get_contents($marker) : 0;
    if (time() - $last > 3600) {
        @file_put_contents($marker, (string)time());
        try {
            require_once INCLUDES_DIR . '/WhatsApp.php';
            if (class_exists('WhatsApp') && method_exists('WhatsApp', 'sendMessage')) {
                WhatsApp::sendMessage(
                    '96871616161',
                    "Cardify to ERP sync alert: {$stillFailed} order(s) still failing after retry. Check the ERP service token (erp_settings.erp_api_token in adminpasswords.loggedSessions) and the erp.bhd.om endpoints."
                );
            }
        } catch (Throwable $e) {
            error_log('erp-sync-backfill alert failed: ' . $e->getMessage());
        }
    }
}
