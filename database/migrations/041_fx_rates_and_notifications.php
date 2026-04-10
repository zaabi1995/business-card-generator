<?php
/**
 * Migration 041 — fx_rates, notification_log, and free-forever pivot
 *
 * - Creates fx_rates table (1 OMR → target currency rates) with seed rows
 * - Creates notification_log table (unified email+WhatsApp audit trail)
 * - Zeros out all plan_prices rows (plans become free forever)
 * - Moves every existing company to the 'enterprise' tier (all features free)
 *
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // 1. fx_rates table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fx_rates (
        id VARCHAR(36) PRIMARY KEY,
        base_currency VARCHAR(3) NOT NULL DEFAULT 'OMR',
        target_currency VARCHAR(3) NOT NULL,
        rate DECIMAL(14, 6) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by_user_id VARCHAR(36) NULL,
        notes VARCHAR(255) NULL,
        UNIQUE KEY unique_pair (base_currency, target_currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed rows — OMR is pegged to USD, Gulf currencies derived via USD peg
    $seedRates = [
        ['USD', 2.598000, 'Pegged (1 OMR = 2.598 USD)'],
        ['AED', 9.543000, 'Derived via USD peg'],
        ['SAR', 9.742000, 'Derived via USD peg'],
        ['EUR', 2.400000, 'Floats; updated quarterly'],
    ];
    $insertRate = $pdo->prepare(
        "INSERT IGNORE INTO fx_rates (id, base_currency, target_currency, rate, notes)
         VALUES (UUID(), 'OMR', :target, :rate, :notes)"
    );
    foreach ($seedRates as [$target, $rate, $notes]) {
        $insertRate->execute(['target' => $target, 'rate' => $rate, 'notes' => $notes]);
    }

    // 2. notification_log table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_log (
        id VARCHAR(36) PRIMARY KEY,
        event VARCHAR(64) NOT NULL,
        company_id VARCHAR(36) NULL,
        recipient_email VARCHAR(255) NULL,
        recipient_phone VARCHAR(32) NULL,
        recipient_name VARCHAR(255) NULL,
        channels_attempted VARCHAR(64) NOT NULL,
        channels_succeeded VARCHAR(64) NULL,
        channels_failed VARCHAR(64) NULL,
        error_json TEXT NULL,
        context_json TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event (event),
        INDEX idx_company (company_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Zero out all plan_prices rows (plans are free forever)
    $pdo->exec("UPDATE plan_prices SET price_monthly = 0, price_yearly = 0");

    // 4. Move every company to the enterprise tier
    $pdo->exec("UPDATE companies SET plan = 'enterprise'");

    echo "Migration 041 complete:\n";
    echo "  fx_rates table created and seeded with USD/AED/SAR/EUR\n";
    echo "  notification_log table created\n";
    echo "  plan_prices zeroed out\n";
    echo "  companies moved to enterprise tier\n";
} catch (PDOException $e) {
    echo "Migration 041 FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
