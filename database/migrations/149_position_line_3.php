<?php
/**
 * Third job-title line (role / department / sector).
 *
 * Gulf corporate cards routinely carry a three-line designation: the role,
 * then the department, then the sector. Al Maha Petroleum's 2026 portrait
 * card is the first tenant that needs all three, and MHD already uses two.
 * Additive and nullable, so every existing tenant is untouched.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $cols = $db->fetchAll("SHOW COLUMNS FROM employees LIKE 'position_%_3'");
    $have = array_column($cols ?: [], 'Field');

    if (!in_array('position_en_3', $have, true)) {
        $db->exec("ALTER TABLE employees
                   ADD COLUMN position_en_3 VARCHAR(255) NULL DEFAULT NULL AFTER position_en_2");
        echo "Migration 149: employees.position_en_3 added\n";
    } else {
        echo "Migration 149: employees.position_en_3 already present\n";
    }

    if (!in_array('position_ar_3', $have, true)) {
        $db->exec("ALTER TABLE employees
                   ADD COLUMN position_ar_3 VARCHAR(255) NULL DEFAULT NULL AFTER position_ar_2");
        echo "Migration 149: employees.position_ar_3 added\n";
    } else {
        echo "Migration 149: employees.position_ar_3 already present\n";
    }
} catch (Exception $e) {
    echo "Migration 149 failed: " . $e->getMessage() . "\n";
    exit(1);
}
