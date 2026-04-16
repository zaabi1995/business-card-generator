<?php
/**
 * Migration 057: Card Dark Mode Toggle
 *
 * Adds a per-employee flag that lets card owners decide whether visitors
 * are allowed to flip the public card between light and dark modes.
 *
 * Default: 1 (toggle exposed). Owners can disable to force their default theme.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Check for column presence via information_schema (works with utf8mb4_unicode_ci)
    $dbNameRow = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    $dbName = $dbNameRow['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'card_dark_mode_toggle'"
    );
    $check->execute([':db' => $dbName]);
    $exists = (int)$check->fetchColumn() > 0;

    if (!$exists) {
        $pdo->exec(
            "ALTER TABLE `employees`
             ADD COLUMN `card_dark_mode_toggle` TINYINT(1) NOT NULL DEFAULT 1
             COMMENT 'When 1, public card shows a sun/moon toggle that lets visitors override the default theme.'"
        );
        echo "[057] Added employees.card_dark_mode_toggle\n";
    } else {
        echo "[057] employees.card_dark_mode_toggle already exists — skipping\n";
    }

    echo "[057] Migration complete\n";
} catch (Exception $e) {
    echo "[057] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
