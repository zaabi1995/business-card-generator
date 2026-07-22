<?php
// database/migrations/138_card_third_language.php
// Optional per-tenant THIRD language for the digital card (on top of EN/AR).
// Fully opt-in: a company with no third language configured is unchanged.
//   companies.ecard_third_lang        ISO code of the 3rd language (e.g. 'ms'), '' = none
//   companies.ecard_third_lang_label  short pill label (e.g. 'BM')
//   companies.ecard_third_lang_rtl    1 if the 3rd language is RTL
// Field values live in generic *_l3 employee columns so CardSections::tColumn
// reads them with the existing {base}_{locale} mechanism (locale 'l3'), with
// an English fallback. Generic slot = reusable for any language, no per-lang
// column bloat.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();

    $add = function ($table, $col, $ddl) use ($db) {
        if (!$db->columnExists($table, $col)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            echo "  + $table.$col\n";
        }
    };

    $add('companies', 'ecard_third_lang',       "ecard_third_lang VARCHAR(8) NOT NULL DEFAULT ''");
    $add('companies', 'ecard_third_lang_label', "ecard_third_lang_label VARCHAR(40) NOT NULL DEFAULT ''");
    $add('companies', 'ecard_third_lang_rtl',   "ecard_third_lang_rtl TINYINT(1) NOT NULL DEFAULT 0");

    foreach (['name_l3', 'position_l3', 'company_l3', 'address_l3', 'address_2_l3', 'website_l3'] as $c) {
        $add('employees', $c, "`$c` VARCHAR(500) NULL");
    }

    echo "Migration 138: third-language columns ready\n";
} catch (Exception $e) {
    echo "Migration 138 failed: " . $e->getMessage() . "\n";
    exit(1);
}
