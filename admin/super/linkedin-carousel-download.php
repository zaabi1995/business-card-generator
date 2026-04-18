<?php
/**
 * Super Admin — Download a generated LinkedIn carousel PDF.
 * Auth-gated. Path traversal-safe.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('missing id'); }

$db = Database::getInstance();
$row = $db->fetchOne("SELECT slug, linkedin_carousel_pdf FROM blog_posts WHERE id = ?", [$id]);
if (!$row || empty($row['linkedin_carousel_pdf'])) { http_response_code(404); die('not generated'); }

// Resolve to absolute path inside BASE_DIR — block any traversal
$rel = $row['linkedin_carousel_pdf'];
$abs = realpath(BASE_DIR . '/' . $rel);
$expectedRoot = realpath(BASE_DIR . '/uploads/linkedin-carousels');
if (!$abs || !$expectedRoot || strpos($abs, $expectedRoot) !== 0 || !is_file($abs)) {
    http_response_code(404); die('PDF not found');
}

$dlName = 'cardify-linkedin-' . preg_replace('/[^a-z0-9-]/i', '-', $row['slug']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
