<?php
/**
 * CardifyConvention
 *
 * Single source of truth for two naming rules that apply to every Cardify
 * tenant:
 *
 *  1. Company slug = the local-part of the company's email domain.
 *     - admin@alali.om                -> "alali"
 *     - support@oman-data-park.com   -> "omandatapark"  (hyphens stripped)
 *     - admin@otech.om               -> "otech"
 *     The slug becomes <slug>.cardify.om at the DNS / nginx layer.
 *
 *  2. Employee ID (URL slug) = the local-part of the employee's email,
 *     normalised to lowercase, with anything that isn't [a-z0-9.] turned
 *     into a dot, and consecutive dots collapsed.
 *     - ali.alzaabi@alali.om         -> "ali.alzaabi"
 *     - Ahmed Al Balushi <ahmed.balushi@otech.om> -> "ahmed.balushi"
 *     - bonus_round@alali.om         -> "bonus.round"
 *     The employee URL becomes <slug>.cardify.om/<id>.
 *
 * Both functions accept a database handle and resolve collisions deterministically
 * by appending -2, -3, ... when the slug or id is already taken.
 *
 * USAGE
 *   require_once __DIR__ . '/CardifyConvention.php';
 *   $slug = CardifyConvention::companySlugFromEmail('admin@alali.om', $db);
 *   $eid  = CardifyConvention::employeeIdFromEmail('ali.alzaabi@alali.om', $companyId, $db);
 */

class CardifyConvention
{
    /**
     * Reserved subdomains that cannot be used as company slugs.
     * Keep in sync with TenantHost::reservedSubdomains().
     */
    public static function reservedSlugs(): array
    {
        return [
            'www', 'mail', 'api', 'app', 'admin', 'docs', 'license', 'blog',
            'shop', 'status', 'smtp', 'imap', 'pop', 'ftp', 'cdn', 'static',
            'login', 'logout', 'register', 'install', 'share', 's', 'r',
            'press', 'help', 'support', 'pricing', 'about', 'contact', 'privacy',
            'terms', 'security', 'cookies', 'faq', 'careers', 'media', 'press-kit',
            'industries', 'solutions', 'tools', 'design', 'logos', 'companies',
        ];
    }

    /**
     * Strip everything to a clean lowercase ASCII slug. Hyphens and dots removed.
     * "Oman Data Park" -> "omandatapark"
     * "alali.om"       -> "alali"
     */
    public static function normalizeSlug(string $raw): string
    {
        $raw = strtolower(trim($raw));
        // Take only the first dot-separated component, drop any TLD or subdomain
        if (strpos($raw, '@') !== false) {
            $raw = substr($raw, strpos($raw, '@') + 1);
        }
        if (strpos($raw, '.') !== false) {
            $raw = substr($raw, 0, strpos($raw, '.'));
        }
        // Strip everything except a-z 0-9
        $raw = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
        return $raw;
    }

    /**
     * Derive a company slug from any email or domain string and resolve
     * collisions against the existing companies table.
     *
     * @param string                 $emailOrDomain
     * @param Database|object|null   $db
     * @param string|null            $excludeCompanyId  When updating a company in place
     * @return string                Unique slug
     */
    public static function companySlugFromEmail(string $emailOrDomain, $db = null, ?string $excludeCompanyId = null): string
    {
        $base = self::normalizeSlug($emailOrDomain);
        if ($base === '' || strlen($base) < 2) {
            $base = 'company';
        }
        // Truncate to 50 chars (subdomains capped at ~62 by DNS, leave room for collision suffix).
        if (strlen($base) > 50) {
            $base = substr($base, 0, 50);
        }
        // Avoid reserved subdomains.
        if (in_array($base, self::reservedSlugs(), true)) {
            $base = $base . 'co';
        }

        if ($db === null && class_exists('Database')) {
            $db = Database::getInstance();
        }
        if (!$db || !method_exists($db, 'fetchOne')) {
            return $base;
        }

        $candidate = $base;
        $n = 1;
        while (true) {
            $params = ['s' => $candidate];
            $sql = 'SELECT id FROM companies WHERE slug = :s';
            if ($excludeCompanyId) {
                $sql .= ' AND id <> :ex';
                $params['ex'] = $excludeCompanyId;
            }
            $sql .= ' LIMIT 1';
            $row = $db->fetchOne($sql, $params);
            if (!$row) {
                return $candidate;
            }
            $n++;
            $candidate = $base . $n;
        }
    }

    /**
     * Normalise the local-part of an email to a URL-safe employee id.
     *   "Ahmed.Al-Balushi"      -> "ahmed.al.balushi"
     *   "ali_alzaabi"           -> "ali.alzaabi"
     *   "first.last+tag"        -> "first.last.tag"
     */
    public static function normalizeEmployeeId(string $emailOrLocal): string
    {
        $local = strtolower(trim($emailOrLocal));
        // If a full email, strip the domain
        if (strpos($local, '@') !== false) {
            $local = substr($local, 0, strpos($local, '@'));
        }
        // Replace anything that isn't [a-z0-9.] with a dot
        $local = preg_replace('/[^a-z0-9.]+/', '.', $local) ?? '';
        // Collapse consecutive dots, trim leading/trailing dots
        $local = preg_replace('/\.+/', '.', $local) ?? '';
        $local = trim($local, '.');
        // Length cap to keep URLs manageable
        if (strlen($local) > 60) {
            $local = substr($local, 0, 60);
        }
        return $local;
    }

    /**
     * Derive a unique employee id from an email and resolve collisions
     * against the existing employees of the same company.
     *
     * @param string             $email
     * @param string             $companyId
     * @param Database|null      $db
     * @param string|null        $excludeEmployeeId  When updating in place
     * @return string            Unique employee id
     */
    public static function employeeIdFromEmail(string $email, string $companyId, $db = null, ?string $excludeEmployeeId = null): string
    {
        $base = self::normalizeEmployeeId($email);
        if ($base === '' || strlen($base) < 2) {
            $base = 'employee';
        }

        if ($db === null && class_exists('Database')) {
            $db = Database::getInstance();
        }
        if (!$db || !method_exists($db, 'fetchOne')) {
            return $base;
        }

        $candidate = $base;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM employees WHERE id = :i AND company_id = :c';
            $params = ['i' => $candidate, 'c' => $companyId];
            if ($excludeEmployeeId) {
                $sql .= ' AND id <> :ex';
                $params['ex'] = $excludeEmployeeId;
            }
            $sql .= ' LIMIT 1';
            $row = $db->fetchOne($sql, $params);
            if (!$row) {
                return $candidate;
            }
            $n++;
            $candidate = $base . $n;
        }
    }

    /**
     * Build the canonical absolute URL for an employee's E-Card on their
     * tenant subdomain. Handy for QR generation and email signatures.
     */
    public static function tenantUrl(string $companySlug, string $employeeId = ''): string
    {
        $url = 'https://' . $companySlug . '.cardify.om';
        if ($employeeId !== '') {
            $url .= '/' . $employeeId;
        }
        return $url;
    }
}
