<?php
/**
 * OgImage, per-tenant Open Graph / Twitter card generator.
 *
 * Produces a 1200x630 PNG branded with the company logo, primary color,
 * name, and a localized headline. Output is cached at
 * storage/og/{company_id}-{hash}.png. The hash covers logo mtime + colors
 * + name + locale so cache busts automatically when a tenant re-brands.
 *
 * Rendering: SVG composed in PHP, rasterized with rsvg-convert when
 * available. Falls back to a GD composition that skips SVG logos.
 */

class OgImage
{
    const WIDTH  = 1200;
    const HEIGHT = 630;

    /**
     * Build (or fetch from cache) the OG image for a company and return
     * the absolute filesystem path to the PNG. Returns null on failure
     * so callers can fall back to the generic cardify-og.png.
     *
     * Options:
     *   - headline (string)    override subtitle line
     *   - locale   (string)    'en' or 'ar' (affects default headline)
     *   - variant  (string)    arbitrary cache bucket, e.g. 'portal' or 'login'
     *   - department (string)  department display name to append
     */
    public static function generate(array $company, array $opts = []): ?string
    {
        $root    = dirname(__DIR__);
        $cacheDir = $root . '/storage/og';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            return null;
        }

        $id       = (string)($company['id'] ?? '');
        if ($id === '') return null;
        $slug     = (string)($company['slug'] ?? $id);
        $name     = (string)($company['name'] ?? $slug);
        $locale   = $opts['locale'] ?? 'en';
        $variant  = $opts['variant'] ?? 'portal';
        $dept     = (string)($opts['department'] ?? '');
        $headline = (string)($opts['headline'] ?? self::defaultHeadline($locale));

        $theme = self::loadTheme($id);
        $brandPrimary   = $theme['primary']   ?? '#009bc1';
        $brandSecondary = $theme['secondary'] ?? '#0f172a';
        $logoPath       = self::resolveLogo($id, $theme, $root);

        $logoSig = $logoPath ? (string)@filemtime($logoPath) : '0';
        $key = sha1(implode('|', [
            $id, $slug, $name, $dept, $headline, $locale, $variant,
            $brandPrimary, $brandSecondary, $logoPath ?? '-', $logoSig,
        ]));
        $outPath = $cacheDir . '/' . $slug . '-' . substr($key, 0, 12) . '.png';

        if (is_file($outPath) && filesize($outPath) > 0) {
            return $outPath;
        }

        // Prune stale variants for this slug so the dir doesn't grow forever.
        foreach (glob($cacheDir . '/' . $slug . '-*.png') ?: [] as $old) {
            @unlink($old);
        }

        // Prefer rsvg-convert, highest fidelity (gradients, rounded corners,
        // embedded SVG logos). Fall back to GD so dev boxes without
        // rsvg-convert still get a usable card.
        $ok = self::renderWithRsvg($outPath, $name, $dept, $headline, $brandPrimary, $brandSecondary, $logoPath, $locale)
           || self::renderWithGd($outPath, $name, $dept, $headline, $brandPrimary, $brandSecondary, $logoPath, $locale);

