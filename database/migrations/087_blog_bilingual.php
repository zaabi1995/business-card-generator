<?php
/**
 * Migration 087: bilingual blog posts (Cat R action 440).
 *
 * Adds Arabic counterparts on blog_posts: title_ar, slug_ar (unique),
 * excerpt_ar, content_ar, meta_desc_ar. All nullable, so legacy
 * English-only posts keep working and /ar/blog silently falls back
 * to the EN version until a translation ships.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    // Skip gracefully if blog_posts doesn't exist on a particular install.
    $exists = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts'"
    );
    if ((int)($exists['c'] ?? 0) === 0) {
        echo "Migration 087: blog_posts table not present, skipping\n";
        exit(0);
    }

    addColumnIfMissing($db, 'blog_posts', 'title_ar VARCHAR(255) NULL');
    addColumnIfMissing($db, 'blog_posts', 'slug_ar VARCHAR(255) NULL');
    addColumnIfMissing($db, 'blog_posts', 'excerpt_ar TEXT NULL');
    addColumnIfMissing($db, 'blog_posts', 'content_ar LONGTEXT NULL');
    addColumnIfMissing($db, 'blog_posts', 'meta_desc_ar VARCHAR(500) NULL');

    // Unique index on slug_ar (NULL values don't collide under MySQL).
    $hasIdx = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND INDEX_NAME = 'uk_slug_ar'"
    );
    if ((int)($hasIdx['c'] ?? 0) === 0) {
        try { $db->exec("ALTER TABLE blog_posts ADD UNIQUE KEY uk_slug_ar (slug_ar)"); } catch (Throwable $_) {}
    }

    echo "Migration 087: blog_posts bilingual columns ready\n";
} catch (Exception $e) {
    echo "Migration 087 failed: " . $e->getMessage() . "\n";
    exit(1);
}
