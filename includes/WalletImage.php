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
    public static function fitPng(string $srcPath, int $w, int $h, bool $knockoutWhite = false, bool $alignLeft = false): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null; // GD missing , caller falls back to the transparent icon
        }
        $img = self::load($srcPath, max($w, $h));
        if (!$img) {
            return null;
        }
        // Trim transparent padding so $alignLeft anchors the actual logo mark,
        // not the source image's transparent margin (a logo exported with built-in
        // padding otherwise renders centered/right on the pass despite alignLeft;
        // Apple's logo region is top-left). imagecropauto(IMG_CROP_TRANSPARENT) is
        // unreliable on truecolor-alpha, so scan the alpha channel for the bbox.
        $bw0 = imagesx($img); $bh0 = imagesy($img);
        if ($bw0 > 2 && $bh0 > 2) {
            $minX = $bw0; $minY = $bh0; $maxX = -1; $maxY = -1;
            $stp = max(1, (int) floor(max($bw0, $bh0) / 600));
            for ($yy = 0; $yy < $bh0; $yy += $stp) {
                for ($xx = 0; $xx < $bw0; $xx += $stp) {
                    if ((((imagecolorat($img, $xx, $yy)) >> 24) & 0x7F) < 100) {
                        if ($xx < $minX) $minX = $xx;
                        if ($xx > $maxX) $maxX = $xx;
                        if ($yy < $minY) $minY = $yy;
                        if ($yy > $maxY) $maxY = $yy;
                    }
                }
            }
            if ($maxX >= $minX && $maxY >= $minY) {
                $minX = max(0, $minX - $stp); $minY = max(0, $minY - $stp);
                $maxX = min($bw0 - 1, $maxX + $stp); $maxY = min($bh0 - 1, $maxY + $stp);
                $cw = $maxX - $minX + 1; $ch = $maxY - $minY + 1;
                if (($cw < $bw0 || $ch < $bh0) && $cw > 1 && $ch > 1) {
                    $cropped = imagecrop($img, ['x' => $minX, 'y' => $minY, 'width' => $cw, 'height' => $ch]);
                    if ($cropped !== false) { $img = $cropped; }
                }
            }
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
        $dx = $alignLeft ? 0 : (int) (($w - $dw) / 2);
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

    /**
     * Generate a brand-coloured pass background (PNG bytes): a soft vertical
     * gradient in the tenant's colour plus a faint diagonal halftone dot wave,
     * echoing the Otech-style business card. Designed to read well after Apple's
     * heavy background blur. Returns null if GD is unavailable.
     */
    public static function brandBackground(string $hex, int $w, int $h): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $hx = ltrim($hex, '#');
        if (strlen($hx) === 3) { $hx = $hx[0].$hx[0].$hx[1].$hx[1].$hx[2].$hx[2]; }
        if (strlen($hx) !== 6) { $hx = '2d13ea'; }
        $r = hexdec(substr($hx, 0, 2)); $g = hexdec(substr($hx, 2, 2)); $b = hexdec(substr($hx, 4, 2));

        $im = imagecreatetruecolor($w, $h);
        // Vertical gradient: brand at top -> ~28% deeper at the bottom for depth.
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $f = 1 - 0.28 * $t;
            $col = imagecolorallocate($im, (int)max(0, $r * $f), (int)max(0, $g * $f), (int)max(0, $b * $f));
            imageline($im, 0, $y, $w, $y, $col);
        }
        // Allover halftone (like the Otech card), intensifying toward the bottom-
        // right. Big dots + meaningful opacity so the texture survives Apple's
        // heavy blur instead of washing out to flat colour.
        imagealphablending($im, true);
        $lr = (int)min(255, $r + 120); $lg = (int)min(255, $g + 125); $lb = (int)min(255, $b + 135);
        $step = max(8, (int)($w / 15));
        for ($gy = 0; $gy < $h + $step; $gy += $step) {
            for ($gx = 0; $gx < $w + $step; $gx += $step) {
                $diag = (($gx / max(1, $w)) + ($gy / max(1, $h))) / 2; // 0 top-left .. 1 bottom-right
                $intensity = pow($diag, 1.15);
                if ($intensity < 0.05) { continue; }
                $rad = max(1, (int)round($step * 0.34 * $intensity));
                $alpha = (int)max(40, min(120, 122 - 85 * $intensity)); // 40 strong .. 120 faint
                $col = imagecolorallocatealpha($im, $lr, $lg, $lb, $alpha);
                imagefilledellipse($im, (int)$gx, (int)$gy, $rad * 2, $rad * 2, $col);
            }
        }
        ob_start();
        $ok = imagepng($im);
        $bytes = ob_get_clean();
        return ($ok && $bytes !== false && $bytes !== '') ? $bytes : null;
    }

    /**
     * Brand strip (storeCard 375x144) with the Arabic NAME + TITLE baked in as
     * properly-shaped, right-aligned RTL text. PassKit text fields can't be forced
     * RTL on a non-Arabic device, so for Arabic passes we render the text into the
     * image via SVG -> rsvg-convert (librsvg uses Pango, which shapes + bidis Arabic
     * correctly). Returns ['strip.png'=>bytes,'strip@2x.png'=>..,'strip@3x.png'=>..]
     * (all three) or [] on any failure so the caller can fall back to text fields.
     */
    public static function brandStripWithText(string $hex, string $name, string $title): array
    {
        $rsvg = trim((string) @shell_exec('command -v rsvg-convert 2>/dev/null'));
        if ($rsvg === '') {
            return [];
        }
        $hx = ltrim($hex, '#');
        if (strlen($hx) === 3) { $hx = $hx[0].$hx[0].$hx[1].$hx[1].$hx[2].$hx[2]; }
        if (strlen($hx) !== 6) { $hx = '2d13ea'; }
        $r = hexdec(substr($hx, 0, 2)); $g = hexdec(substr($hx, 2, 2)); $b = hexdec(substr($hx, 4, 2));
        $darker = sprintf('#%02x%02x%02x', (int)($r * 0.72), (int)($g * 0.72), (int)($b * 0.72));

        $W = 375; $H = 144; $pad = 20;
        $avail = $W - 2 * $pad;
        $nlen = max(1, function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name));
        $tlen = max(1, function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title));
        // Shrink the font for long strings so they never overflow the strip width.
        $nameSize  = max(22, min(42, (int) floor($avail / ($nlen * 0.55))));
        $titleSize = max(12, min(19, (int) floor($avail / ($tlen * 0.52))));
        $nameY  = ($title !== '') ? 66 : 84;
        $titleY = 106;

        $esc = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $step = 22; $dotR = 3.4;
        $svg  = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '">';
        $svg .= '<defs>';
        $svg .= '<linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#' . $hx . '"/><stop offset="1" stop-color="' . $darker . '"/></linearGradient>';
        $svg .= '<pattern id="d" width="' . $step . '" height="' . $step . '" patternUnits="userSpaceOnUse"><circle cx="' . ($step / 2) . '" cy="' . ($step / 2) . '" r="' . $dotR . '" fill="#ffffff" fill-opacity="0.10"/></pattern>';
        $svg .= '</defs>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#g)"/>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#d)"/>';
        // direction=rtl + text-anchor=start + x at the right edge => right-aligned RTL.
        $svg .= '<text x="' . ($W - $pad) . '" y="' . $nameY . '" text-anchor="start" direction="rtl" font-family="Noto Naskh Arabic, Noto Sans Arabic, sans-serif" font-weight="700" font-size="' . $nameSize . '" fill="#ffffff">' . $esc($name) . '</text>';
        if ($title !== '') {
            $svg .= '<text x="' . ($W - $pad) . '" y="' . $titleY . '" text-anchor="start" direction="rtl" font-family="Noto Sans Arabic, sans-serif" font-size="' . $titleSize . '" fill="#ffffff" fill-opacity="0.92">' . $esc($title) . '</text>';
        }
        $svg .= '</svg>';

        $tmpSvg = tempnam(sys_get_temp_dir(), 'wstrip_') . '.svg';
        if (@file_put_contents($tmpSvg, $svg) === false) {
            return [];
        }
        $out = [];
        foreach (['strip.png' => [375, 144], 'strip@2x.png' => [750, 288], 'strip@3x.png' => [1125, 432]] as $asset => $dim) {
            $tmpPng = tempnam(sys_get_temp_dir(), 'wstripo_') . '.png';
            $rc = 1; $o = [];
            @exec(escapeshellarg($rsvg) . ' -w ' . $dim[0] . ' -h ' . $dim[1] . ' '
                . escapeshellarg($tmpSvg) . ' -o ' . escapeshellarg($tmpPng) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_readable($tmpPng) && filesize($tmpPng) > 0) {
                $bytes = @file_get_contents($tmpPng);
                if ($bytes !== false && $bytes !== '') {
                    $out[$asset] = $bytes;
                }
            }
            @unlink($tmpPng);
        }
        @unlink($tmpSvg);
        return (count($out) === 3) ? $out : [];
    }

    /**
     * Poster-generic ARTWORK (iOS 27 HIG): a full-bleed brand background that Apple
     * renders CRISP (unblurred) behind the pass fields. Base size 358x448 pt, plus
     * @2x / @3x. Returns ['artwork.png'=>bytes,'artwork@2x.png'=>..,'artwork@3x.png'=>..]
     * or [] on failure (caller falls back to legacy / no artwork).
     *
     * For Arabic ($rtl=true) the name + title are baked into the upper third as
     * properly-shaped RTL text, because PassKit text fields won't render true RTL on a
     * non-Arabic device. English passes pass empty $name/$title and use real (accessible)
     * text fields instead, per Apple's "reserve images for visual content" guidance.
     * The bottom ~28% is kept clear of important art: Apple lays a material strip + the
     * square QR there on poster passes.
     */
    public static function brandArtwork(string $hex, string $name = '', string $title = '', bool $rtl = false): array
    {
        $sizes = [
            'artwork.png'     => [358, 448],
            'artwork@2x.png'  => [716, 896],
            'artwork@3x.png'  => [1074, 1344],
        ];

        $rsvg = trim((string) @shell_exec('command -v rsvg-convert 2>/dev/null'));
        if ($rsvg !== '') {
            $svg = self::artworkSvg($hex, $name, $title, $rtl);
            $tmpSvg = tempnam(sys_get_temp_dir(), 'wart_') . '.svg';
            if (@file_put_contents($tmpSvg, $svg) !== false) {
                $out = [];
                foreach ($sizes as $asset => $dim) {
                    $tmpPng = tempnam(sys_get_temp_dir(), 'warto_') . '.png';
                    $rc = 1; $o = [];
                    @exec(escapeshellarg($rsvg) . ' -w ' . $dim[0] . ' -h ' . $dim[1] . ' '
                        . escapeshellarg($tmpSvg) . ' -o ' . escapeshellarg($tmpPng) . ' 2>/dev/null', $o, $rc);
                    if ($rc === 0 && is_readable($tmpPng) && filesize($tmpPng) > 0) {
                        $bytes = @file_get_contents($tmpPng);
                        if ($bytes !== false && $bytes !== '') { $out[$asset] = $bytes; }
                    }
                    @unlink($tmpPng);
                }
                @unlink($tmpSvg);
                if (count($out) === 3) { return $out; }
            }
        }

        // GD fallback: gradient + halftone only (no shaped text), reusing brandBackground.
        $out = [];
        foreach ($sizes as $asset => $dim) {
            $bytes = self::brandBackground($hex, $dim[0], $dim[1]);
            if ($bytes) { $out[$asset] = $bytes; }
        }
        return (count($out) === 3) ? $out : [];
    }

    /** Build the poster artwork SVG (viewBox 358x448, scaled by rsvg at render time). */
    private static function artworkSvg(string $hex, string $name, string $title, bool $rtl): string
    {
        $hx = ltrim($hex, '#');
        if (strlen($hx) === 3) { $hx = $hx[0].$hx[0].$hx[1].$hx[1].$hx[2].$hx[2]; }
        if (strlen($hx) !== 6) { $hx = '2d13ea'; }
        $r = hexdec(substr($hx, 0, 2)); $g = hexdec(substr($hx, 2, 2)); $b = hexdec(substr($hx, 4, 2));
        $darker = sprintf('#%02x%02x%02x', (int)($r * 0.58), (int)($g * 0.58), (int)($b * 0.58));
        $lighter = sprintf('#%02x%02x%02x', (int)min(255, $r + 26), (int)min(255, $g + 26), (int)min(255, $b + 26));

        $W = 358; $H = 448; $pad = 28;
        $step = 26; $dotR = 3.2;

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '">';
        $svg .= '<defs>';
        $svg .= '<linearGradient id="bg" x1="0" y1="0" x2="0.35" y2="1"><stop offset="0" stop-color="' . $lighter . '"/><stop offset="0.55" stop-color="#' . $hx . '"/><stop offset="1" stop-color="' . $darker . '"/></linearGradient>';
        $svg .= '<radialGradient id="glow" cx="0.5" cy="0.18" r="0.9"><stop offset="0" stop-color="#ffffff" stop-opacity="0.16"/><stop offset="0.5" stop-color="#ffffff" stop-opacity="0"/></radialGradient>';
        $svg .= '<linearGradient id="foot" x1="0" y1="0.62" x2="0" y2="1"><stop offset="0" stop-color="' . $darker . '" stop-opacity="0"/><stop offset="1" stop-color="' . $darker . '" stop-opacity="0.55"/></linearGradient>';
        $svg .= '<pattern id="dots" width="' . $step . '" height="' . $step . '" patternUnits="userSpaceOnUse" patternTransform="rotate(12)"><circle cx="' . ($step / 2) . '" cy="' . ($step / 2) . '" r="' . $dotR . '" fill="#ffffff" fill-opacity="0.09"/></pattern>';
        $svg .= '</defs>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#bg)"/>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#dots)"/>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#glow)"/>';
        $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#foot)"/>';

        // Arabic: bake the shaped RTL name + title into the upper third (clear of the
        // bottom material strip + QR). English leaves this empty and uses text fields.
        if ($rtl && $name !== '') {
            $avail = $W - 2 * $pad;
            $nlen = max(1, function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name));
            $tlen = max(1, function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title));
            $nameSize  = max(24, min(44, (int) floor($avail / ($nlen * 0.55))));
            $titleSize = max(13, min(20, (int) floor($avail / max(1, $tlen) / 0.52)));
            $esc = function (string $s): string {
                return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
            };
            $nameY = 168; $titleY = $nameY + $nameSize + 8;
            $svg .= '<text x="' . ($W - $pad) . '" y="' . $nameY . '" text-anchor="start" direction="rtl" font-family="Noto Naskh Arabic, Noto Sans Arabic, sans-serif" font-weight="700" font-size="' . $nameSize . '" fill="#ffffff">' . $esc($name) . '</text>';
            if ($title !== '') {
                $svg .= '<text x="' . ($W - $pad) . '" y="' . $titleY . '" text-anchor="start" direction="rtl" font-family="Noto Sans Arabic, sans-serif" font-size="' . $titleSize . '" fill="#ffffff" fill-opacity="0.9">' . $esc($title) . '</text>';
            }
        }

        $svg .= '</svg>';
        return $svg;
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
