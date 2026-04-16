<?php
/**
 * Migration 067: Hash bulk-claim magic tokens at rest.
 *
 * Codex round-3 finding #5 — storing raw magic_token in the DB means a
 * read-only leak (backup tape, SQL injection elsewhere, a stolen dump) is
 * an instant claim-capability leak for every un-claimed lead.
 *
 * Fix: add magic_token_hash (SHA-256 of the token), backfill it from the
 * existing plaintext column, and make the index unique. /claim-lead.php
 * now looks up by hash first and falls back to plaintext during the
 * transition window so mid-migration sends don't break.
 *
 * We keep the plaintext `magic_token` column for now so in-flight WA
 * messages continue to work. A follow-up migration can drop it once all
 * outstanding links have expired (14 days).
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $dbNameRow = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    $dbName    = $dbNameRow['db'] ?? null;

    // Skip entirely if bulk_claim_leads hasn't been created yet (fresh install).
    $hasTable = $pdo->query("SHOW TABLES LIKE 'bulk_claim_leads'")->rowCount() > 0;
    if (!$hasTable) {
        echo "[067] bulk_claim_leads table missing — run 066 first, skipping.\n";
        return ['success' => true];
    }

    // -------------------------------------------------------------------
    // 1. Add magic_token_hash column
    // -------------------------------------------------------------------
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'bulk_claim_leads'
           AND COLUMN_NAME  = 'magic_token_hash'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `bulk_claim_leads`
             ADD COLUMN `magic_token_hash` CHAR(64) NULL DEFAULT NULL
             COMMENT 'SHA-256 of magic_token; looked up by /claim-lead.php'
             AFTER `magic_token`"
        );
        echo "[067] Added bulk_claim_leads.magic_token_hash\n";
    } else {
        echo "[067] bulk_claim_leads.magic_token_hash already exists — skipped\n";
    }

    // -------------------------------------------------------------------
    // 2. Backfill hash from existing plaintext tokens.
    //    Safe & idempotent: only touches rows where hash is NULL.
    //    Uses MySQL's SHA2() so we never have to pull plaintext across the wire.
    // -------------------------------------------------------------------
    $updated = $pdo->exec(
        "UPDATE `bulk_claim_leads`
            SET `magic_token_hash` = SHA2(`magic_token`, 256)
          WHERE `magic_token_hash` IS NULL
            AND `magic_token` IS NOT NULL
            AND `magic_token` <> ''"
    );
    echo "[067] Backfilled magic_token_hash on {$updated} rows\n";

    // -------------------------------------------------------------------
    // 3. Add unique index on the hash so lookups stay O(1).
    //    Can't create it before the backfill because existing rows might
    //    collide on NULL across MySQL versions.
    // -------------------------------------------------------------------
    $idxCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'bulk_claim_leads'
           AND INDEX_NAME   = 'uniq_magic_token_hash'"
    );
    $idxCheck->execute([':db' => $dbName]);
    if ((int)$idxCheck->fetchColumn() === 0) {
        // UNIQUE allows multiple NULLs in MySQL, so this is safe even if some
        // rows are still pending backfill.
        $pdo->exec(
            "ALTER TABLE `bulk_claim_leads`
             ADD UNIQUE KEY `uniq_magic_token_hash` (`magic_token_hash`)"
        );
        echo "[067] Added UNIQUE index uniq_magic_token_hash\n";
    } else {
        echo "[067] UNIQUE index uniq_magic_token_hash already exists — skipped\n";
    }

    echo "[067] Migration complete\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[067] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
