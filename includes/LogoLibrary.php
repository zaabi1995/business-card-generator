<?php
/**
 * LogoLibrary, shared helpers for the Omani Logo Library.
 *
 * Responsibilities:
 *   - Path derivation for a company's logo variants
 *   - URL construction (public paths served from /storage/)
 *   - Domain parsing + match check for auto-verify
 *   - Dominant color extraction
 *   - Status-aware download eligibility
 */
class LogoLibrary {

    const STORAGE_BASE = '/storage/logos';

    /** @return array{svg:?string,png:?string,png_512:?string,png_2048:?string,webp:?string} */
    public static function publicPaths(array $company): array {
        return [
            'svg'     => $company['logo_svg_path']      ?? null,
            'png'     => $company['logo_png_path']      ?? null,
            'png_512' => $company['logo_png_512_path']  ?? null,
            'png_2048'=> $company['logo_png_2048_path'] ?? null,
            'webp'    => $company['logo_webp_path']     ?? null,
        ];
    }

    public static function storageDir(string $status, int $companyId): string {
        $status = in_array($status, ['indexed','verified','pending'], true) ? $status : 'indexed';
        $base = dirname(__DIR__) . '/storage/logos/' . $status;
        if (!is_dir($base)) @mkdir($base, 0755, true);
        return $base;
    }

    public static function deriveDomain(?string $url): ?string {
        if (!$url) return null;
        $url = trim($url);
        if ($url === '') return null;
        if (!preg_match('~^https?://~i', $url)) $url = 'http://' . $url;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        $host = strtolower($host);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        return $host ?: null;
    }

    /**
     * Is $userEmail's domain a match for $companyDomain?
     * Exact root-domain match, case-insensitive, ignoring `www.` prefix.
     * Generic providers always return false.
     */
    public static function emailDomainMatchesCompany(string $userEmail, ?string $companyDomain): bool {
        if (!$companyDomain) return false;
        $generic = [
            'gmail.com','yahoo.com','hotmail.com','outlook.com','live.com',
            'icloud.com','aol.com','proton.me','protonmail.com','zoho.com','mail.com',
        ];
        $userEmail = strtolower(trim($userEmail));
        $at = strrpos($userEmail, '@');
        if ($at === false) return false;
        $ud = substr($userEmail, $at + 1);
        if (in_array($ud, $generic, true)) return false;
        return $ud === strtolower($companyDomain);
    }

