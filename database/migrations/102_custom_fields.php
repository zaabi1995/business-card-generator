<?php
/**
 * Migration 102: company-defined custom fields.
 *
 * `companies.custom_fields`   JSON   definition list, e.g.
 *     [{"key":"employee_id","label":"Employee ID","label_ar":"الرقم الوظيفي","type":"text"}, ...]
 *
 * `employees.custom_fields`   JSON   per-employee key/value store
 *     {"employee_id":"EMP-1234","cost_center":"CC-19"}
 *
 * Both nullable; existing rows remain unaffected.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $hasCol = function (string $table, string $col) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $col]);
        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$hasCol('companies', 'custom_fields')) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN custom_fields JSON NULL");
        echo "[102] Added companies.custom_fields\n";
    } else {
        echo "[102] companies.custom_fields already exists\n";
    }

    if (!$hasCol('employees', 'custom_fields')) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN custom_fields JSON NULL");
        echo "[102] Added employees.custom_fields\n";
    } else {
        echo "[102] employees.custom_fields already exists\n";
    }

    echo "[102] Migration complete\n";
} catch (Exception $e) {
    echo "[102] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
