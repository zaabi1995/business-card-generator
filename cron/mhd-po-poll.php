<?php
/**
 * Poll the sales mailbox for MHD purchase-order replies.
 *
 * Runs every two minutes. Prints one line per reply that concerns a card job;
 * messages that have nothing to do with the flow are recorded and stay silent,
 * so the log is readable.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PoInbox.php';
require_once INCLUDES_DIR . '/CardJob.php';

$limit = isset($argv[1]) ? (int)$argv[1] : 50;
$days  = isset($argv[2]) ? (int)$argv[2] : 3;
foreach (PoInbox::poll($limit, $days) as $r) {
    echo date('c') . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
