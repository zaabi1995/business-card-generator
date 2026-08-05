<?php
/**
 * Migration 149: bilingual career listings (award ledger llm75-1).
 *
 * cardify.om/ar/careers rendered `career_listings.description` verbatim inside
 * <html lang="ar">, so an Arabic reader got a 141-letter English job listing
 * with zero Arabic in it. The page MEAN was 0.841, comfortably above any floor,
 * which is why 75 rounds of estate audits never saw it: only a per-BLOCK census
 * does.
 *
 * blog_posts got its Arabic twins in migration 087. career_listings never did,
 * so there was no column to translate INTO, and no amount of template work
 * could have fixed the page. This adds the twins.
 *
 * All nullable and no backfill here, on purpose: BilingualRecord refuses a row
 * whose twin is blank rather than half-translating it, so an untranslated job
 * is invisible on /ar/careers and unchanged on /careers. Filling title_ar +
 * description_ar makes it reappear by itself.
 *
 * employment_type is deliberately NOT twinned: it is an ENUM whose labels
 * already come from lang/{en,ar}/careers.php, and duplicating it as free text
 * would create a second source for the same string.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    $exists = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'career_listings'"
    );
    if ((int)($exists['c'] ?? 0) === 0) {
        echo "Migration 149: career_listings table not present, skipping\n";
        exit(0);
    }

    addColumnIfMissing($db, 'career_listings', 'title_ar VARCHAR(255) NULL');
    addColumnIfMissing($db, 'career_listings', 'description_ar TEXT NULL');
    addColumnIfMissing($db, 'career_listings', 'requirements_ar TEXT NULL');
    addColumnIfMissing($db, 'career_listings', 'benefits_ar TEXT NULL');
    addColumnIfMissing($db, 'career_listings', 'location_ar VARCHAR(255) NULL');
    addColumnIfMissing($db, 'career_listings', 'department_ar VARCHAR(100) NULL');
    // salary_range is free text ("Competitive", "OMR 800-1,200"), not a number,
    // so it is prose too. Empty on every current row, which is why it never
    // showed up in the census; a twin now exists before someone fills it.
    addColumnIfMissing($db, 'career_listings', 'salary_range_ar VARCHAR(100) NULL');

    echo "Migration 149: career_listings bilingual columns ready\n";
} catch (Exception $e) {
    echo "Migration 149 failed: " . $e->getMessage() . "\n";
    exit(1);
}
