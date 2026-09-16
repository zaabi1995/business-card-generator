<?php
/**
 * Web variants for tenant brand images (company_themes.logo_path /
 * favicon_path).
 *
 * Those two files are stored at whatever size the client uploaded and were
 * never resized. Measured on the live VPS: 6 of 23 theme images were over
 * 60KB, the worst a 292KB favicon and a 98KB logo that the public card page
 * draws at max-width 96px (about 23px tall). On a 400kbps profile the Shield
 * logo alone was roughly 2 seconds of the connection, shipped on every card
 * view, and the same file is also used by the admin UI and the portal.
 *
 * Same pattern as scripts/backfill-card-web-variants.php: write capped
 * siblings next to the original and leave the original alone, so the Apple /
 * Google wallet passes and the print paths keep reading the exact bytes the
 * tenant uploaded. Only the browser-facing surfaces prefer a sibling.
 *
 * TWO siblings per image, because the two consumers differ:
 *   <base>_web.webp  for <img> tags (logo on the card, portal, admin chrome)
 *   <base>_web.png   for <link rel="icon">
 * Safari has never rendered a WebP favicon reliably, and Safari is the browser
 * that opens a scanned card. A broken favicon fails silently, so the icon link
 * gets a same-format capped PNG instead.
 *
 * Naming is "<base>_web.*", NOT "<base>-web.*", because wallet_apple.php finds
 * a tenant's reverse logo by globbing "<base>-dark.*" (cardify skill rule 45).
 * An underscore cannot collide with that pattern.
 */

