<?php
/**
 * Migration 172: the email thread a card job belongs to.
 *
 * Ali, 30 Sep 2026: "ensure it is 100% reply as in the same thread". A job that
 * started from a client email (Kharusi, Paul Moses) got Cardify's approval and
 * quotation emails as NEW threads with a different subject. With these columns
 * every Cardify email for the job replies inside the client's own thread.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    foreach ([['thread_message_id', "VARCHAR(255) NULL DEFAULT NULL"],
              ['thread_references', "TEXT NULL DEFAULT NULL"],
              ['thread_subject', "VARCHAR(255) NULL DEFAULT NULL"]] as [$col, $def]) {
        if ($db->columnExists('card_requests', $col)) {
            echo "Migration 172: card_requests.{$col} already present\n";
            continue;
        }
        $db->exec("ALTER TABLE `card_requests` ADD COLUMN `{$col}` {$def}");
        echo "Migration 172: added card_requests.{$col}\n";
    }
} catch (Exception $e) {
    echo "Migration 172 failed: " . $e->getMessage() . "\n";
    exit(1);
}