    /**
     * How many om_companies rows share this domain?
     * Used to gate auto-verify (>1 means conflicting match).
     */
    public static function countCompaniesForDomain(Database $db, string $domain): int {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c FROM om_companies WHERE website_domain_cache = :d",
            [':d' => $domain]
        );
        return (int) ($row['c'] ?? 0);
    }

    public static function statusBadge(string $status): array {
        return match ($status) {
            'verified' => ['label' => 'Verified', 'color' => '#16a34a'],
            'indexed'  => ['label' => 'Indexed',  'color' => '#6b7280'],
            'pending'  => ['label' => 'Pending',  'color' => '#d97706'],
            'disputed' => ['label' => 'Disputed', 'color' => '#dc2626'],
            'takedown' => ['label' => 'Removed',  'color' => '#4b5563'],
            default    => ['label' => 'No Logo',  'color' => '#9ca3af'],
        };
    }

    /**
     * Can this logo be downloaded right now?
     * Library policy: allow SVG/PNG/WebP downloads for BOTH 'indexed' and
     * 'verified' statuses, the library is a public reference archive and
     * downloads are helpful for journalists, designers, researchers. Takedown
     * and disputed statuses remain blocked. Verified logos get a little extra
     * (the "Verified by owner" badge), but download gating is the same.
     */
    public static function canDownload(array $company): bool {
        return in_array($company['logo_status'] ?? 'none', ['indexed', 'verified'], true);
    }

    /**
     * Extract dominant color via ColorThief-PHP if available, else GD-based 1-pixel fallback.
     * Returns hex '#RRGGBB' or null.
     */
    public static function dominantColor(string $imagePath): ?string {
        if (!is_file($imagePath)) return null;
        if (class_exists('\ColorThief\ColorThief')) {
            try {
                $rgb = \ColorThief\ColorThief::getColor($imagePath, 10);
                return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
            } catch (Throwable $e) { /* fall through */ }
        }
        // GD fallback: resize to 1x1 and read pixel
        $mime = function_exists('mime_content_type') ? mime_content_type($imagePath) : null;
        $src = match ($mime) {
            'image/png'  => @imagecreatefrompng($imagePath),
            'image/jpeg' => @imagecreatefromjpeg($imagePath),
            'image/webp' => @imagecreatefromwebp($imagePath),
            default      => null,
        };
        if (!$src) return null;
        $tmp = imagecreatetruecolor(1, 1);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, 1, 1, imagesx($src), imagesy($src));
        $rgb = imagecolorat($tmp, 0, 0);
        $r = ($rgb >> 16) & 0xff; $g = ($rgb >> 8) & 0xff; $b = $rgb & 0xff;
        imagedestroy($src); imagedestroy($tmp);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Extract up to N (default 5) dominant colors as a hex palette.
     * Uses GD: downsamples to 64x64, builds a 4-bit-per-channel histogram
     * (4096 bins), filters near-white/near-black background unless they
     * dominate. Returns ['#RRGGBB', ...] ordered by frequency.
     */
    public static function palette(string $imagePath, int $count = 5): array {
        if (!is_file($imagePath)) return [];
        $mime = function_exists('mime_content_type') ? mime_content_type($imagePath) : null;
        $src = match ($mime) {
            'image/png'  => @imagecreatefrompng($imagePath),
            'image/jpeg' => @imagecreatefromjpeg($imagePath),
            'image/webp' => @imagecreatefromwebp($imagePath),
            'image/gif'  => @imagecreatefromgif($imagePath),
            default      => null,
        };
        if (!$src) return [];

        $w = imagesx($src);
        $h = imagesy($src);
        $size = 64;
        $small = imagecreatetruecolor($size, $size);
        // Preserve alpha so PNG transparency maps to a "skip" pixel
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $size, $size, $w, $h);
        imagedestroy($src);

        $bins = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                $a = ($rgba >> 24) & 0x7f;
                if ($a > 100) continue; // ~78% transparent or more, skip
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                // 4-bit quantize, ~4096 bins
                $key = ($r >> 4) << 8 | ($g >> 4) << 4 | ($b >> 4);
                if (!isset($bins[$key])) {
                    $bins[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $bins[$key]['count']++;
                $bins[$key]['r'] += $r;
                $bins[$key]['g'] += $g;
                $bins[$key]['b'] += $b;
            }
        }
        imagedestroy($small);
        if (empty($bins)) return [];

        // Sort by count desc
        uasort($bins, fn($a, $b) => $b['count'] - $a['count']);

        // Average each bin back to a real color, then dedupe colors that
        // are within ~20 units in any channel (avoid 5 shades of the
        // same near-white "background").
        $palette = [];
        $total   = array_sum(array_column($bins, 'count'));
        $minShare = 0.01; // 1% minimum to count
        foreach ($bins as $bin) {
            if ($bin['count'] / $total < $minShare) break;
            $r = (int) round($bin['r'] / $bin['count']);
            $g = (int) round($bin['g'] / $bin['count']);
            $b = (int) round($bin['b'] / $bin['count']);
            // De-duplicate near-identical colors already in the palette
            $tooClose = false;
            foreach ($palette as $p) {
                if (abs($p[0] - $r) < 20 && abs($p[1] - $g) < 20 && abs($p[2] - $b) < 20) {
                    $tooClose = true;
                    break;
                }
            }
            if ($tooClose) continue;
            $palette[] = [$r, $g, $b];
            if (count($palette) >= $count) break;
        }

        // Demote pure white / near-white when the palette has at least
        // one non-background color, the brand color is what users want.
        $hasNonBg = false;
        foreach ($palette as $p) {
            if (!self::isBackground($p)) { $hasNonBg = true; break; }
        }
        if ($hasNonBg) {
            usort($palette, fn($a, $b) => (self::isBackground($a) ? 1 : 0) - (self::isBackground($b) ? 1 : 0));
        }

        return array_map(
            fn($p) => sprintf('#%02x%02x%02x', $p[0], $p[1], $p[2]),
            $palette
        );
    }

    private static function isBackground(array $rgb): bool {
        // Near-white: all channels > 230. Near-black: all < 25.
        return ($rgb[0] > 230 && $rgb[1] > 230 && $rgb[2] > 230)
            || ($rgb[0] < 25  && $rgb[1] < 25  && $rgb[2] < 25);
    }

    /**
     * Recolor an SVG document string into a single-color monochrome
     * (black, white, or any target hex). Best-effort: respects
     * none/transparent/currentColor/inherit keywords, replaces solid
     * fill+stroke attrs and inline style fills, drops <defs> so
     * gradients/patterns become the target color too, and ensures the
     * root <svg> carries a default fill so unfilled paths inherit it.
     */
    public static function recolorSvgString(string $svg, string $color): string {
        $skip = ['none', 'transparent', 'currentcolor', 'inherit'];

        // Strip <defs> first so gradient/pattern refs collapse to solid
        $svg = preg_replace('#<defs\b[^>]*>.*?</defs>#is', '', $svg) ?? $svg;

        // Replace fill="..."/'...'
        $svg = preg_replace_callback(
            '/\b(fill|stroke)\s*=\s*(["\'])([^"\']*)\2/i',
            function ($m) use ($color, $skip) {
                $val = strtolower(trim($m[3]));
                if ($val === '' || in_array($val, $skip, true)) return $m[0];
                return $m[1] . '=' . $m[2] . $color . $m[2];
            },
            $svg
        ) ?? $svg;

        // Replace inline style fill:/stroke:
        $svg = preg_replace_callback(
            '/\b(fill|stroke)\s*:\s*([^;"\'\s}]+)/i',
            function ($m) use ($color, $skip) {
                $val = strtolower(trim($m[2]));
                if ($val === '' || in_array($val, $skip, true)) return $m[0];
                return $m[1] . ': ' . $color;
            },
            $svg
        ) ?? $svg;

        // Add a root <svg fill="..."> so unfilled paths inherit the target
        if (!preg_match('/<svg\b[^>]*\bfill\s*=/i', $svg)) {
            $svg = preg_replace('/<svg\b/i', '<svg fill="' . $color . '"', $svg, 1);
        }
        return $svg;
    }

    /**
     * Heuristic: does the logo's palette suggest a light-leaning design
     * (e.g. white wordmark + accent) that would render invisibly on a
     * white card background? True only when the top 2 colors include a
     * near-white WITHOUT a near-black companion (which would mean it's
     * a 2-tone that works on either side).
     */
    public static function shouldUseDarkVariantOnLight(?array $palette): bool {
        if (empty($palette)) return false;
        $top = array_slice($palette, 0, 2);
        $hasLight = false;
        $hasDark  = false;
        foreach ($top as $hex) {
            $L = self::hexLuminance((string) $hex);
            if ($L === null) continue;
            if ($L > 0.90) $hasLight = true;
            if ($L < 0.20) $hasDark  = true;
        }
        return $hasLight && !$hasDark;
    }

    /** sRGB perceived luminance, 0.0 (black) to 1.0 (white). */
    public static function hexLuminance(string $hex): ?float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return null;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }

    /**
     * Recolor a raster (PNG/WebP) into solid color while preserving
     * alpha. Returns true on success. Requires ImageMagick `convert`.
     */
    public static function recolorRasterFile(string $src, string $dst, string $color): bool {
        if (!is_file($src)) return false;
        $convert = trim((string) @shell_exec('command -v convert 2>/dev/null'));
        if ($convert === '') return false;
        $rc = 0;
        // -fill <color> -colorize 100 paints every non-transparent pixel
        // the target color (preserving alpha). Works on PNG + WebP.
        @exec(
            escapeshellarg($convert) . ' ' . escapeshellarg($src)
            . ' -fill ' . escapeshellarg($color) . ' -colorize 100 '
            . escapeshellarg($dst) . ' 2>/dev/null',
            $_o, $rc
        );
        if ($rc !== 0 || !is_file($dst) || filesize($dst) < 100) return false;
        @chmod($dst, 0644);
        return true;
    }

    /**
     * One-shot: produce dark + white monochrome variants (SVG when
     * source SVG is available, PNG + WebP always) for a company id.
     * Writes alongside the original (-dark / -white suffixes) and
     * returns the relative path map suitable for an om_companies UPDATE.
     * Idempotent.
     */
    public static function generateMonochromeVariants(int $companyId): array {
        $db = self::dbOrNull();
        if (!$db) return [];

        $row = $db->fetchOne(
            "SELECT id, logo_svg_path, logo_png_path, logo_png_2048_path
             FROM om_companies WHERE id = :id",
            [':id' => $companyId]
        );
        if (!$row) return [];

        $root = realpath(__DIR__ . '/..');
        if (!$root) return [];

        $svgRel = $row['logo_svg_path'] ?? null;
        $pngRel = $row['logo_png_path'] ?? $row['logo_png_2048_path'] ?? null;
        $out = [];

        // SVG -> SVG dark/white
        if ($svgRel && is_file($root . $svgRel)) {
            $src = file_get_contents($root . $svgRel);
            if ($src) {
                foreach ([['dark', '#111111'], ['white', '#ffffff']] as [$tone, $color]) {
                    $dstAbs = $root . str_replace('.svg', "-$tone.svg", $svgRel);
                    $recolored = self::recolorSvgString($src, $color);
                    if (file_put_contents($dstAbs, $recolored) !== false) {
                        @chmod($dstAbs, 0644);
                        $out["logo_svg_{$tone}_path"] = str_replace('.svg', "-$tone.svg", $svgRel);
                    }
                }
            }
        }

        // PNG -> PNG dark/white (and same for WebP if WebP exists)
        if ($pngRel && is_file($root . $pngRel)) {
            $srcAbs = $root . $pngRel;
            foreach ([['dark', '#111111'], ['white', '#ffffff']] as [$tone, $color]) {
                $dstRel = str_replace('.png', "-$tone.png", $pngRel);
                $dstAbs = $root . $dstRel;
                if (self::recolorRasterFile($srcAbs, $dstAbs, $color)) {
                    self::trimRasterFile($dstAbs);
                    $out["logo_png_{$tone}_path"] = $dstRel;
                    // Same recolor for WebP using the PNG as source
                    $webpRel = str_replace('.png', "-$tone.webp", $pngRel);
                    $webpAbs = $root . $webpRel;
                    if (self::recolorRasterFile($srcAbs, $webpAbs, $color)) {
                        $out["logo_webp_{$tone}_path"] = $webpRel;
                    }
                }
            }
        }

        if ($out) {
            $out['logo_variants_at'] = date('Y-m-d H:i:s');
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($out)));
            $params = [];
            foreach ($out as $k => $v) $params[":$k"] = $v;
            $params[':id'] = $companyId;
            $db->getConnection()
                ->prepare("UPDATE om_companies SET $set, logo_updated_at = NOW() WHERE id = :id")
                ->execute($params);
        }
        return $out;
    }

    /**
     * r27-14: the archive count and the verified count are two populations,
     * and every surface that publishes a logo number must say which one it
     * means. "106 Verified Omani brand logos" was the archive count wearing
     * the verified word: 94 of those rows are 'indexed', crawled by us and
     * confirmed by nobody. One query per population, called from every
     * surface, so a copy edit can never invent a third number.
     */
    public static function archiveCount(): int {
        static $n = null;
        if ($n === null) {
            $db = self::dbOrNull();
            $r  = $db ? $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')") : null;
            $n  = $r ? (int) $r['c'] : 0;
        }
        return $n;
    }

    public static function verifiedCount(): int {
        static $n = null;
        if ($n === null) {
            $db = self::dbOrNull();
            $r  = $db ? $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status = 'verified'") : null;
            $n  = $r ? (int) $r['c'] : 0;
        }
        return $n;
    }

    /**
     * A rounded floor for "N+" copy. It must stay strictly BELOW the real
     * count, or the "+" asserts rows that do not exist (r20-32), so a count
     * that lands exactly on a ten drops one step.
     */
    public static function archiveFloor(int $step = 10): int {
        $c = self::archiveCount();
        if ($c <= $step) return max(0, $c - 1);
        $f = intdiv($c, $step) * $step;
        if ($f >= $c) $f -= $step;
        return $f;
    }

    /** Singleton accessor that won't throw if DB isn't configured. */
    private static function dbOrNull(): ?Database {
        try { return Database::getInstance(); } catch (Throwable $e) { return null; }
    }

    /**
     * Trim transparent borders from a raster file (PNG / WebP) in-place.
     * Logos uploaded with whitespace in the source viewBox render with
     * empty edges around the visible content; trimming gives every file
     * a tight bounding box so the consumer can center it predictably.
     * No-op if ImageMagick `convert` is unavailable, or if the file is
     * already tight (convert -trim only touches matching transparent
     * edge pixels, never the inner content).
     */
    public static function trimRasterFile(string $path): bool {
        if (!is_file($path)) return false;
        $convert = trim((string) @shell_exec('command -v convert 2>/dev/null'));
        if ($convert === '') return false;
        $bytesBefore = filesize($path);
        $rc = 0; $out = [];
        @exec(escapeshellarg($convert) . ' ' . escapeshellarg($path) . ' -trim +repage '
              . escapeshellarg($path) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0 || !is_file($path) || filesize($path) < 100) {
            return false;
        }
        clearstatcache(true, $path);
        return true;
    }

    public static function ipHash(): string {
        // Use the shared getClientIp() helper so deployments behind Cloudflare /
        // reverse proxies hash the real client IP, not the proxy address.
        if (!function_exists('getClientIp')) {
            require_once __DIR__ . '/UrlSafety.php';
        }
        return hash('sha256', getClientIp() . '|cardify-logo-salt');
    }

    public static function uaHash(): string {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|cardify-logo-salt');
    }
}
