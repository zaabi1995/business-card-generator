<?php
/**
 * WalletImage , normalize a company logo into the PNG assets Apple Wallet needs.
 *
 * Apple rejects a .pkpass whose icon.png is not a valid PNG (or is absurdly
 * large), surfacing only as the opaque "Cannot add pass" on the device. A
 * tenant logo can be SVG / JPEG / WebP / oversized, so before bundling we
 * re-encode it to clean, correctly sized PNGs on a transparent canvas.
 *
 * Uses GD (always present on the VPS; the imagick PHP ext is NOT installed) and
 * falls back to the rsvg-convert / ImageMagick `convert` CLIs for SVG sources.
 */

class WalletImage
{
    /**
     * PNG bytes of $srcPath fit into a $w x $h box on a transparent canvas
     * (aspect preserved, centered). null when the source can't be decoded.
     *
     * $knockoutWhite: for reverse logos on a dark pass header. Only applied when
     * the source actually HAS transparency (a transparent-background logo) so an
     * opaque white-bg JPEG never becomes a solid white block.
     */
    public static function fitPng(string $srcPath, int $w, int $h, bool $knockoutWhite = false): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null; // GD missing , caller falls back to the transparent icon
        }
        $img = self::load($srcPath, max($w, $h));
        if (!$img) {
            return null;
        }
        $sw = imagesx($img);
        $sh = imagesy($img);
        if ($sw < 1 || $sh < 1) {
            return null;
        }

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $transparent);
        imagealphablending($canvas, true);

        $scale = min($w / $sw, $h / $sh);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));
        $dx = (int) (($w - $dw) / 2);
        $dy = (int) (($h - $dh) / 2);
        $doKnockout = $knockoutWhite && self::hasTransparency($img);
        imagecopyresampled($canvas, $img, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);

        if ($doKnockout) {
            // Recolour every non-transparent pixel to white, preserving its alpha,
            // so the logo reads as a clean reverse/knockout mark on the dark header.
            imagealphablending($canvas, false);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $a = (imagecolorat($canvas, $x, $y) >> 24) & 0x7F;
                    if ($a < 127) {
                        imagesetpixel($canvas, $x, $y, imagecolorallocatealpha($canvas, 255, 255, 255, $a));
                    }
                }
            }
            imagesavealpha($canvas, true);
        }

        ob_start();
        $ok = imagepng($canvas);
        $bytes = ob_get_clean();
        // GD images are GC'd automatically on PHP 8+; imagedestroy() is a no-op
        // since 8.0 and an E_DEPRECATED on 8.5, which the caller's error handler
        // would turn into a fatal. So we deliberately don't call it.
        return ($ok && $bytes !== false && $bytes !== '') ? $bytes : null;
    }

    /** True if the image has any meaningfully transparent pixels (sampled grid). */
    private static function hasTransparency($img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sx = max(1, (int) ($w / 50));
        $sy = max(1, (int) ($h / 50));
        for ($y = 0; $y < $h; $y += $sy) {
            for ($x = 0; $x < $w; $x += $sx) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 20) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Load a source image as a GD resource, rasterizing SVG via CLI first. */
    private static function load(string $srcPath, int $hint)
    {
        if (!is_readable($srcPath)) {
            return null;
        }
        $data = @file_get_contents($srcPath);
        if ($data === false || $data === '') {
            return null;
        }

        $isSvg = (stripos($srcPath, '.svg') !== false)
              || (stripos(substr($data, 0, 512), '<svg') !== false);
        if ($isSvg) {
            $png = self::rasterizeSvg($srcPath, $hint * 4);
            if ($png === null) {
                return null;
            }
            $img = @imagecreatefromstring($png);
            return $img ?: null;
        }

        $img = @imagecreatefromstring($data);
        return $img ?: null;
    }

    /** Rasterize an SVG to PNG bytes via rsvg-convert, falling back to convert. */
    private static function rasterizeSvg(string $svgPath, int $size): ?string
    {
        $size = max(64, min(2048, $size));
        $tmp  = tempnam(sys_get_temp_dir(), 'wsvg_') . '.png';
        $cmds = [
            'rsvg-convert -w ' . $size . ' -h ' . $size . ' '
                . escapeshellarg($svgPath) . ' -o ' . escapeshellarg($tmp),
            'convert -background none -resize ' . $size . 'x' . $size . ' '
                . escapeshellarg($svgPath) . ' ' . escapeshellarg($tmp),
        ];
        foreach ($cmds as $cmd) {
            $rc = 1;
            @exec($cmd . ' 2>/dev/null', $out, $rc);
            if ($rc === 0 && is_readable($tmp) && filesize($tmp) > 0) {
                $bytes = @file_get_contents($tmp);
                @unlink($tmp);
                return ($bytes !== false && $bytes !== '') ? $bytes : null;
            }
        }
        @unlink($tmp);
        return null;
    }
}
