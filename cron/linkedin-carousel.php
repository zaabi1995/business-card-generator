<?php
/**
 * LinkedIn Carousel Autoposter
 * Publishes the next-due blog post as a LinkedIn document carousel.
 * On any fatal failure, falls back to legacy text+link poster (linkedin-autoposter.php).
 *
 * Cron: 0 9 * * * php /www/wwwroot/cardify.om/cron/linkedin-carousel.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/LinkedInCarousel.php';

$logFile = __DIR__ . '/../logs/linkedin-carousel.log';
function carouselLog(string $m) {
    global $logFile;
    $d = dirname($logFile); if (!is_dir($d)) mkdir($d, 0755, true);
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $m . "\n", FILE_APPEND);
}

function fallback(string $reason) {
    // Legacy text+link poster is DISABLED as a fallback — we only want carousel posts.
    // If the carousel fails, we skip today and retry tomorrow (post remains linkedin_posted IS NULL).
    carouselLog("Fallback skipped (legacy text-link poster disabled). Reason: $reason");
}

try {
    $pdo = Database::getInstance()->getConnection();
    if (!$pdo) throw new RuntimeException('DB not connected');
} catch (Throwable $e) {
    carouselLog('DB ERROR: ' . $e->getMessage());
    fallback('DB init failed');
    exit(1);
}

$today = date('Y-m-d');
$stmt = $pdo->prepare(
    "SELECT id, title, slug, excerpt, content AS body, status
     FROM blog_posts
     WHERE status IN ('draft','published')
       AND DATE(published_at) <= ?
       AND linkedin_posted IS NULL
     ORDER BY published_at ASC
     LIMIT 1"
);
$stmt->execute([$today]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    carouselLog('No posts due today');
    exit(0);
}

if ($post['status'] === 'draft') {
    $pdo->prepare("UPDATE blog_posts SET status='published' WHERE id = ?")->execute([$post['id']]);
    carouselLog("Auto-published draft: {$post['title']}");
}

try {
    $result = LinkedInCarousel::postForBlog($post, $pdo, $logFile);

    $upd = $pdo->prepare(
        "UPDATE blog_posts
         SET linkedin_posted = NOW(),
             linkedin_post_id = ?,
             linkedin_carousel_pdf = ?,
             linkedin_company_post_id = ?
         WHERE id = ?"
    );
    $upd->execute([
        $result['personal_post_id'],
        str_replace(dirname(__DIR__) . '/', '', $result['pdf_path']),
        $result['company_post_id'],
        $post['id'],
    ]);
    carouselLog("SUCCESS: {$post['title']} | personal={$result['personal_post_id']} | company=" . ($result['company_post_id'] ?? 'n/a'));
} catch (Throwable $e) {
    carouselLog("CAROUSEL FAILED for {$post['title']}: " . $e->getMessage());
    fallback("Post {$post['id']}: " . $e->getMessage());
    exit(1);
}
