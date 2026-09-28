<?php
/**
 * Migration 171: a second mobile number on the person.
 *
 * MHD's Khalid Al Zadjali carries two mobiles (95103696 and 72258263) and asked
 * for both at the top of his card (28 Sep 2026). employees had one mobile only.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    foreach ([['mobile_2', "VARCHAR(50) NULL DEFAULT NULL"], ['mobile_2_ar', "VARCHAR(50) NULL DEFAULT NULL"]] as [$col, $def]) {
        if ($db->columnExists('employees', $col)) {
            echo "Migration 171: employees.{$col} already present\n";
            continue;
        }
        $db->exec("ALTER TABLE `employees` ADD COLUMN `{$col}` {$def}");
        echo "Migration 171: added employees.{$col}\n";
    }
} catch (Exception $e) {
    echo "Migration 171 failed: " . $e->getMessage() . "\n";
    exit(1);
}
