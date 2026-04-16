<?php
/**
 * Migration 048: Testimonial Moderation
 *
 * Adds public visitor submission + approval workflow to employee_card_testimonials.
 * Existing rows (admin-created via PR #8) are backfilled to status='approved' so they
 * keep displaying without owner action.
 */
require_once __DIR__ . '/../../config.php';

function col_exists($pdo, $table, $col) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function index_exists($pdo, $table, $index) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $table = 'employee_card_testimonials';

    if (!col_exists($pdo, $table, 'status')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `position`");
        echo "  + added status\n";
    }
    if (!col_exists($pdo, $table, 'submitted_by_visitor')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `submitted_by_visitor` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
        echo "  + added submitted_by_visitor\n";
    }
    if (!col_exists($pdo, $table, 'visitor_email')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `visitor_email` VARCHAR(255) NULL AFTER `submitted_by_visitor`");
        echo "  + added visitor_email\n";
    }
    if (!col_exists($pdo, $table, 'rating')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `rating` TINYINT UNSIGNED NULL AFTER `visitor_email`");
        echo "  + added rating\n";
    }
    if (!col_exists($pdo, $table, 'submitter_ip')) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `submitter_ip` VARCHAR(64) NULL AFTER `rating`");
        echo "  + added submitter_ip\n";
    }

    // Backfill: any pre-existing rows (admin-created) that are still on the default
    // 'pending' status with submitted_by_visitor=0 are owner-created and should
    // be approved so they keep displaying.
    $affected = $pdo->exec(
        "UPDATE `$table` SET status='approved' WHERE status='pending' AND submitted_by_visitor=0"
    );
    echo "  ~ backfilled $affected existing rows to approved\n";

    if (!index_exists($pdo, $table, 'idx_emp_status_pos')) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `idx_emp_status_pos` (`employee_id`, `status`, `position`)");
        echo "  + added idx_emp_status_pos\n";
    }

    echo "Migration 048: testimonial moderation OK\n";
} catch (Exception $e) {
    echo "Migration 048 failed: " . $e->getMessage() . "\n";
    exit(1);
}
