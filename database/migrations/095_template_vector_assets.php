<?php
/**
 * Migration 095: track vector-source availability per template.
 *
 *   has_vector_source TINYINT(1) DEFAULT 0
 *     1 when scripts/parse_card_pdf.py exported a usable bg-page-N.svg
 *     and source.pdf has at least one embedded TTF font.
 *
 *   fonts_dir VARCHAR(500) NULL
 *     Web-relative path to the directory holding extracted TTF fonts
 *     for this template (e.g. /uploads/templates/imports/<token>/fonts).
 *     NULL until extract-template-fonts.py runs for the template.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(
        "ALTER TABLE templates
         ADD COLUMN IF NOT EXISTS has_vector_source TINYINT(1) NOT NULL DEFAULT 0
                 AFTER current_version,
         ADD COLUMN IF NOT EXISTS fonts_dir VARCHAR(500) NULL
                 AFTER has_vector_source"
    );
    echo "Migration 095: templates.has_vector_source + fonts_dir added\n";
} catch (Throwable $e) {
    echo "Migration 095 failed: " . $e->getMessage() . "\n";
    exit(1);
}
