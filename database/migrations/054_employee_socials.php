<?php
/**
 * Migration 054: Enhanced Social Links
 *
 * Replaces the two hard-coded columns (employees.linkedin, employees.twitter)
 * with a flexible key/value table supporting 25+ platforms, custom ordering
 * per employee, and a legacy-column fallback for backward compatibility.
 *
 * The `employees.linkedin` and `employees.twitter` columns are NOT dropped
 * in this migration — they remain as a read-only fallback for one release
 * and will be removed in a later cleanup migration.
 *
 * Idempotent. Backfill runs inside a transaction.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'employee_socials'")->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("CREATE TABLE `employee_socials` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `company_id` VARCHAR(36) NOT NULL,
            `platform` VARCHAR(32) NOT NULL,
            `url` VARCHAR(512) NOT NULL,
            `position` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_emp_pos` (`employee_id`, `position`),
            INDEX `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[054] Created employee_socials\n";
    } else {
        echo "[054] employee_socials already exists — skipping create\n";
    }

    // Backfill legacy linkedin/twitter columns into new table (transactional, idempotent)
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query(
            "SELECT id, company_id, linkedin, twitter FROM employees
             WHERE (linkedin IS NOT NULL AND linkedin <> '')
                OR (twitter IS NOT NULL AND twitter <> '')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $checkStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM employee_socials WHERE employee_id = :eid AND platform = :p"
        );
        $insertStmt = $pdo->prepare(
            "INSERT INTO employee_socials (employee_id, company_id, platform, url, position)
             VALUES (:eid, :cid, :p, :u, :pos)"
        );

        $inserted = 0;
        foreach ($rows as $r) {
            $pos = 0;
            foreach (['linkedin' => $r['linkedin'], 'twitter' => $r['twitter']] as $platform => $url) {
                $url = trim((string)$url);
                if ($url === '') continue;
                $checkStmt->execute([':eid' => $r['id'], ':p' => $platform]);
                if ((int)$checkStmt->fetchColumn() > 0) continue;
                $insertStmt->execute([
                    ':eid' => $r['id'],
                    ':cid' => $r['company_id'],
                    ':p'   => $platform,
                    ':u'   => $url,
                    ':pos' => $pos++,
                ]);
                $inserted++;
            }
        }
        $pdo->commit();
        echo "[054] Backfill: inserted $inserted social rows from legacy columns\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo "[054] Migration complete\n";
} catch (Exception $e) {
    echo "[054] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
