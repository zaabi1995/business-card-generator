<?php
/**
 * Admin: generate + return LinkedIn carousel PDF for a blog post on demand.
 * Usage: GET /admin/blog-carousel-preview.php?id=<blog_post_id>
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CarouselSlideGenerator.php';
require_once INCLUDES_DIR . '/CarouselPDFRenderer.php';

requireAdmin();

$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); die('missing id'); }

$db = Database::getInstance();
$post = $db->fetchOne("SELECT id, title, slug, excerpt, body FROM blog_posts WHERE id = :id", ['id' => $id]);
if (!$post) { http_response_code(404); die('not found'); }

try {
    $slides = CarouselSlideGenerator::generate($post);
    $pdfPath = sys_get_temp_dir() . '/carousel-preview-' . preg_replace('/[^a-z0-9-]/i', '', $id) . '.pdf';
    $blogUrl = 'https://cardify.om/blog/' . $post['slug'];
    CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="carousel-' . preg_replace('/[^a-z0-9-]/i', '-', $post['slug']) . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    readfile($pdfPath);
    @unlink($pdfPath);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Preview failed: ' . htmlspecialchars($e->getMessage()) . '</pre>';
}
