<?php
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE blog_posts
        ADD COLUMN linkedin_carousel_data LONGTEXT NULL AFTER linkedin_company_post_id,
        ADD COLUMN linkedin_commentary TEXT NULL AFTER linkedin_carousel_data,
        ADD COLUMN linkedin_marked_posted_at DATETIME NULL AFTER linkedin_commentary,
        ADD COLUMN linkedin_generated_at DATETIME NULL AFTER linkedin_marked_posted_at");
    echo "Migration 118: linkedin_carousel_data + commentary + manual-post tracking columns added\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Migration 118: already exists, skipping\n";
        exit(0);
    }
    echo "Migration 118 failed: " . $e->getMessage() . "\n";
    exit(1);
}
