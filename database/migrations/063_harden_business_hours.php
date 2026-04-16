<?php
/**
 * Migration 063: employee_business_hours cascading FKs (Codex finding)
 *
 * Migration 058 created employee_business_hours WITHOUT a FK on employee_id,
 * so deleting an employee leaves orphan weekly-schedule rows. Add a CASCADE
 * FK so rows disappear with their employee.
 *
 * Also clean up any orphan rows first so the FK can be added.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    // Check table exists before touching it (058 should have created it).
    $tableExists = $pdo->query("SHOW TABLES LIKE 'employee_business_hours'")->rowCount() > 0;
    if (!$tableExists) {
        echo "[063] employee_business_hours missing — run 058 first. Skipping.\n";
        exit(0);
    }

    // Already has the FK?
    $fk = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'employee_business_hours'
            AND CONSTRAINT_NAME = 'fk_ebh_employee'"
    )->fetchColumn();

    if ($fk) {
        echo "[063] fk_ebh_employee already present — skipped\n";
        echo "[063] Migration complete\n";
        exit(0);
    }

    // Delete orphan rows (employee_id with no matching employees.id) so the
    // FK can be added cleanly.
    $orphans = $pdo->exec(
        "DELETE ebh FROM employee_business_hours ebh
          LEFT JOIN employees e ON e.id = ebh.employee_id
          WHERE e.id IS NULL"
    );
    echo "[063] removed $orphans orphan business_hours rows\n";

    $pdo->exec(
        "ALTER TABLE employee_business_hours
           ADD CONSTRAINT fk_ebh_employee
               FOREIGN KEY (employee_id) REFERENCES employees(id)
               ON DELETE CASCADE ON UPDATE CASCADE"
    );
    echo "[063] fk_ebh_employee added (CASCADE on delete/update)\n";

    echo "[063] Migration complete\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[063] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
