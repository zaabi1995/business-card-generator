<?php
/**
 * Migration 124: wc_wallet_passes.pass_type
 *
 * The hub now issues TWO Apple Wallet passes per user:
 *   - 'player'  : points / rank / level / streak / next match (the original)
 *   - 'matches' : today's World Cup fixtures + yesterday's results, daily.
 *
 * One row per (user_id, pass_type). pass_type defaults to 'player' so the
 * existing single-pass rows keep their meaning. The unique key moves from
 * serial-only to (user_id, pass_type) for the lazy get-or-create, while serial
 * stays globally unique for the PassKit web service lookups.
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    $hasCol = function (string $col) use ($pdo, $dbName): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=:db AND TABLE_NAME='wc_wallet_passes' AND COLUMN_NAME=:c");
        $st->execute([':db'=>$dbName, ':c'=>$col]);
        return (int)$st->fetchColumn() > 0;
    };
    $hasIdx = function (string $idx) use ($pdo, $dbName): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=:db AND TABLE_NAME='wc_wallet_passes' AND INDEX_NAME=:i");
        $st->execute([':db'=>$dbName, ':i'=>$idx]);
        return (int)$st->fetchColumn() > 0;
    };

    if (!$hasCol('pass_type')) {
        $pdo->exec("ALTER TABLE wc_wallet_passes
                    ADD COLUMN pass_type ENUM('player','matches') NOT NULL DEFAULT 'player' AFTER user_id");
        echo "  + pass_type\n";
    } else {
        echo "  pass_type already present\n";
    }

    // One pass per (user, type). Drop the old user-only index if it exists.
    if ($hasIdx('idx_user')) {
        try { $pdo->exec("ALTER TABLE wc_wallet_passes DROP INDEX idx_user"); } catch (Throwable $e) {}
    }
    if (!$hasIdx('uq_user_type')) {
        try {
            $pdo->exec("ALTER TABLE wc_wallet_passes ADD UNIQUE KEY uq_user_type (user_id, pass_type)");
            echo "  + uq_user_type\n";
        } catch (Throwable $e) {
            // Pre-existing duplicate (user_id) rows would block the unique key; keep a plain index.
            try { $pdo->exec("ALTER TABLE wc_wallet_passes ADD KEY idx_user_type (user_id, pass_type)"); } catch (Throwable $e2) {}
            echo "  ~ uq_user_type skipped (" . $e->getMessage() . ")\n";
        }
    }

    echo "124_wc_wallet_pass_type done\n";
} catch (Throwable $e) {
    fwrite(STDERR, "124_wc_wallet_pass_type failed: " . $e->getMessage() . "\n");
    exit(1);
}
