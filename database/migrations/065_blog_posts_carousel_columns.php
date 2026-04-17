<?php
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE blog_posts
        ADD COLUMN linkedin_carousel_pdf VARCHAR(500) NULL AFTER linkedin_post_id,
        ADD COLUMN linkedin_company_post_id VARCHAR(255) NULL AFTER linkedin_carousel_pdf");
    echo "Migration 065: blog_posts carousel columns added\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Migration 065: columns already exist, skipping\n";
        exit(0);
    }
    echo "Migration 065 failed: " . $e->getMessage() . "\n";
    exit(1);
}
