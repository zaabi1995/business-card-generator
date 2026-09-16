<?php
/**
 * Prerequisites the MHD flow cannot work without. Found by reading the code
 * rather than trusting the plan, which had assumed all four already existed.
 *
 * 1. card_requests.status is ENUM('pending','approved','rejected') and nothing
 *    ever widened it, so the six fulfilment states could not be stored at all.
 *    Rather than widen that ENUM, which admin/requests.php filters and counts on,
 *    the fulfilment state gets its own column. Approval status and fulfilment
 *    progress are different questions and now have different answers.
 *
 * 2. print_orders has no erp_client_name, and ERPSync resolves the client by
 *    joining companies, never departments. Every MHD division would therefore
 *    quote against the single parent-company account. The order now carries the
 *    division's own client name, which ERPSync already prefers when present.
 *
 * 3. admin_approval_tokens has no purpose column, so a token minted to approve a
 *    card request and one minted to sign a delivery note are the same object and
 *    either endpoint would accept either. The default backfills every existing
 *    row to today's only meaning.
 *
 * 4. print_orders.department_id, so a card job can be traced back to its division
 *    without going through card_requests.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $add = function (string $table, string $col, string $ddl) use ($db) {
        if (!$db->columnExists($table, $col)) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
            echo "Migration 160: {$table}.{$col} added\n";
        } else {
            echo "Migration 160: {$table}.{$col} already present\n";
        }
    };

    $add('card_requests', 'fulfilment_state',
        "`fulfilment_state` VARCHAR(24) NOT NULL DEFAULT 'submitted'");
    $add('print_orders', 'erp_client_name', "`erp_client_name` VARCHAR(190) NULL");
    $add('print_orders', 'department_id',   "`department_id` VARCHAR(36) NULL");
    $add('admin_approval_tokens', 'purpose',
        "`purpose` VARCHAR(40) NOT NULL DEFAULT 'card_request'");

    $idx = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'admin_approval_tokens'
            AND index_name = 'idx_aat_request_purpose'");
    if ((int)($idx['c'] ?? 0) === 0) {
        $db->exec("CREATE INDEX `idx_aat_request_purpose`
                     ON `admin_approval_tokens` (`request_id`, `purpose`)");
        echo "Migration 160: admin_approval_tokens purpose index added\n";
    }

    // Existing rows predate the flow. An approved request is already past the
    // approval gate, so start it at 'approved'; everything else at 'submitted'.
    $db->exec("UPDATE card_requests SET fulfilment_state = 'approved'
                WHERE status = 'approved' AND fulfilment_state = 'submitted'");
    $db->exec("UPDATE card_requests SET fulfilment_state = 'rejected'
                WHERE status = 'rejected' AND fulfilment_state = 'submitted'");

    echo "Migration 160: done\n";
} catch (Exception $e) {
    echo "Migration 160 failed: " . $e->getMessage() . "\n";
    exit(1);
}
