<?php
/**
 * Cron: Process email queue
 *
 * Sends pending scheduled emails (onboarding drip sequence, etc.)
 * Run every 15 minutes via cron.
 */


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Mailer.php';

$stats = Mailer::processQueue(20);

$total = $stats['sent'] + $stats['failed'];
if ($total > 0) {
    echo date('Y-m-d H:i:s') . " Email queue: sent={$stats['sent']}, failed={$stats['failed']}\n";
}
