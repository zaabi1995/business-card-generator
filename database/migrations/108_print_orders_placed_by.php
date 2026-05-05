<?php
/**
 * Migration 108: print_orders.placed_by_operator_id
 *
 * Attribution column for orders placed by a print-shop operator
 * (Ali / Arshad / Hussain) acting on behalf of a client. NULL when
 * the order came through the customer's own admin flow.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $hasCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'print_orders'
           AND column_name = 'placed_by_operator_id'"
    )->fetchColumn();

    if ($hasCol === 0) {
        $pdo->exec(
            "ALTER TABLE print_orders
             ADD COLUMN placed_by_operator_id VARCHAR(36) NULL
             COMMENT 'When set, order was placed via print-shop on behalf of company'"
        );
        $pdo->exec("CREATE INDEX idx_pord_placed_by ON print_orders (placed_by_operator_id)");
        echo "[108] Added print_orders.placed_by_operator_id\n";
    } else {
        echo "[108] placed_by_operator_id already exists\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[108] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
