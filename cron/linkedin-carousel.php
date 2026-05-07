<?php
/**
 * LinkedIn Carousel Generator (cron).
 * Generates the next-due blog post's slide JSON + PDF + commentary, saves to DB.
 * Does NOT post to LinkedIn — Ali manually posts to the Cardify company page
 * via the admin section at /admin/super/linkedin-carousels.php.
 *
 * Cron: 0 9 * * * php /www/wwwroot/cardify.om/cron/linkedin-carousel.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Kill switch: touch /www/wwwroot/cardify.om/cron/.posting-disabled to halt
// all LinkedIn posting (cron + admin UI manual triggers) without editing
// code. Currently OFF per Ali 2026-05-07: carousel was failing daily at the
// Anthropic API credit step. Removing the flag file re-enables.
if (file_exists(__DIR__ . '/.posting-disabled')) {
    echo "[" . date('Y-m-d H:i:s') . "] LinkedIn posting disabled by flag file, exiting.\n";
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/CarouselSlideGenerator.php';
require_once __DIR__ . '/../includes/CarouselPDFRenderer.php';

$logFile = __DIR__ . '/../logs/linkedin-carousel.log';
function carouselLog(string $m) {
    global $logFile;
    $d = dirname($logFile); if (!is_dir($d)) mkdir($d, 0755, true);
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $m . "\n", FILE_APPEND);
}

try {
    $pdo = Database::getInstance()->getConnection();
    if (!$pdo) throw new RuntimeException('DB not connected');
} catch (Throwable $e) {
    carouselLog('DB ERROR: ' . $e->getMessage());
    exit(1);
}

$today = date('Y-m-d');
$stmt = $pdo->prepare(
    "SELECT id, title, slug, excerpt, content AS body, status
     FROM blog_posts
     WHERE status IN ('draft','published')
       AND DATE(published_at) <= ?
       AND linkedin_generated_at IS NULL
       AND linkedin_marked_posted_at IS NULL
     ORDER BY published_at ASC
     LIMIT 1"
);
$stmt->execute([$today]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    carouselLog('No posts pending generation');
    exit(0);
}

if ($post['status'] === 'draft') {
    $pdo->prepare("UPDATE blog_posts SET status='published' WHERE id = ?")->execute([$post['id']]);
    carouselLog("Auto-published draft: {$post['title']}");
}

try {
    carouselLog("Generating carousel for: {$post['title']}");
    $slides = CarouselSlideGenerator::generate($post);
    carouselLog("Slide JSON generated");

    $pdfDir = dirname(__DIR__) . '/uploads/linkedin-carousels';
    if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
    $pdfPath = $pdfDir . '/' . date('Y-m-d') . '-' . preg_replace('/[^a-z0-9-]/i', '-', $post['slug']) . '.pdf';
    $blogUrl = 'https://cardify.om/blog/' . $post['slug'];
    CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);
    carouselLog("PDF rendered: $pdfPath (" . filesize($pdfPath) . " bytes)");

    $commentary = $slides['hook_en']
        . "\n\n" . $slides['hook_ar']
        . "\n\nSwipe through, or read the full post: " . $blogUrl
        . "\n\n#Cardify #Oman #DigitalBusinessCards #Branding";

    $relPdf = str_replace(dirname(__DIR__) . '/', '', $pdfPath);
    $upd = $pdo->prepare(
        "UPDATE blog_posts
         SET linkedin_carousel_pdf = ?,
             linkedin_carousel_data = ?,
             linkedin_commentary = ?,
             linkedin_generated_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([
        $relPdf,
        json_encode($slides, JSON_UNESCAPED_UNICODE),
        $commentary,
        $post['id'],
    ]);
    carouselLog("READY for manual posting: {$post['title']} | pdf=$relPdf");
} catch (Throwable $e) {
    carouselLog("CAROUSEL GENERATE FAILED for {$post['title']}: " . $e->getMessage());
    exit(1);
}
