<?php
/**
 * Migration 104: employees.field_overrides
 *
 * Per-employee per-field rendering overrides. Lets HR override the
 * template's font-size / colour / weight for a single employee when
 * an edge case warrants it (very long name, brand-rep card with
 * gold ink instead of the default white, etc.) without forking the
 * whole template.
 *
 * Shape (JSON object keyed by field key):
 *   {
 *     "name_en":     {"fontSize": 18, "fill": "#ffffff"},
 *     "name_ar":     {"fontSize": 50},
 *     "position_ar": {"fill": "#c9a961", "fontWeight": 500}
 *   }
 *
 * Only the keys present in the override dict are merged on top of
 * the template field at render time; everything else falls through
 * to the template default. The renderer (portal.php +
 * generate_card_html.php) merges these into addTextField options.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $has = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'employees'
           AND column_name = 'field_overrides'"
    )->fetchColumn();

    if ((int) $has === 0) {
        $pdo->exec(
            "ALTER TABLE employees
             ADD COLUMN field_overrides JSON DEFAULT NULL
             COMMENT 'Per-field render overrides (fontSize, fill, fontWeight). NULL = use template defaults.'"
        );
        echo "[104] Added employees.field_overrides\n";
    } else {
        echo "[104] employees.field_overrides already exists, skipping\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[104] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
