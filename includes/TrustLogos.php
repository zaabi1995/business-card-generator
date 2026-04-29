<?php
/**
 * Pulls curated Omani company logos for the homepage + signup trust
 * strip. Cached in APCu for 5 minutes so the marketing pages do not
 * pay a DB hit on every visit.
 */
class TrustLogos
{
    public const CACHE_TTL = 300;

    /**
     * Return up to $limit companies with a renderable logo. Only
     * curated=1 + logo_status IN ('verified','indexed') rows. Sorted
     * by updated_at DESC to lean toward "recently active".
     *
     * @return array<int, array{id:int,name_en:string,name_ar:?string,slug:string,logo_src:string}>
     */
    public static function recent(int $limit = 12): array
    {
        $key = 'cardify_trust_logos_v1_' . $limit;
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch($key, $ok);
            if ($ok && is_array($cached)) return $cached;
        }

        $rows = [];
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT id, name_en, name_ar, slug,
                        logo_webp_path, logo_png_512_path, logo_png_path, logo_svg_path
                 FROM om_companies
                 WHERE curated = 1
                   AND logo_status IN ('verified','indexed')
                   AND (logo_webp_path IS NOT NULL OR logo_png_512_path IS NOT NULL
                        OR logo_png_path IS NOT NULL OR logo_svg_path IS NOT NULL)
                 ORDER BY updated_at DESC
                 LIMIT :lim",
                ['lim' => $limit]
            );
        } catch (Throwable $e) {
            $rows = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $path = $r['logo_webp_path']
                ?? $r['logo_png_512_path']
                ?? $r['logo_png_path']
                ?? $r['logo_svg_path'];
            if (!$path) continue;
            $src = (string) $path;
            if ($src !== '' && $src[0] !== '/') $src = '/' . $src;
            $out[] = [
                'id'       => (int) $r['id'],
                'name_en'  => (string) ($r['name_en'] ?? ''),
                'name_ar'  => $r['name_ar'] ?? null,
                'slug'     => (string) ($r['slug'] ?? ''),
                'logo_src' => $src,
            ];
        }

        if (function_exists('apcu_store')) {
            apcu_store($key, $out, self::CACHE_TTL);
        }
        return $out;
    }
}
