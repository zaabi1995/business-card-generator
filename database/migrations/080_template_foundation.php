<?php
/**
 * Migration 080: template schema foundation for Category F
 * (template editor upgrades, Cardify v2.0 sprint actions 151-180).
 *
 * Adds:
 *   - companies.default_front_template_id  (company-wide default)
 *   - companies.default_back_template_id
 *   - templates.description, tags (JSON), industry, locked_at,
 *     archived_at, current_version
 *   - template_versions table (full version history for revert support)
 *   - generated_cards.template_version_id (pins the version used)
 *
 * All columns nullable to keep the migration forward-compatible. Once
 * the editor UI ships, existing rows back-fill as they're edited.
 *
 * Departments already have template_front_id + template_back_id
 * columns (for department-level overrides), so this migration leaves
 * that table untouched.
 */
require_once __DIR__ . '/../../config.php';

/**
 * Add a column only if it doesn't already exist, quiet on duplicate.
 */
function addColumnIfMissing(Database $db, string $table, string $columnDef): void
{
    // $columnDef starts with the column name, e.g. "foo VARCHAR(255) NULL"
    preg_match('/^\s*(\w+)/', $columnDef, $m);
    $col = $m[1] ?? null;
    if (!$col) throw new RuntimeException("Bad column def: $columnDef");

    $exists = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
        ['t' => $table, 'c' => $col]
    );
    if ((int)($exists['c'] ?? 0) > 0) return;

    $db->exec("ALTER TABLE `$table` ADD COLUMN $columnDef");
}

try {
    $db = Database::getInstance();

    // 1. companies.default_front_template_id + default_back_template_id
    addColumnIfMissing($db, 'companies', 'default_front_template_id VARCHAR(100) NULL');
    addColumnIfMissing($db, 'companies', 'default_back_template_id VARCHAR(100) NULL');

    // 2. templates metadata
    addColumnIfMissing($db, 'templates', 'description TEXT NULL');
    addColumnIfMissing($db, 'templates', 'tags JSON NULL');
    addColumnIfMissing($db, 'templates', 'industry VARCHAR(64) NULL');
    addColumnIfMissing($db, 'templates', 'locked_at TIMESTAMP NULL');
    addColumnIfMissing($db, 'templates', 'archived_at TIMESTAMP NULL');
    addColumnIfMissing($db, 'templates', 'current_version INT UNSIGNED NOT NULL DEFAULT 1');

    // 3. template_versions history
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS template_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id VARCHAR(100) NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            fields_json LONGTEXT NULL,
            settings_json LONGTEXT NULL,
            background_image_path VARCHAR(500) NULL,
            created_by VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            change_summary VARCHAR(255) NULL,
            UNIQUE KEY uniq_template_version (template_id, version_number),
            INDEX idx_template (template_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // 4. generated_cards pins the template version used
    addColumnIfMissing($db, 'generated_cards', 'front_template_version INT UNSIGNED NULL');
    addColumnIfMissing($db, 'generated_cards', 'back_template_version INT UNSIGNED NULL');

    // 5. Seed version 1 for every existing template so current rows have
    //    a history baseline.
    $db->exec(<<<'SQL'
        INSERT INTO template_versions (template_id, version_number, fields_json, settings_json, background_image_path, created_at, change_summary)
        SELECT t.id, 1, t.fields_json, t.settings_json, t.background_image_path, COALESCE(t.created_at, NOW()), 'initial-snapshot-at-migration-080'
        FROM templates t
        WHERE NOT EXISTS (
            SELECT 1 FROM template_versions v WHERE v.template_id = t.id AND v.version_number = 1
        )
SQL);

    // 6. Backfill companies.default_front/back_template_id from the
    //    newest active "front"/"back" template per company so the
    //    default applies immediately without any admin action.
    $db->exec(<<<'SQL'
        UPDATE companies c
        SET default_front_template_id = (
            SELECT t.id FROM templates t
            WHERE t.company_id = c.id AND t.side = 'front' AND t.is_active = 1 AND t.archived_at IS NULL
            ORDER BY t.updated_at DESC LIMIT 1
        )
        WHERE default_front_template_id IS NULL
SQL);
    $db->exec(<<<'SQL'
        UPDATE companies c
        SET default_back_template_id = (
            SELECT t.id FROM templates t
            WHERE t.company_id = c.id AND t.side = 'back' AND t.is_active = 1 AND t.archived_at IS NULL
            ORDER BY t.updated_at DESC LIMIT 1
        )
        WHERE default_back_template_id IS NULL
SQL);

    echo "Migration 080: template foundation ready (companies defaults + templates metadata + versions + generated_cards version pin; seeded baseline v1 + backfilled defaults)\n";
} catch (Exception $e) {
    echo "Migration 080 failed: " . $e->getMessage() . "\n";
    exit(1);
}
