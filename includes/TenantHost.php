<?php
/**
 * TenantHost: detect when the current request is for a {slug}.cardify.om
 * subdomain and resolve it to a companies row. Used by index.php and the
 * tenant OTP login flow.
 */

class TenantHost
{
    private static $resolved = null; // null=not yet, false=not a tenant, array=company

    public static function reservedSubdomains(): array
    {
        return ['www','mail','api','app','admin','docs','license','blog','shop','status','smtp','imap','pop','ftp','cdn','static'];
    }

    public static function isTenantHost(): bool
    {
        return self::resolve() !== null;
    }

    /** @return array|null companies row, or null if host is not a valid tenant */
    public static function resolve(): ?array
    {
        if (self::$resolved !== null) {
            return self::$resolved === false ? null : self::$resolved;
        }

        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host);

        if (!preg_match('/^([a-z0-9][a-z0-9-]{1,62})\.cardify\.om$/', $host, $m)) {
            self::$resolved = false;
            return null;
        }

        $slug = $m[1];
        if (in_array($slug, self::reservedSubdomains(), true)) {
            self::$resolved = false;
            return null;
        }

        if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) {
            self::$resolved = false;
            return null;
        }

        try {
            if (function_exists('findCompanyBySlug')) {
                $company = findCompanyBySlug($slug);
            } else {
                $db = Database::getInstance();
                $company = $db->fetchOne("SELECT * FROM companies WHERE slug = :s LIMIT 1", ['s' => $slug]);
            }
        } catch (Exception $e) {
            error_log('TenantHost resolve failed: ' . $e->getMessage());
            self::$resolved = false;
            return null;
        }

        if (!$company || ($company['status'] ?? 'active') !== 'active') {
            self::$resolved = false;
            return null;
        }

        self::$resolved = $company;
        return $company;
    }

    public static function slug(): ?string
    {
        $c = self::resolve();
        return $c['slug'] ?? null;
    }

    public static function id(): ?string
    {
        $c = self::resolve();
        return $c['id'] ?? null;
    }

    /**
     * Return the tenant's brand pack: logo URL, favicon URL, primary +
     * secondary colours, and display name. Used by ui-header.php to
     * auto-customise the favicon, page loader and brand-coloured spinner
     * for every page on a {slug}.cardify.om subdomain.
     *
     * Auto-fallbacks (so HR doesn't have to upload separate assets):
     *   - favicon defaults to the logo when company_themes.favicon_path
     *     is NULL (the most common case after registration). Browsers
     *     accept SVG / PNG logos as favicon directly.
     *   - colours default to a sensible neutral pair when company_themes
     *     row doesn't exist yet.
     *
     * Returns null when the host isn't a tenant subdomain.
     *
     * @return array{logo:?string,favicon:?string,primary:string,secondary:string,name:string}|null
     */
    private static $brandCache = null;
    public static function theme(): ?array
    {
        if (self::$brandCache !== null) {
            return self::$brandCache === false ? null : self::$brandCache;
        }
        $c = self::resolve();
        if (!$c) {
            self::$brandCache = false;
            return null;
        }
        $theme = null;
        try {
            $db = Database::getInstance();
            $theme = $db->fetchOne(
                "SELECT logo_path, favicon_path, primary_color, secondary_color
                 FROM company_themes WHERE company_id = :cid LIMIT 1",
                ['cid' => $c['id']]
            );
        } catch (Throwable $e) {
            // Theme table missing on a fresh install: just use fallbacks.
        }

        // Normalise upload paths to absolute URLs. company_themes.logo_path
        // is stored as either '/uploads/...' (preferred) or
        // 'companies/<id>/theme/foo.svg' (legacy bare relative). Prepend
        // '/uploads/' for the legacy form so <link href> works on any host.
        $normalize = function ($p) {
            if (!$p) return null;
            $p = trim((string)$p);
            if ($p === '' || preg_match('#^https?://#i', $p)) return $p;
            if ($p[0] === '/') return $p;
            return '/uploads/' . ltrim($p, '/');
        };

        $logoUrl    = $normalize($theme['logo_path']    ?? null);
        $faviconUrl = $normalize($theme['favicon_path'] ?? null);

        // Auto-derive favicon from logo when not explicitly set. Most
        // tenants only upload one logo; the browser accepts SVG / PNG
        // logos as favicons directly.
        if (!$faviconUrl && $logoUrl) {
            $faviconUrl = $logoUrl;
        }

        self::$brandCache = [
            'logo'      => $logoUrl,
            'favicon'   => $faviconUrl,
            'primary'   => $theme['primary_color']   ?? '#0f3354',
            'secondary' => $theme['secondary_color'] ?? '#c9a961',
            'name'      => $c['name'] ?? ($c['slug'] ?? 'Cardify'),
        ];
        return self::$brandCache;
    }
}
