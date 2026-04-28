<?php
/**
 * LogoPalette
 *
 * Extracts a brand palette from an uploaded logo (PNG, JPG, SVG, WebP, GIF).
 * Returns the dominant colour as `primary`, the next saturated colour as
 * `secondary`, and a calculated `accent` (complementary hue) for highlights.
 *
 * Works without external dependencies, uses GD only. SVGs are rasterised
 * via Imagick if available, otherwise falls back to a sane default.
 *
 * USAGE
 *   require_once __DIR__ . '/LogoPalette.php';
 *   $palette = LogoPalette::extract('/path/to/logo.png');
 *   // => ['primary' => '#2d13ea', 'secondary' => '#ff7800', 'accent' => '#1a0a8a']
 */

class LogoPalette
{
    /** Minimum saturation to count as a "brand colour" (filter out greys). */
    private const MIN_SATURATION = 0.18;

    /** Pixels under this brightness are treated as black-ish, skipped. */
    private const MIN_LIGHTNESS = 0.08;

    /** Pixels over this brightness are treated as white-ish, skipped. */
    private const MAX_LIGHTNESS = 0.95;

    /** Resize the source to this many pixels per axis to speed up scanning. */
    private const SCAN_SIZE = 96;

    /** Quantisation step: round each RGB channel to multiples of this. */
    private const QUANTISE = 32;

    /**
     * Public entry point.
     *
     * @param string $path Local path to the logo file.
     * @return array{primary:string, secondary:string, accent:string, logo_url:?string}
     */
    public static function extract(string $path): array
    {
        $defaults = [
            'primary'   => '#2d13ea',
            'secondary' => '#ff7800',
            'accent'    => '#1a0a8a',
            'logo_url'  => null,
        ];

        if (!is_file($path) || !is_readable($path)) {
            return $defaults;
        }

        $img = self::loadAsGd($path);
        if (!$img) {
            return $defaults;
        }

        // Resize for speed.
        $w = imagesx($img);
        $h = imagesy($img);
        $ratio = max($w, $h) / self::SCAN_SIZE;
        if ($ratio > 1) {
            $newW = max(1, (int) round($w / $ratio));
            $newH = max(1, (int) round($h / $ratio));
            $small = imagecreatetruecolor($newW, $newH);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
            imagefill($small, 0, 0, $transparent);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $small;
            $w = $newW;
            $h = $newH;
        }

        // Walk pixels, count quantised RGB buckets weighted by saturation.
        $buckets = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha > 100) continue; // transparent pixel
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                [$hh, $ss, $ll] = self::rgbToHsl($r, $g, $b);
                if ($ll < self::MIN_LIGHTNESS || $ll > self::MAX_LIGHTNESS) continue;
                if ($ss < self::MIN_SATURATION) continue;

                $rq = self::quantise($r);
                $gq = self::quantise($g);
                $bq = self::quantise($b);
                $key = sprintf('%02x%02x%02x', $rq, $gq, $bq);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['r' => 0, 'g' => 0, 'b' => 0, 'n' => 0, 'sat' => 0];
                }
                $buckets[$key]['r']  += $r;
                $buckets[$key]['g']  += $g;
                $buckets[$key]['b']  += $b;
                $buckets[$key]['n']  += 1;
                $buckets[$key]['sat'] = max($buckets[$key]['sat'], $ss);
            }
        }
        imagedestroy($img);

        if (count($buckets) === 0) {
            return $defaults;
        }

        // Score = count * saturation. We want a colour that is BOTH common
        // and saturated; tiny accents shouldn't beat the brand colour.
        $scored = [];
        foreach ($buckets as $k => $b) {
            $score = $b['n'] * (0.4 + 0.6 * $b['sat']);
            $avgR = (int) round($b['r'] / $b['n']);
            $avgG = (int) round($b['g'] / $b['n']);
            $avgB = (int) round($b['b'] / $b['n']);
            $scored[] = [
                'hex'   => sprintf('#%02x%02x%02x', $avgR, $avgG, $avgB),
                'score' => $score,
                'rgb'   => [$avgR, $avgG, $avgB],
            ];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $primary = $scored[0]['hex'];

        // Secondary: highest-scoring colour whose hue is at least 30° away
        // from primary, falls back to the second entry if everything is
        // monochromatic.
        $secondary = null;
        $primaryHue = self::rgbToHsl(...$scored[0]['rgb'])[0];
        for ($i = 1; $i < count($scored); $i++) {
            $hue = self::rgbToHsl(...$scored[$i]['rgb'])[0];
            $delta = min(abs($hue - $primaryHue), 1 - abs($hue - $primaryHue));
            if ($delta > 30 / 360) {
                $secondary = $scored[$i]['hex'];
                break;
            }
        }
        if ($secondary === null) {
            $secondary = $scored[count($scored) > 1 ? 1 : 0]['hex'];
        }

        // Accent: complementary of primary (hue + 180°), used for QR colour
        // and CTA highlights when the primary is the "field" colour.
        $accent = self::shiftHue($primary, 180);

        return [
            'primary'   => strtolower($primary),
            'secondary' => strtolower($secondary),
            'accent'    => strtolower($accent),
            'logo_url'  => null,
        ];
    }

    private static function quantise(int $v): int
    {
        return (int) (round($v / self::QUANTISE) * self::QUANTISE);
    }

    /**
     * Load a source image into a GD resource regardless of format.
     * Returns null if the file can't be decoded.
     */
    private static function loadAsGd(string $path)
    {
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? mime_content_type($path) ?: '';

        if ($mime === 'image/svg+xml' || str_ends_with(strtolower($path), '.svg')) {
            // Rasterise SVG via Imagick if we have it. Otherwise return null
            // and let the caller fall back to defaults.
            if (extension_loaded('imagick')) {
                try {
                    $im = new Imagick();
                    $im->setBackgroundColor(new ImagickPixel('transparent'));
                    $im->setResolution(150, 150);
                    $im->readImageBlob(file_get_contents($path));
                    $im->setImageFormat('png');
                    $blob = $im->getImageBlob();
                    return @imagecreatefromstring($blob);
                } catch (Throwable $e) {
                    return null;
                }
            }
            return null;
        }

        return @imagecreatefromstring(file_get_contents($path)) ?: null;
    }

    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0, 0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        switch ($max) {
            case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
            case $g: $h = ($b - $r) / $d + 2; break;
            default: $h = ($r - $g) / $d + 4;
        }
        $h /= 6;
        return [$h, $s, $l];
    }

    private static function shiftHue(string $hex, float $degrees): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);
        $h = fmod($h + ($degrees / 360) + 1, 1);
        [$r2, $g2, $b2] = self::hslToRgb($h, $s, $l);
        return sprintf('#%02x%02x%02x', $r2, $g2, $b2);
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }
        $hue2rgb = function ($p, $q, $t) {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $r = $hue2rgb($p, $q, $h + 1 / 3);
        $g = $hue2rgb($p, $q, $h);
        $b = $hue2rgb($p, $q, $h - 1 / 3);
        return [
            (int) round($r * 255),
            (int) round($g * 255),
            (int) round($b * 255),
        ];
    }
}
