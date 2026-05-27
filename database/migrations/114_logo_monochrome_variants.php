<?php
/**
 * Monochrome brand variants. One upload auto-fans out to:
 *   - dark   SVG + PNG + WebP (black recolor, for placement on light bgs)
 *   - white  SVG + PNG + WebP (white recolor, for placement on dark bgs)
 *
 * The default-color (original) columns remain untouched.
 * /companies/<slug> renders the right variant by background; the grid
 * card auto-flips when a light-on-white logo would be invisible.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $columns = [
        ['logo_svg_dark_path',   'VARCHAR(255) NULL'],
        ['logo_svg_white_path',  'VARCHAR(255) NULL'],
        ['logo_png_dark_path',   'VARCHAR(255) NULL'],
        ['logo_png_white_path',  'VARCHAR(255) NULL'],
        ['logo_webp_dark_path',  'VARCHAR(255) NULL'],
        ['logo_webp_white_path', 'VARCHAR(255) NULL'],
        ['logo_variants_at',     'TIMESTAMP NULL'],
    ];

    foreach ($columns as [$name, $type]) {
        try {
            $db->exec("ALTER TABLE om_companies ADD COLUMN $name $type AFTER logo_webp_path");
            echo "  + om_companies.$name\n";
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate column') !== false) {
                echo "  ~ om_companies.$name already exists\n";
                continue;
            }
            throw $e;
        }
    }

    echo "Migration 114: monochrome variant columns ready\n";
} catch (Exception $e) {
    echo "Migration 114 failed: " . $e->getMessage() . "\n";
    exit(1);
}
