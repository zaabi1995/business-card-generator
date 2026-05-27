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
