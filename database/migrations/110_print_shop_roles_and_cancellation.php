<?php
/**
 * Migration 110: print-shop role + tier system, plus order cancellation columns.
 *
 * Capabilities model (WordPress-style, simplest that fits):
 *
 *   print_shops.tier ENUM('admin','standard')
 *     - 'admin'    BHD-style internal provider. Cross-tenant browse,
 *                  cancel/refund authority, manage pricing across clients.
 *     - 'standard' Marketplace shops. Own-shop operations only. Cancel
 *                  authority gated to operator.role='admin'.
 *
 *   print_shop_operators.role ENUM('admin','operator','viewer')
 *     - 'admin'    Full power within the shop. Cancel, refund, manage
 *                  operators, manage pricing.
 *     - 'operator' Update orders, advance stages, view reports. No
 *                  cancel/refund. Default for newly invited operators.
 *     - 'viewer'   Read-only. For accountants/auditors.
 *
 * Permissions are the AND of (shop.tier, operator.role); see
 * PrintShopAuth::can() for the matrix.
 *
 * Cancellation columns on print_orders capture who cancelled, when, and why
 * so we have an audit trail per order. Refund tracking is in scope of a
 * later phase; columns are placeholder-only here.
 *
 * Backfill: BHD shop_id=2 -> tier='admin'; existing operators of any
 * shop -> role='admin' (since they were the shop owners or first invitees,
 * this matches their existing implicit privileges).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // 1. print_shops.tier
    $hasTier = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'print_shops'
           AND column_name = 'tier'"
    )->fetchColumn();
    if ($hasTier === 0) {
        $pdo->exec(
            "ALTER TABLE print_shops
             ADD COLUMN tier ENUM('admin','standard') NOT NULL DEFAULT 'standard'
             COMMENT 'admin = full power including cross-tenant + cancel + refund; standard = own-shop ops only'"
        );
        $pdo->exec("CREATE INDEX idx_pshops_tier ON print_shops (tier)");
        // Internal-provider shops are admin tier by definition. BHD is shop_id=2.
        $pdo->exec("UPDATE print_shops SET tier = 'admin' WHERE is_internal_provider = 1 OR id = 2");
        echo "[110] Added print_shops.tier + backfilled BHD/internal_provider shops to admin\n";
    } else {
        echo "[110] print_shops.tier already exists, skipping\n";
    }

    // 2. print_shop_operators.role
    $hasRole = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'print_shop_operators'
           AND column_name = 'role'"
    )->fetchColumn();
    if ($hasRole === 0) {
        $pdo->exec(
            "ALTER TABLE print_shop_operators
             ADD COLUMN role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator'
             COMMENT 'Within a shop: admin = full, operator = advance status only, viewer = read-only'"
        );
        $pdo->exec("CREATE INDEX idx_psop_role ON print_shop_operators (role)");
        // Existing operators are first-invitees / shop owners, treat them as admin.
        $pdo->exec("UPDATE print_shop_operators SET role = 'admin'");
        echo "[110] Added print_shop_operators.role + backfilled existing ops to admin\n";
    } else {
        echo "[110] print_shop_operators.role already exists, skipping\n";
    }

    // 3. print_orders cancellation audit columns
    $hasCancCols = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'print_orders'
           AND column_name = 'cancelled_at'"
    )->fetchColumn();
    if ($hasCancCols === 0) {
        $pdo->exec(
            "ALTER TABLE print_orders
             ADD COLUMN cancelled_at TIMESTAMP NULL
             COMMENT 'When the order was cancelled. NULL when not cancelled.',
             ADD COLUMN cancelled_by_operator_id VARCHAR(36) NULL
             COMMENT 'Operator who issued the cancellation. NULL if cancelled via system / customer.',
             ADD COLUMN cancellation_reason TEXT NULL
             COMMENT 'Free-text reason captured at cancel time. Required by the UI.'"
        );
        $pdo->exec("CREATE INDEX idx_pord_cancelled_at ON print_orders (cancelled_at)");
        echo "[110] Added print_orders.cancelled_at / cancelled_by_operator_id / cancellation_reason\n";
    } else {
        echo "[110] print_orders cancellation columns already exist, skipping\n";
    }

    echo "[110] OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[110] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
