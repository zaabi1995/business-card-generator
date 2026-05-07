<?php
require_once __DIR__ . '/CarouselSlideGenerator.php';
require_once __DIR__ . '/CarouselPDFRenderer.php';
require_once __DIR__ . '/LinkedInPoster.php';

/**
 * LinkedInCarousel
 * End-to-end: blog post → slide JSON → PDF → LinkedIn doc post + optional org cross-post.
 */
class LinkedInCarousel {
    public static function postForBlog(array $blog, PDO $pdo, string $logFile): array {
        // Kill switch: same flag file as the cron poster scripts. Allows the
        // admin UI 'Post carousel now' button to be safely no-op'd along with
        // the scheduled cron without editing code or removing the button.
        $flag = dirname(__DIR__) . '/cron/.posting-disabled';
        if (file_exists($flag)) {
            self::log($logFile, "SKIPPED: LinkedIn posting disabled by flag file ({$flag})");
            return ['posted' => false, 'reason' => 'posting_disabled'];
        }

        self::log($logFile, "Generating carousel for: {$blog['title']}");

        $slides = CarouselSlideGenerator::generate($blog);
        self::log($logFile, "Slide JSON generated");

        $pdfDir = dirname(__DIR__) . '/uploads/linkedin-carousels';
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
        $pdfPath = $pdfDir . '/' . date('Y-m-d') . '-' . self::slugify($blog['slug']) . '.pdf';
        $blogUrl = 'https://cardify.om/blog/' . $blog['slug'];
        CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);
        self::log($logFile, "PDF rendered: $pdfPath (" . filesize($pdfPath) . " bytes)");

        $commentary = $slides['hook_en']
            . "\n\n" . $slides['hook_ar']
            . "\n\nSwipe through, or read the full post: " . $blogUrl
            . "\n\n#Cardify #Oman #DigitalBusinessCards #Branding";

        $personalToken = self::setting($pdo, 'linkedin_access_token');
        $personUrn = self::setting($pdo, 'linkedin_person_urn');
        if (!$personalToken || !$personUrn) {
            throw new RuntimeException('Personal LinkedIn token or person URN missing');
        }

        $personalAuthor = 'urn:li:person:' . $personUrn;
        $personalPostId = LinkedInPoster::postDocument(
            $personalToken,
            $personalAuthor,
            $pdfPath,
            $commentary,
            $blog['title']
        );
        self::log($logFile, "Personal post OK: $personalPostId");

        $orgToken = self::setting($pdo, 'linkedin_org_access_token');
        $orgId = self::setting($pdo, 'linkedin_company_id');
        $orgPostId = null;
        if ($orgToken && $orgId) {
            try {
                $orgAuthor = 'urn:li:organization:' . $orgId;
                $orgPostId = LinkedInPoster::postDocument(
                    $orgToken,
                    $orgAuthor,
                    $pdfPath,
                    $commentary,
                    $blog['title']
                );
                self::log($logFile, "Company page post OK: $orgPostId");
            } catch (Throwable $e) {
                self::log($logFile, "Company page post FAILED (personal still OK): " . $e->getMessage());
            }
        } else {
            self::log($logFile, "Skip company post, org token not configured");
        }

        return [
            'pdf_path' => $pdfPath,
            'personal_post_id' => $personalPostId,
            'company_post_id' => $orgPostId,
        ];
    }

    private static function setting(PDO $pdo, string $key): ?string {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : null;
    }

    private static function slugify(string $s): string {
        $s = preg_replace('/[^a-z0-9-]+/i', '-', $s);
        return trim(strtolower($s), '-');
    }

    private static function log(string $file, string $msg): void {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    }
}
