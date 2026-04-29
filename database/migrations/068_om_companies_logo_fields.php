<?php
/**
 * Migration 068: Add logo-library columns to om_companies.
 *
 * Additive only. Existing `logo_url` column left in place (deprecated, kept
 * as a quick-thumbnail pointer). New columns power the full library UX.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $columns = [
        'logo_svg_path'            => "VARCHAR(255) NULL",
        'logo_png_path'            => "VARCHAR(255) NULL",
        'logo_png_512_path'        => "VARCHAR(255) NULL",
        'logo_png_2048_path'       => "VARCHAR(255) NULL",
        'logo_webp_path'           => "VARCHAR(255) NULL",
        'logo_source_url'          => "TEXT NULL",
        'logo_status'              => "ENUM('none','indexed','pending','verified','disputed','takedown') NOT NULL DEFAULT 'none'",
        'logo_source'              => "ENUM('2oman_net','company_web','user_upload','admin_upload') NULL",
        'logo_dominant_color'      => "CHAR(7) NULL",
        'logo_width'               => "INT NULL",
        'logo_height'              => "INT NULL",
        'logo_claimed_by_user_id'  => "VARCHAR(36) NULL",
        'logo_claimed_at'          => "DATETIME NULL",
        'logo_verified_at'         => "DATETIME NULL",
        'logo_updated_at'          => "DATETIME NULL",
        'website_domain_cache'     => "VARCHAR(255) NULL",
    ];

    $existing = [];
    $rows = $pdo->query("SHOW COLUMNS FROM om_companies")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $existing[$r['Field']] = true; }

    foreach ($columns as $name => $def) {
        if (isset($existing[$name])) {
            echo "[068] $name exists — skipped\n";
            continue;
        }
        $pdo->exec("ALTER TABLE om_companies ADD COLUMN `$name` $def");
        echo "[068] added $name\n";
    }

    $indexes = [
        'idx_logo_status'          => 'logo_status',
        'idx_logo_verified_at'     => 'logo_verified_at',
        'idx_website_domain_cache' => 'website_domain_cache',
    ];
    $existingIdx = [];
    foreach ($pdo->query("SHOW INDEX FROM om_companies")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingIdx[$r['Key_name']] = true;
    }
    foreach ($indexes as $idx => $col) {
        if (isset($existingIdx[$idx])) {
            echo "[068] index $idx exists — skipped\n";
            continue;
        }
        $pdo->exec("ALTER TABLE om_companies ADD INDEX `$idx` (`$col`)");
        echo "[068] added index $idx\n";
    }

    return ['success' => true];
} catch (Throwable $e) {
    echo "[068] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
