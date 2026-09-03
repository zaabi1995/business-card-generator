<?php
/**
 * Migration 155: print_shop_companies
 *
 * Lets a print partner create or be attached to client company
 * tenants. Regular shops only see rows they own. BHD's internal
 * provider flag is unchanged and still lists every company.
 *
 * No prices, commissions, or marketplace listing changes.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'print_shop_companies'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec("
            CREATE TABLE print_shop_companies (
                id            VARCHAR(36)  NOT NULL,
                print_shop_id INT          NOT NULL,
                company_id    VARCHAR(36)  NOT NULL,
                source        VARCHAR(16)  NOT NULL DEFAULT 'created',
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_shop_company (print_shop_id, company_id),
                KEY idx_company (company_id),
                KEY idx_shop (print_shop_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[155] Created print_shop_companies\n";
    } else {
        echo "[155] print_shop_companies already exists, skipping\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[155] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
