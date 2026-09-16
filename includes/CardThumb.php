<?php
require_once __DIR__ . '/CardPDFRenderer.php';

/**
 * CardThumb, the small picture of the card that travels with the ERP document.
 *
 * Ali, 16 Sep 2026: the quotation should show the card itself. BHD-ERP renders
 * an item image as a 36 x 36 avatar with object-fit: cover, so a landscape card
 * handed over as is would be cropped to a strip through its middle. The card is
 * therefore centred on a white square first, and the avatar then shows the whole
 * card.
 *
 * The picture is whatever the employee already has: the portal preview if one
 * was made, otherwise a fresh render of the card itself.
 */
class CardThumb
{
    /** @return string|null path to a temporary PNG, or null when there is nothing to show */
    public static function forRequest(array $req): ?string
    {
        $src = self::sourceImage($req);
        return $src === null ? null : self::square($src);
    }

    /** The best picture available for this request, as a PNG on disk. */
    private static function sourceImage(array $req): ?string
    {
        foreach (['preview_front_path', 'preview_back_path'] as $col) {
            $p = trim((string)($req[$col] ?? ''));
            if ($p === '') { continue; }
            $abs = $p[0] === '/' && is_file($p) ? $p : BASE_DIR . '/' . ltrim($p, '/');
            if (is_file($abs)) { return $abs; }
        }

        $employeeId = trim((string)($req['employee_id'] ?? ''));
        if ($employeeId === '') { return null; }
        $pdf = CardPDFRenderer::render($employeeId, 'web', ['include_qr' => true]);
        if (empty($pdf['success']) || !is_file((string)$pdf['path'])) { return null; }
        return self::firstPageAsPng((string)$pdf['path']);
    }

    private static function firstPageAsPng(string $pdfPath): ?string
    {
        $stem = sys_get_temp_dir() . '/cardthumb-' . bin2hex(random_bytes(6));
        $cmd  = 'pdftoppm -png -r 150 -f 1 -l 1 ' . escapeshellarg($pdfPath) . ' '
              . escapeshellarg($stem) . ' 2>/dev/null';
        shell_exec($cmd);
        foreach (glob($stem . '*.png') ?: [] as $f) { return $f; }
        return null;
    }

    /**
     * Centre the picture on a white square so a cover-cropped avatar shows all
     * of it. Returns a new temporary file; the source is left alone.
     */
    public static function square(string $src, int $size = 600): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($src)) { return null; }
        $info = @getimagesize($src);
        if (!$info) { return null; }
        $img = match ($info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default        => false,
        };
        if (!$img) { return null; }

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(($size * 0.92) / $w, ($size * 0.92) / $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $img, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2),
                           0, 0, $nw, $nh, $w, $h);

        $out = sys_get_temp_dir() . '/cardthumb-sq-' . bin2hex(random_bytes(6)) . '.png';
        $ok  = imagepng($canvas, $out, 6);
        imagedestroy($canvas);
        imagedestroy($img);
        // A render source is a temporary file of ours; a stored preview is not.
        if (strpos($src, sys_get_temp_dir() . '/cardthumb-') === 0) { @unlink($src); }
        return $ok ? $out : null;
    }
}
