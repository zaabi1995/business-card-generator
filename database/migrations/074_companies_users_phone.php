<?php
/**
 * Migration 074: Add phone columns to companies + users.
 *
 * Cardify captures no phone numbers anywhere today. This blocks WhatsApp-first
 * outreach (BHD-218 wave-1 was forced to pivot to email). The register form
 * already collects a phone field but has nowhere to persist it.
 *
 * - companies.phone VARCHAR(32) — admin contact phone for the company
 * - users.phone     VARCHAR(32) — personal phone for the admin user
 *
 * Both nullable, E.164 format, unindexed (phone-lookups are rare).
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $tables = [
        'companies' => ['phone' => "VARCHAR(32) NULL"],
        'users'     => ['phone' => "VARCHAR(32) NULL"],
    ];

    foreach ($tables as $table => $columns) {
        $existing = [];
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $existing[$r['Field']] = true; }

        foreach ($columns as $name => $def) {
            if (isset($existing[$name])) {
                echo "[074] $table.$name exists — skipped\n";
                continue;
            }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $def");
            echo "[074] added $table.$name\n";
        }
    }

    return ['success' => true];
} catch (Throwable $e) {
    echo "[074] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
