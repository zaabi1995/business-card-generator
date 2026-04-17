<?php
/**
 * CarouselPDFRenderer
 * PHP wrapper that feeds slide JSON to the Node + Playwright renderer.
 */
class CarouselPDFRenderer {
    public static function render(array $slides, string $outputPdfPath, string $blogUrl): void {
        $toolDir = dirname(__DIR__) . '/tools/carousel-render';
        $renderScript = $toolDir . '/render.js';
        if (!file_exists($renderScript)) {
            throw new RuntimeException("render.js missing at $renderScript");
        }

        $slides['qr_data_url'] = self::qrDataUrl($blogUrl);

        $inputPath = tempnam(sys_get_temp_dir(), 'carousel_in_') . '.json';
        file_put_contents($inputPath, json_encode($slides, JSON_UNESCAPED_UNICODE));

        $cmd = sprintf(
            'cd %s && node render.js %s %s 2>&1',
            escapeshellarg($toolDir),
            escapeshellarg($inputPath),
            escapeshellarg($outputPdfPath)
        );
        exec($cmd, $out, $code);
        @unlink($inputPath);

        if ($code !== 0) {
            throw new RuntimeException("Renderer failed (code=$code): " . implode("\n", $out));
        }
        if (!file_exists($outputPdfPath) || filesize($outputPdfPath) < 5000) {
            throw new RuntimeException("Renderer produced no/empty PDF: $outputPdfPath");
        }
    }

    private static function qrDataUrl(string $url): string {
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=12&data=' . urlencode($url);
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $png = @file_get_contents($qrUrl, false, $ctx);
        if (!$png) return '';
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
