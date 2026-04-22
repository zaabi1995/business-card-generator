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
}
