<?php
/**
 * Cardify payment-retry worker (Cat S action 455).
 *
 * Cron: every hour. Picks due rows from payment_retries, creates a
 * fresh Paymob intent, WhatsApp+emails the customer a new checkout
 * link, and advances the backoff ladder until the 3 attempts are
 * used up (~7 days).
 *
 *   Cron: 15 * * * * /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/payment-retry.php >> /var/log/cardify-payment-retry.log 2>&1
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PaymentRetry.php';

$start = microtime(true);
$out = PaymentRetry::runQueue(20);
$ms  = (int) round((microtime(true) - $start) * 1000);

printf(
    "[%s] payment-retry processed=%d succeeded_send=%d exhausted=%d errors=%d (%dms)\n",
    date('Y-m-d H:i:s'),
    $out['processed'], $out['succeeded_send'], $out['exhausted'], $out['errors'], $ms
);
