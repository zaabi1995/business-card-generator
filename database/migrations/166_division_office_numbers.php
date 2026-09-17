<?php
/**
 * Migration 166: office tel and fax become the person's to edit, prefilled per division.
 *
 * MHD's division cards baked the office tel and fax as fixed text, but MHD staff
 * print their own direct lines: Building Materials cards read 24794655 while
 * Infrastructure cards carry four different numbers, and Healthcare prints both
 * 24833500 and 24835500. The division keeps a default (departments.office_*), the
 * portal prefills it, and the person can change it.
 *
 * employees already has phone / phone_ar / fax / fax_ar. A second tel line needs
 * phone_2 / phone_2_ar.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $add = [
        ['departments', 'office_tel1', "VARCHAR(32) NULL DEFAULT NULL COMMENT 'Default office tel printed on the card'"],
        ['departments', 'office_tel2', "VARCHAR(32) NULL DEFAULT NULL COMMENT 'Default second office tel, for two-tel layouts'"],
        ['departments', 'office_fax',  "VARCHAR(32) NULL DEFAULT NULL COMMENT 'Default office fax printed on the card'"],
        ['employees',   'phone_2',     "VARCHAR(50) NULL DEFAULT NULL"],
        ['employees',   'phone_2_ar',  "VARCHAR(50) NULL DEFAULT NULL"],
    ];
    foreach ($add as [$table, $col, $def]) {
        if ($db->columnExists($table, $col)) {
            echo "Migration 166: {$table}.{$col} already present\n";
            continue;
        }
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
        echo "Migration 166: added {$table}.{$col}\n";
    }
} catch (Exception $e) {
    echo "Migration 166 failed: " . $e->getMessage() . "\n";
    exit(1);
}
