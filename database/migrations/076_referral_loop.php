<?php
/**
 * Migration 076: Referral loop MVP (BHD-234).
 *
 * Share link per user → /r/<code> → referred_by attribution → 3 months free
 * for the referrer on the referred user's first paid conversion.
 *
 * - users.referral_code        VARCHAR(16) UNIQUE NULL — auto-generated per user
 * - users.referred_by_user_id  CHAR(36)    NULL       — attribution at signup
 * - users.referred_at          DATETIME    NULL
 * - referral_events(table)                            — signup | paid_conversion | reward_granted
 *
 * Also backfills unique referral_code for every existing user.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    // --- users: referral columns -------------------------------------------------
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[$r['Field']] = true;
    }

    $userCols = [
        'referral_code'        => "VARCHAR(16) NULL",
        'referred_by_user_id'  => "CHAR(36) NULL",
        'referred_at'          => "DATETIME NULL",
    ];
    foreach ($userCols as $name => $def) {
        if (isset($existing[$name])) {
            echo "[076] users.$name exists — skipped\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `$name` $def");
        echo "[076] added users.$name\n";
    }

    // Unique index on referral_code (skip if present)
    $indexes = [];
    foreach ($pdo->query("SHOW INDEX FROM `users`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $indexes[$r['Key_name']] = true;
    }
    if (!isset($indexes['users_referral_code_unique'])) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `users_referral_code_unique` (`referral_code`)");
            echo "[076] added unique index users_referral_code_unique\n";
        } catch (Throwable $e) {
            echo "[076] unique index failed: " . $e->getMessage() . "\n";
        }
    }

    // Index on referred_by for admin lookups
    if (!isset($indexes['users_referred_by_idx'])) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD INDEX `users_referred_by_idx` (`referred_by_user_id`)");
            echo "[076] added index users_referred_by_idx\n";
        } catch (Throwable $e) {
            // ignore
        }
    }

    // --- referral_events ---------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `referral_events` (
            `id`               CHAR(36)     NOT NULL PRIMARY KEY,
            `referrer_user_id` CHAR(36)     NOT NULL,
            `referred_user_id` CHAR(36)     NULL,
            `referred_company_id` CHAR(36)  NULL,
            `event_type`       VARCHAR(32)  NOT NULL,
            `details`          TEXT         NULL,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `ref_events_referrer_idx` (`referrer_user_id`),
            INDEX `ref_events_type_idx` (`event_type`),
            INDEX `ref_events_company_idx` (`referred_company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[076] referral_events ready\n";

    // --- backfill existing users with referral codes -----------------------------
    // Unambiguous alphabet (no 0/O/1/I), 8 chars → ~2.8e12 codes → collision-safe.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $genCode = function () use ($alphabet) {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    };

    $rows = $pdo->query("SELECT id FROM `users` WHERE referral_code IS NULL OR referral_code = ''")
                ->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    $stmt = $pdo->prepare("UPDATE `users` SET referral_code = :code WHERE id = :id");
    foreach ($rows as $row) {
        // Retry on rare collision.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $stmt->execute([':code' => $genCode(), ':id' => $row['id']]);
                $updated++;
                break;
            } catch (Throwable $e) {
                if ($attempt === 4) {
                    echo "[076] could not assign code for user {$row['id']}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    echo "[076] backfilled referral_code for $updated users\n";

    return ['success' => true];
} catch (Throwable $e) {
    echo "[076] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
