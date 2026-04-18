<?php
/**
 * LogoLibrary — shared helpers for the Omani Logo Library.
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

    public static function canDownload(array $company): bool {
        return ($company['logo_status'] ?? 'none') === 'verified';
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

    public static function ipHash(): string {
        return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|cardify-logo-salt');
    }

    public static function uaHash(): string {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|cardify-logo-salt');
    }
}
