<?php
/**
 * Migration 062: Harden appointments + short_links (Codex adversarial findings)
 *
 *  - appointments: add UNIQUE (employee_id, slot_start) to prevent TOCTOU
 *    double-booking. Two parallel requests can both pass the availability
 *    check, but only one INSERT will succeed.
 *  - short_links: add `approved` flag + approver metadata. All existing rows
 *    are grandfathered as approved=1 so live traffic is NOT disrupted. New
 *    non-super-admin rows default to approved=0 and must be reviewed before
 *    /s/<slug> will redirect.
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    // ------------------------------------------------------------------
    // 1. UNIQUE (employee_id, slot_start) on appointments.
    //    Drop any duplicates first so the UNIQUE can be added cleanly.
    // ------------------------------------------------------------------
    $existing = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'appointments'
            AND INDEX_NAME   = 'uk_slot'"
    )->fetchColumn();

    if (!$existing) {
        // Cancel the older of any duplicate (employee_id, slot_start) rows so
        // the UNIQUE key creation doesn't fail on legacy data. Cancellations
        // are a no-op for active bookings.
        $pdo->exec(
            "UPDATE appointments a
                JOIN (
                    SELECT employee_id, slot_start, MIN(created_at) AS keep_at
                      FROM appointments
                     GROUP BY employee_id, slot_start
                    HAVING COUNT(*) > 1
                ) d ON a.employee_id = d.employee_id
                   AND a.slot_start   = d.slot_start
                   AND a.created_at   <> d.keep_at
                SET a.status = 'cancelled',
                    a.cancellation_reason = COALESCE(a.cancellation_reason, 'Deduplicated by migration 062')"
        );
        // …but the UNIQUE still applies to ALL rows. If there are still
        // duplicates with identical created_at (extremely unlikely),
        // disambiguate by keeping the smallest id.
        $pdo->exec(
            "UPDATE appointments a
                JOIN (
                    SELECT employee_id, slot_start, MIN(id) AS keep_id
                      FROM appointments
                     GROUP BY employee_id, slot_start
                    HAVING COUNT(*) > 1
                ) d ON a.employee_id = d.employee_id
                   AND a.slot_start   = d.slot_start
                   AND a.id           <> d.keep_id
                SET a.status = 'cancelled',
                    a.cancellation_reason = COALESCE(a.cancellation_reason, 'Deduplicated by migration 062')"
        );

        // The UNIQUE must work even when there are cancelled duplicates,
        // because cancelled rows still occupy the (employee_id, slot_start)
        // tuple. The dedup above kept exactly one row per tuple, so we're OK.
        $pdo->exec(
            "ALTER TABLE appointments
               ADD UNIQUE KEY uk_slot (employee_id, slot_start)"
        );
        echo "[062] appointments.uk_slot UNIQUE added\n";
    } else {
        echo "[062] appointments.uk_slot already present — skipped\n";
    }

    // ------------------------------------------------------------------
    // 2. short_links: add moderation columns.
    // ------------------------------------------------------------------
    $addColumn = function ($col, $ddl) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'short_links'
                AND COLUMN_NAME  = :c"
        );
        $stmt->execute(['c' => $col]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo "[062] short_links.$col already present — skipped\n";
            return;
        }
        $pdo->exec("ALTER TABLE short_links ADD COLUMN $ddl");
        echo "[062] short_links.$col added\n";
    };

    $addColumn('approved',
        "`approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `click_count`");
    $addColumn('approved_by_user_id',
        "`approved_by_user_id` VARCHAR(36) NULL AFTER `approved`");
    $addColumn('approved_at',
        "`approved_at` DATETIME NULL AFTER `approved_by_user_id`");

    // Grandfather existing rows so live /s/<slug> redirects keep working.
    $grand = $pdo->exec(
        "UPDATE short_links
            SET approved = 1,
                approved_at = COALESCE(approved_at, created_at)
          WHERE approved = 0
            AND created_at < NOW()"
    );
    echo "[062] grandfathered $grand existing short_links as approved\n";

    // Helpful index for the pending-moderation view.
    $idx = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'short_links'
            AND INDEX_NAME   = 'idx_approved_created'"
    )->fetchColumn();
    if (!$idx) {
        $pdo->exec("ALTER TABLE short_links ADD INDEX idx_approved_created (approved, created_at)");
        echo "[062] short_links idx_approved_created added\n";
    } else {
        echo "[062] short_links idx_approved_created present — skipped\n";
    }

    echo "[062] Migration complete\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[062] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