if (!class_exists('ThemeImage')) {

final class ThemeImage
{
    /** Long edge caps. A logo renders at 96px CSS, a favicon at 32-180px. */
    public const LOGO_MAX    = 400;
    public const FAVICON_MAX = 512;

    public const QUALITY     = 82;
    public const SUFFIX_WEBP = '_web.webp';
    public const SUFFIX_PNG  = '_web.png';

    /**
     * Long-edge cap for a tenant whose card page raises the header logo above
     * the default 96px (company_themes.logo_max_px). LOGO_MAX = 400 is ~4x for a
     * 96px draw, but only 1.7x once a wide wordmark draws at 240px, which looks
     * soft on a phone. Targets 2.5x the tenant's cap (sharp on a phone without
     * paying 3x the bytes on fine line art) and never goes below 400, so a
     * tenant with no cap set is byte-identical to before.
     */
    public static function logoMaxFor($logoMaxPx): int
    {
        $cap = (int) $logoMaxPx;
        if ($cap <= 0) return self::LOGO_MAX;
        return max(self::LOGO_MAX, min(2000, (int) round($cap * 2.5)));
    }

    /** prefer*() runs on every page render; memoise the stat calls. */
    private static array $preferCache = [];

    private static function baseDir(): string
    {
        return defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
    }

    /**
     * Resolve a stored theme path to an absolute file on disk.
     *
     * company_themes paths come in three shapes depending on which flow wrote
     * them: "/uploads/logos/x.png" (onboarding, apply_theme), "uploads/..",
     * and bare "companies/<id>/theme/x.png" (the theme builder). Mirrors the
     * candidate list in wallet_apple.php.
     *
     * Returns null when the file is missing or resolves outside uploads/.
     */
    public static function absPath(?string $stored): ?string
    {
        $p = trim((string) $stored);
        if ($p === '' || preg_match('#^(https?:)?//#i', $p)) return null;

        $root    = rtrim(self::baseDir(), '/');
        $uploads = $root . '/uploads';
        $rel     = ltrim($p, '/');

        $cands = [];
        if ($p[0] === '/' || strpos($rel, 'uploads/') === 0) {
            $cands[] = $root . '/' . $rel;
        }
        $cands[] = $uploads . '/' . $rel;

        $realUploads = realpath($uploads);
        if ($realUploads === false) return null;

        foreach ($cands as $c) {
            if (!is_file($c)) continue;
            // Never step outside uploads/ on a crafted path.
            $real = realpath($c);
            if ($real === false) continue;
            if (strncmp($real, $realUploads . DIRECTORY_SEPARATOR, strlen($realUploads) + 1) !== 0) continue;
            return $real;
        }
        return null;
    }

    /** "companies/x/theme/logo_1.png" -> "companies/x/theme/logo_1_web.webp" */
    public static function siblingStored(string $stored, string $suffix = self::SUFFIX_WEBP): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', trim($stored)) . $suffix;
    }

    /** Absolute sibling path for an absolute original path. */
    public static function siblingAbs(string $abs, string $suffix = self::SUFFIX_WEBP): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $abs) . $suffix;
    }

    /** Every sibling this class can produce for one stored path. */
    public static function siblingsOf(string $stored): array
    {
        return [
            self::siblingStored($stored, self::SUFFIX_WEBP),
            self::siblingStored($stored, self::SUFFIX_PNG),
        ];
    }

    /** A variant is never itself a source. */
    public static function isVariant(string $path): bool
    {
        return (bool) preg_match('/_web\.(webp|png)$/i', trim($path));
    }

    /** GD cannot read SVG or ICO, and an SVG is already tiny and scalable. */
    public static function isConvertible(string $abs): bool
    {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($ext === 'svg' || $ext === 'ico') return false;
        return in_array((int) (@exif_imagetype($abs) ?: 0),
            [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true);
    }

    /**
     * Build (or refresh) both siblings for one stored theme path.
     *
     * Idempotent: leaves a sibling alone when it is already newer than the
     * original. apply_theme.php and api/onboarding.php both write a FIXED
     * filename (logo.<ext>), so a replaced logo keeps the same path and only
     * the mtime moves. Without the mtime check those tenants would serve the
     * previous brand forever.
     *
     * A sibling that comes out no smaller than the original is deleted, so the
     * prefer*() readers fall back to the original rather than shipping more
     * bytes than before.
     *
     * @return array{webp:?string,png:?string} stored-shape paths, null where skipped
     */
    public static function ensureVariants(?string $stored, int $maxEdge, bool $force = false): array
    {
        $out = ['webp' => null, 'png' => null];
        $abs = self::absPath($stored);
        if ($abs === null) return $out;
        if (self::isVariant($abs)) return $out;
        if (!self::isConvertible($abs)) return $out;

        $srcBytes = (int) filesize($abs);
        $plan = [
            'webp' => [self::SUFFIX_WEBP, 'webp'],
            'png'  => [self::SUFFIX_PNG,  'png'],
        ];
        if (!function_exists('imagewebp')) unset($plan['webp']);

        $changed = false;
        foreach ($plan as $key => [$suffix, $format]) {
            $dstAbs = self::siblingAbs($abs, $suffix);
            if (!$force && is_file($dstAbs) && filemtime($dstAbs) >= filemtime($abs)) {
                $out[$key] = self::siblingStored((string) $stored, $suffix);
                continue;
            }
            if (!self::write($abs, $dstAbs, $maxEdge, $format)) continue;
            if (filesize($dstAbs) >= $srcBytes) {
                // No win. Keeping it would make the page heavier, not lighter.
                @unlink($dstAbs);
                $changed = true;
                continue;
            }
            $out[$key] = self::siblingStored((string) $stored, $suffix);
            $changed = true;
        }
        if ($changed) self::$preferCache = [];
        return $out;
    }

    /** Resize + encode. Returns true only when a valid file landed on disk. */
    public static function write(string $srcAbs, string $dstAbs, int $maxEdge, string $format = 'webp'): bool
    {
        $info = @getimagesize($srcAbs);
        if (!$info) return false;
        [$w, $h] = $info;
        if ($w <= 0 || $h <= 0) return false;

        $long  = max($w, $h);
        $scale = $long > $maxEdge ? $maxEdge / $long : 1.0;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        // switch, not match: the syntax-check CI job lints includes/ on PHP 7.4,
        // which is still the stated product floor.
        switch ((int) $info[2]) {
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcAbs);  break;
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcAbs); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcAbs); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcAbs);  break;
            default:             $src = null;
        }
        if (!$src) return false;

        $dst = imagecreatetruecolor($nw, $nh);
        // Brand logos are usually transparent PNGs; without this the padding
        // around the mark goes solid black on the card page.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $ok = $format === 'png'
            ? @imagepng($dst, $dstAbs, 9)
            : @imagewebp($dst, $dstAbs, self::QUALITY);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || !is_file($dstAbs) || filesize($dstAbs) < 32) {
            @unlink($dstAbs);
            return false;
        }
        self::fixPerms($dstAbs);
        return true;
    }

    /**
     * The backfill runs as root from cron and the webserver runs as www.
     * Without this the variant 403s on every public card page.
     */
    public static function fixPerms(string $abs): void
    {
        @chmod($abs, 0644);
        if (function_exists('chown') && function_exists('posix_getuid') && posix_getuid() === 0) {
            @chown($abs, 'www');
            @chgrp($abs, 'www');
        }
    }

    /**
     * Browser-facing path preference: the capped sibling when it exists and is
     * not older than the original, else the original unchanged.
     *
     * Returns the same path SHAPE it was given, so every existing caller can
     * keep passing the result to cardifyAssetUrl() / TenantHost's normaliser.
     * Never use this for the wallet passes or the print renderers, they must
     * read the uploaded bytes.
     */
    public static function preferWeb(?string $stored): string
    {
        return self::prefer($stored, self::SUFFIX_WEBP);
    }

    /** As preferWeb, but for <link rel="icon">: same-format PNG, not WebP. */
    public static function preferIcon(?string $stored): string
    {
        return self::prefer($stored, self::SUFFIX_PNG);
    }

    private static function prefer(?string $stored, string $suffix): string
    {
        $p = trim((string) $stored);
        if ($p === '') return '';
        $key = $suffix . '|' . $p;
        if (isset(self::$preferCache[$key])) return self::$preferCache[$key];

        $result = $p;
        $abs = self::absPath($p);
        if ($abs !== null && !self::isVariant($abs)) {
            $sib = self::siblingAbs($abs, $suffix);
            if (is_file($sib) && filemtime($sib) >= filemtime($abs)) {
                $result = self::siblingStored($p, $suffix);
            }
        }
        return self::$preferCache[$key] = $result;
    }

    /** Test seam: drop the per-request memo. */
    public static function resetCache(): void
    {
        self::$preferCache = [];
    }
}

}
