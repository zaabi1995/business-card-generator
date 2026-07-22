<?php
// database/migrations/137_employee_card_page_layout.php
// Per-employee digital-card page layout: 'auto' (photo-led when a photo
// exists, else the printed card, current behaviour), 'card' (always the
// printed business card), 'photo' (always the profile-photo vCard layout).
// The `photo` column already exists (migration 005); this only adds the
// layout switch. Default 'auto' keeps every existing card unchanged.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    if (!$db->columnExists('employees', 'card_page_layout')) {
        $db->exec("ALTER TABLE employees
                   ADD COLUMN card_page_layout VARCHAR(16) NOT NULL DEFAULT 'auto'");
        echo "Migration 137: employees.card_page_layout added\n";
    } else {
        echo "Migration 137: employees.card_page_layout already exists, skipped\n";
    }
} catch (Exception $e) {
    echo "Migration 137 failed: " . $e->getMessage() . "\n";
    exit(1);
}