        if (!$ok || !is_file($outPath) || filesize($outPath) === 0) {
            return null;
        }
        @chmod($outPath, 0644);
        return $outPath;
    }

    /** Public URL for the OG endpoint; safe for og:image meta. */
    public static function url(array $company, array $opts = []): string
    {
        $host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $query = ['slug' => $company['slug'] ?? ''];
        if (!empty($opts['variant']))    $query['v']    = $opts['variant'];
        if (!empty($opts['department'])) $query['dept'] = $opts['department'];
        if (!empty($opts['locale']))     $query['lang'] = $opts['locale'];
        return $scheme . '://' . $host . '/og-image.php?' . http_build_query($query);
    }

    private static function defaultHeadline(string $locale): string
    {
        return $locale === 'ar' ? 'اطلب بطاقة العمل الخاصة بك' : 'Request your business card';
    }

    private static function loadTheme(string $companyId): array
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT primary_color, secondary_color, logo_path FROM company_themes WHERE company_id = :id LIMIT 1',
                ['id' => $companyId]
            );
        } catch (Throwable $e) {
            $row = null;
        }
        $primary = preg_match('/^#[0-9a-fA-F]{6}$/', $row['primary_color'] ?? '') ? $row['primary_color'] : null;
        $secondary = preg_match('/^#[0-9a-fA-F]{6}$/', $row['secondary_color'] ?? '') ? $row['secondary_color'] : null;
        return [
            'primary'   => $primary,
            'secondary' => $secondary,
            'logo_path' => $row['logo_path'] ?? null,
        ];
    }

    /** @return string|null absolute filesystem path to best available logo */
    private static function resolveLogo(string $companyId, array $theme, string $root): ?string
    {
        // Prefer PNG for easiest embedding in SVG/GD. Fall back to SVG and
        // rasterize on the fly to a temp PNG when we go through rsvg.
        $candidates = [
            '/uploads/companies/' . $companyId . '/logo.png',
            '/uploads/companies/' . $companyId . '/logo.jpg',
            '/uploads/companies/' . $companyId . '/logo.svg',
        ];
        if (!empty($theme['logo_path'])) {
            array_unshift($candidates, $theme['logo_path']);
        }
        foreach ($candidates as $rel) {
            $abs = $root . $rel;
            if (is_file($abs)) return $abs;
        }
        return null;
    }

    private static function renderWithRsvg(
        string $outPath,
        string $name,
        string $dept,
        string $headline,
        string $brandPrimary,
        string $brandSecondary,
        ?string $logoPath,
        string $locale
    ): bool {
        // open_basedir often blocks is_executable() on /usr/bin paths
        // while still allowing exec() to invoke them. Probe via exec,
        // which is the only thing we actually depend on.
        $rsvg = self::resolveRsvgBinary();
        if ($rsvg === null) {
            return false;
        }

        // If the logo is an SVG, rasterize it to a temp PNG first so we can
        // embed it as a data URL without depending on rsvg-convert's nested
        // SVG handling (which varies by version).
        $logoDataUri = null;
        if ($logoPath) {
            $logoDataUri = self::logoAsPngDataUri($logoPath, $rsvg);
        }

        $svg = self::buildSvg($name, $dept, $headline, $brandPrimary, $brandSecondary, $logoDataUri, $locale);

        // Write the SVG inside the site tree so open_basedir never blocks
        // rsvg-convert from reading it.
        $tmpDir = dirname($outPath) . '/tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $tmpSvg = $tmpDir . '/og-' . bin2hex(random_bytes(6)) . '.svg';
        if (@file_put_contents($tmpSvg, $svg) === false) {
            error_log('OgImage: cannot write temp SVG to ' . $tmpSvg);
            return false;
        }

        $cmd = sprintf(
            '%s --format=png --width=%d --height=%d --output=%s %s 2>&1',
            escapeshellcmd($rsvg),
            self::WIDTH, self::HEIGHT,
            escapeshellarg($outPath),
            escapeshellarg($tmpSvg)
        );
        $out = [];
        $code = -1;
        @exec($cmd, $out, $code);
        @unlink($tmpSvg);
        if ($code !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
            error_log('OgImage: rsvg failed code=' . $code . ' out=' . implode(' | ', $out));
            return false;
        }
        return true;
    }

    private static function resolveRsvgBinary(): ?string
    {
        static $cached = 0;
        if ($cached !== 0) return $cached;

        // `command -v` may be empty under open_basedir + restricted shell.
        $fromShell = trim((string)@shell_exec('command -v rsvg-convert 2>/dev/null'));
        $candidates = array_filter([
            $fromShell,
            '/usr/bin/rsvg-convert',
            '/usr/local/bin/rsvg-convert',
            '/opt/homebrew/bin/rsvg-convert',
            'rsvg-convert', // trust $PATH
        ]);

        foreach ($candidates as $bin) {
            $cmd = escapeshellcmd($bin) . ' --version 2>&1';
            $out = [];
            $code = -1;
            @exec($cmd, $out, $code);
            if ($code === 0) {
                $cached = $bin;
                return $bin;
            }
        }
        error_log('OgImage: rsvg-convert not runnable, falling back to GD');
        $cached = null;
        return null;
    }

    private static function logoAsPngDataUri(string $logoPath, string $rsvg): ?string
    {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        if ($ext === 'png' || $ext === 'jpg' || $ext === 'jpeg') {
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $data = @file_get_contents($logoPath);
            if ($data === false) return null;
            return 'data:' . $mime . ';base64,' . base64_encode($data);
        }
        if ($ext === 'svg') {
            $tmpDir = dirname(dirname($logoPath)) . '/og-tmp';
            if (!is_dir($tmpDir)) $tmpDir = sys_get_temp_dir();
            $tmpPng = $tmpDir . '/oglogo-' . bin2hex(random_bytes(6)) . '.png';
            $cmd = sprintf(
                '%s --format=png --height=480 --output=%s %s 2>&1',
                escapeshellcmd($rsvg),
                escapeshellarg($tmpPng),
                escapeshellarg($logoPath)
            );
            @exec($cmd, $_o, $code);
            if ($code !== 0 || !is_file($tmpPng)) { @unlink($tmpPng); return null; }
            $data = @file_get_contents($tmpPng);
            @unlink($tmpPng);
            if ($data === false) return null;
            return 'data:image/png;base64,' . base64_encode($data);
        }
        return null;
    }

    private static function buildSvg(
        string $name,
        string $dept,
        string $headline,
        string $brandPrimary,
        string $brandSecondary,
        ?string $logoDataUri,
        string $locale
    ): string {
        $W = self::WIDTH; $H = self::HEIGHT;
        $darker = self::shade($brandPrimary, -0.35);
        $textColor = self::isLight($brandPrimary) ? '#0f172a' : '#ffffff';
        $muted     = $textColor === '#ffffff' ? 'rgba(255,255,255,0.78)' : 'rgba(15,23,42,0.68)';
        $faint     = $textColor === '#ffffff' ? 'rgba(255,255,255,0.55)' : 'rgba(15,23,42,0.5)';

        $nameLines = self::wrap($name, 22, 2);
        $hasTwoLines = count($nameLines) > 1;

        // Layout: logo card on the left, text block on the right.
        $cardX = 80;  $cardY = 115; $cardW = 400; $cardH = 400;
        $logoPadding = 50;
        $logoX = $cardX + $logoPadding;
        $logoY = $cardY + $logoPadding;
        $logoW = $cardW - 2 * $logoPadding;
        $logoH = $cardH - 2 * $logoPadding;

        $textX   = $cardX + $cardW + 70;
        $textTop = $hasTwoLines ? 230 : 270;

        $e = function(string $s): string {
            return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $nameSvg = '';
        foreach ($nameLines as $i => $line) {
            $y = $textTop + 64 * $i;
            $nameSvg .= '<text x="' . $textX . '" y="' . $y . '" font-family="Inter,Segoe UI,Roboto,Arial,sans-serif" font-size="56" font-weight="800" fill="' . $textColor . '">' . $e($line) . '</text>';
        }

        $deptSvg = '';
        if ($dept !== '') {
            $deptLines = self::wrap($dept, 28, 1);
            $deptSvg = '<text x="' . $textX . '" y="' . ($textTop + 64 * count($nameLines) + 10) . '" font-family="Inter,sans-serif" font-size="26" font-weight="500" fill="' . $muted . '">' . $e($deptLines[0]) . '</text>';
        }

        $headlineY = $textTop + 64 * count($nameLines) + ($dept !== '' ? 70 : 46);
        $headlineSvg = '<text x="' . $textX . '" y="' . $headlineY . '" font-family="Inter,sans-serif" font-size="30" font-weight="600" fill="' . $muted . '">' . $e($headline) . '</text>';

        $initial = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        if ($logoDataUri) {
            $logoSvg = '<image x="' . $logoX . '" y="' . $logoY . '" width="' . $logoW . '" height="' . $logoH . '" xlink:href="' . $logoDataUri . '" preserveAspectRatio="xMidYMid meet"/>';
        } else {
            $logoSvg = '<rect x="' . ($cardX + 80) . '" y="' . ($cardY + 80) . '" width="240" height="240" rx="24" fill="' . $e($brandPrimary) . '"/>'
                     . '<text x="' . ($cardX + $cardW / 2) . '" y="' . ($cardY + $cardH / 2 + 40) . '" text-anchor="middle" font-family="Inter,sans-serif" font-size="140" font-weight="800" fill="#ffffff">' . $e($initial) . '</text>';
        }

        $footer = '<text x="' . $textX . '" y="560" font-family="Inter,sans-serif" font-size="20" font-weight="600" fill="' . $faint . '">cardify.om</text>'
                . '<text x="' . ($W - 80) . '" y="560" text-anchor="end" font-family="Inter,sans-serif" font-size="18" font-weight="500" fill="' . $faint . '">Powered by Cardify</text>';

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="{$W}" height="{$H}" viewBox="0 0 {$W} {$H}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{$e($brandPrimary)}"/>
      <stop offset="1" stop-color="{$e($darker)}"/>
    </linearGradient>
    <filter id="shadow" x="-10%" y="-10%" width="120%" height="120%">
      <feGaussianBlur in="SourceAlpha" stdDeviation="8"/>
      <feOffset dx="0" dy="6" result="off"/>
      <feComponentTransfer><feFuncA type="linear" slope="0.25"/></feComponentTransfer>
      <feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>
  <rect width="{$W}" height="{$H}" fill="url(#bg)"/>
  <g filter="url(#shadow)">
    <rect x="{$cardX}" y="{$cardY}" width="{$cardW}" height="{$cardH}" rx="32" fill="#ffffff"/>
  </g>
  {$logoSvg}
  {$nameSvg}
  {$deptSvg}
  {$headlineSvg}
  {$footer}
</svg>
SVG;
    }

    private static function renderWithGd(
        string $outPath,
        string $name,
        string $dept,
        string $headline,
        string $brandPrimary,
        string $brandSecondary,
        ?string $logoPath,
        string $locale
    ): bool {
        if (!extension_loaded('gd')) return false;

        $W = self::WIDTH; $H = self::HEIGHT;
        $img = imagecreatetruecolor($W, $H);
        [$pr, $pg, $pb] = self::hexToRgb($brandPrimary);
        [$dr, $dg, $db] = self::hexToRgb(self::shade($brandPrimary, -0.35));

        // Vertical gradient approximates the SVG diagonal.
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            $r = (int)round($pr + ($dr - $pr) * $t);
            $g = (int)round($pg + ($dg - $pg) * $t);
            $b = (int)round($pb + ($db - $pb) * $t);
            $col = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $W, $y, $col);
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 80, 115, 480, 515, $white);

        if ($logoPath) {
            $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $logo = null;
            if ($ext === 'png')  $logo = @imagecreatefrompng($logoPath);
            elseif ($ext === 'jpg' || $ext === 'jpeg') $logo = @imagecreatefromjpeg($logoPath);
            if ($logo) {
                $lw = imagesx($logo); $lh = imagesy($logo);
                $maxW = 300; $maxH = 320;
                $scale = min($maxW / $lw, $maxH / $lh);
                $nw = (int)($lw * $scale); $nh = (int)($lh * $scale);
                $dstX = 80 + (int)((400 - $nw) / 2);
                $dstY = 115 + (int)((400 - $nh) / 2);
                imagecopyresampled($img, $logo, $dstX, $dstY, 0, 0, $nw, $nh, $lw, $lh);
                imagedestroy($logo);
            }
        }

        $textColor = self::isLight($brandPrimary) ? imagecolorallocate($img, 15, 23, 42) : $white;
        $muted = self::isLight($brandPrimary) ? imagecolorallocatealpha($img, 15, 23, 42, 50) : imagecolorallocatealpha($img, 255, 255, 255, 40);

        // GD's default bitmap fonts are ugly at this size; fall back to
        // imagestring which is the only option without bundled TTF files.
        $font = 5;
        $nameLines = self::wrap($name, 30, 2);
        $y = 240;
        foreach ($nameLines as $line) {
            imagestring($img, $font, 550, $y, $line, $textColor);
            $y += 30;
        }
        if ($dept !== '') {
            imagestring($img, 4, 550, $y + 10, $dept, $muted);
            $y += 30;
        }
        imagestring($img, 4, 550, $y + 20, $headline, $muted);
        imagestring($img, 3, 550, 560, 'cardify.om', $muted);
        imagestring($img, 2, $W - 220, 560, 'Powered by Cardify', $muted);

        $ok = imagepng($img, $outPath);
        imagedestroy($img);
        return $ok;
    }

    private static function wrap(string $text, int $maxPerLine, int $maxLines): array
    {
        $text = trim($text);
        if ($text === '') return [''];
        $words = preg_split('/\s+/u', $text);
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $probe = $cur === '' ? $w : $cur . ' ' . $w;
            if (mb_strlen($probe, 'UTF-8') <= $maxPerLine || $cur === '') {
                $cur = $probe;
            } else {
                $lines[] = $cur;
                $cur = $w;
                if (count($lines) >= $maxLines) break;
            }
        }
        if (count($lines) < $maxLines && $cur !== '') $lines[] = $cur;
        if (count($lines) > $maxLines) $lines = array_slice($lines, 0, $maxLines);
        // Truncate the last line if it's still too long.
        $last = array_pop($lines);
        if (mb_strlen($last, 'UTF-8') > $maxPerLine) {
            $last = mb_substr($last, 0, $maxPerLine - 1, 'UTF-8') . '…';
        }
        $lines[] = $last;
        return $lines;
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return [0, 155, 193];
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function shade(string $hex, float $ratio): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        if ($ratio < 0) {
            $r = (int)max(0, round($r * (1 + $ratio)));
            $g = (int)max(0, round($g * (1 + $ratio)));
            $b = (int)max(0, round($b * (1 + $ratio)));
        } else {
            $r = (int)min(255, round($r + (255 - $r) * $ratio));
            $g = (int)min(255, round($g + (255 - $g) * $ratio));
            $b = (int)min(255, round($b + (255 - $b) * $ratio));
        }
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private static function isLight(string $hex): bool
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $lum = 0.2126 * ($r / 255) + 0.7152 * ($g / 255) + 0.0722 * ($b / 255);
        return $lum > 0.6;
    }
}
