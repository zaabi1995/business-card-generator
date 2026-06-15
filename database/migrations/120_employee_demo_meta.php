<?php
/**
 * Migration 120: employees.demo_meta
 *
 * Adds a per-employee JSON blob for instant/demo cards created from the
 * homepage hero funnel: { brand_color, verified, source }. Used ONLY by the
 * `demo` sandbox tenant; real tenants never set it. No impact on existing rows.
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'demo_meta'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN demo_meta JSON DEFAULT NULL");
        echo "120: added employees.demo_meta\n";
    } else {
        echo "120: employees.demo_meta already present, skipping\n";
    }
    echo "Migration 120 complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration 120 failed: " . $e->getMessage() . "\n");
    exit(1);
}
