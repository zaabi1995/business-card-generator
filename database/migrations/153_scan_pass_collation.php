<?php
/**
 * Apple Wallet pass updates have been returning 500 to every iPhone.
 *
 * ScanPassService::serialsForDevice() joins scan_pass_registrations to
 * scan_passes to scan_pass_changes on serial_number. scan_pass_changes was
 * created utf8mb4_unicode_ci, the other two utf8mb4_general_ci, so the join
 * predicate mixes collations and MySQL refuses it outright:
 *
 *   SQLSTATE[HY000] 1267 Illegal mix of collations
 *   (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT)
 *
 * The exception escapes as a 500 on /wallet, and the device gives up:
 * "Get serial #s task ... encountered error: Unexpected response code 500".
 * 242 of those were in the error log when this was found, so no pass has been
 * able to refresh for as long as both tables have existed.
 *
 * unicode_ci is the direction of travel: 135 tables in this schema use it and
 * CLAUDE.md names it as the rule, so scan_passes and scan_pass_registrations
 * are the two that drifted, not scan_pass_changes. card_designs is the only
 * other general_ci table and is converted here too so nothing is left behind
 * to bite a future join; it is empty, so that is free.
 *
 * Safe to run: none of these tables carries a foreign key, and the unique
 * indexes do not collide under the new collation. Checked before writing this,
 * COUNT(DISTINCT serial_number) is identical under both collations for all 31
 * scan_passes rows, as is the (serial_number, device_library_id) pair for all 7
 * scan_pass_registrations rows. Serial numbers are hex, which is where the two
 * collations agree anyway.
 *
 * Idempotent: a table already on unicode_ci is skipped.
 */
function migration_153_scan_pass_collation(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    $tables = ['scan_passes', 'scan_pass_registrations', 'card_designs'];

    try {
        foreach ($tables as $table) {
            $exists = $pdo->query(
                "SELECT table_collation
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = " . $pdo->quote($table)
            )->fetchColumn();

            if ($exists === false || $exists === null) {
                $result['messages'][] = "$table not present, skipped";
                continue;
            }
            if ($exists === 'utf8mb4_unicode_ci') {
                $result['messages'][] = "$table already utf8mb4_unicode_ci, nothing to do";
                continue;
            }

            $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $result['messages'][] = "$table converted from $exists to utf8mb4_unicode_ci";
        }

        // Prove the join that was failing can now be prepared and run.
        $pdo->query(
            "SELECT p.serial_number, COALESCE(MAX(ch.id), 0) AS change_id
               FROM scan_pass_registrations r
               JOIN scan_passes p ON p.serial_number = r.serial_number
               LEFT JOIN scan_pass_changes ch ON ch.serial_number = p.serial_number
              WHERE r.device_library_id = ''
              GROUP BY p.serial_number"
        )->fetchAll();
        $result['messages'][] = 'wallet serialsForDevice join executes without a collation error';

        $result['success'] = true;
        return $result;
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        return $result;
    }
}
