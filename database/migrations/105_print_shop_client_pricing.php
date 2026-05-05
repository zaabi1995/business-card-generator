<?php
/**
 * Migration 105: print_shop_client_pricing
 *
 * Per-(print_shop, client_company) pricing overrides. Lets a print
 * shop give specific clients special prices, e.g. BHD honoring a
 * negotiated quote with Otech, without forking the whole shop tier
 * table. When an override row exists for (shop, company), it
 * fully replaces the shop's default `print_shops.pricing` JSON for
 * that company. Missing row falls back to shop default.
 *
 * Pricing JSON shape mirrors print_shops.pricing exactly:
 *   {
 *     "quantity_tiers": {
 *       "100":  {"price": 5.000, "per_card": 0.050},
 *       "500":  {"price": 20.000,"per_card": 0.040},
 *       "1000": {"price": 35.000,"per_card": 0.035}
 *     },
 *     "setup_fee": 0,
 *     "shipping_base": 0,
 *     "currency": "OMR"
 *   }
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'print_shop_client_pricing'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec("
            CREATE TABLE print_shop_client_pricing (
                id            VARCHAR(36) PRIMARY KEY,
                print_shop_id INT NOT NULL,
                company_id    VARCHAR(36) NOT NULL,
                pricing       JSON NOT NULL,
                notes         TEXT NULL,
                created_by    VARCHAR(36) NULL,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_shop_company (print_shop_id, company_id),
                KEY idx_company (company_id),
                KEY idx_shop (print_shop_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[105] Created print_shop_client_pricing\n";
    } else {
        echo "[105] print_shop_client_pricing already exists, skipping\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[105] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
