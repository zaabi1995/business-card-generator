<?php
/**
 * Bring scan_pro_receipts onto utf8mb4_unicode_ci before it has rows.
 *
 * Migration 153 fixed a live outage: Apple Wallet refreshes were 500ing
 * because scan_passes and scan_pass_registrations sat on general_ci while
 * scan_pass_changes sat on unicode_ci, and MySQL will not evaluate a join
 * predicate across two collations (error 1267).
 *
 * scan_pro_receipts is the same accident waiting: it is utf8mb3_general_ci,
 * and its employee_id varchar(36) obviously wants to be joined to
 * employees.id, which is utf8mb4_unicode_ci. Nothing joins it yet, which is
 * the only reason it has not broken. It holds Apple in-app-purchase receipts
 * for Cardify Pro, so it will get rows, and the first person to write that
 * join gets a 500 on a paid feature.
 *
 * Free to do now: the table is empty, carries no foreign key, and its three
 * columns are a transaction id, a UUID and a UUID, so no stored value can
 * change meaning between utf8mb3 and utf8mb4.
 *
 * The leftover riyada_registrations_bak_20260520_213615 is deliberately not
 * touched. It is a dated backup table, not part of the schema, and dropping
 * or rewriting someone's backup is not a migration's call.
 *
 * Idempotent: skipped if already on utf8mb4_unicode_ci.
 */
function migration_154_scan_pro_receipts_collation(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];

    try {
        $collation = $pdo->query(
            "SELECT table_collation
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = 'scan_pro_receipts'"
        )->fetchColumn();

        if ($collation === false || $collation === null) {
            $result['success'] = true;
            $result['messages'][] = 'scan_pro_receipts not present, nothing to do';
            return $result;
        }
        if ($collation === 'utf8mb4_unicode_ci') {
            $result['success'] = true;
            $result['messages'][] = 'scan_pro_receipts already utf8mb4_unicode_ci, nothing to do';
            return $result;
        }

        $pdo->exec("ALTER TABLE scan_pro_receipts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $result['messages'][] = "scan_pro_receipts converted from $collation to utf8mb4_unicode_ci";

        // Prove the join it exists to serve can now be prepared.
        $pdo->query(
            "SELECT r.original_transaction_id
               FROM scan_pro_receipts r
               JOIN employees e ON e.id = r.employee_id
              LIMIT 1"
        )->fetchAll();
        $result['messages'][] = 'scan_pro_receipts joins employees without a collation error';

        $result['success'] = true;
        return $result;
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        return $result;
    }
}
