<?php
/**
 * Cardify ERP sync retry worker (Cat S action 443).
 *
 * Runs ERPSync::runQueue() once, meant to be invoked by cron every
 * minute. Picks up to 20 due retry rows, calls recordPayment() again,
 * and updates status based on the outcome. Each failed attempt bumps
 * attempts + next_retry_at; after the last slot in the ladder the row
 * flips to 'exhausted' and stops getting picked up.
 *
 *   Cron line (every minute):
 *   * * * * * /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/erp-retry.php >> /var/log/cardify/erp-retry.log 2>&1
 *
 * Exits 0 even on per-row failures so cron doesn't spam MAILTO.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ERPSync.php';

$start = microtime(true);
$out   = ERPSync::runQueue(20);
$ms    = (int) round((microtime(true) - $start) * 1000);

printf(
    "[%s] erp-retry processed=%d succeeded=%d failed=%d (%dms)\n",
    date('Y-m-d H:i:s'),
    $out['processed'],
    $out['succeeded'],
    $out['failed'],
    $ms
);
