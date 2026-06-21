<?php
/**
 * Migration 105: World Cup referrals + bonus points.
 * ref_code (each user's invite code), referred_by (who invited them),
 * bonus_points (referral/share points, counted into points_cache + prize race).
 */
require_once __DIR__ . '/../../config.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

function wc_col_exists($pdo, $table, $col){
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

if (!wc_col_exists($pdo,'wc_users','ref_code')) {
    $pdo->exec("ALTER TABLE wc_users ADD COLUMN ref_code VARCHAR(12) NULL");
    $pdo->exec("ALTER TABLE wc_users ADD UNIQUE KEY uq_ref_code (ref_code)");
    echo "[105] added ref_code\n";
}
if (!wc_col_exists($pdo,'wc_users','referred_by')) {
    $pdo->exec("ALTER TABLE wc_users ADD COLUMN referred_by BIGINT UNSIGNED NULL");
    $pdo->exec("ALTER TABLE wc_users ADD KEY idx_referred_by (referred_by)");
    echo "[105] added referred_by\n";
}
if (!wc_col_exists($pdo,'wc_users','bonus_points')) {
    $pdo->exec("ALTER TABLE wc_users ADD COLUMN bonus_points INT NOT NULL DEFAULT 0");
    echo "[105] added bonus_points\n";
}

// Backfill ref_code for existing rows.
$rows = $db->fetchAll("SELECT id FROM wc_users WHERE ref_code IS NULL OR ref_code=''");
foreach ($rows as $r) {
    $code = substr(strtoupper(bin2hex(random_bytes(6))), 0, 8);
    $db->update('wc_users', ['ref_code'=>$code], 'id=:id', ['id'=>$r['id']]);
}
echo "[105] backfilled ".count($rows)." ref_codes\n";
echo "[105] done\n";
