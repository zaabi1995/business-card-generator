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
        if (strlen($local) > 36) {
            $local = substr($local, 0, 36);
        }
        return $local;
    }

    /**
     * Derive a unique employee id from an email and resolve collisions
     * against the globally unique employees.id primary key.
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
            $sql = 'SELECT id FROM employees WHERE id = :i';
            $params = ['i' => $candidate];
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
            $suffix = (string) $n;
            $candidate = substr($base, 0, 36 - strlen($suffix)) . $suffix;
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

    /**
     * Path-reserved localparts that collide with real tenant routes
     * (served before the bare-slug card resolver). Mirror of the JS
     * CARDIFY_RESERVED_SLUGS in admin/auto_generate.php + admin/onboarding.php.
     * A localpart in this set must be served as /card/<x>, not /<x>.
     */
    public static function shareReservedSlugs(): array
    {
        return [
            'admin','api','login','logout','portal','assets','uploads','data','logs',
            'includes','printshop','paymob','amwalpay','webhooks','install','cron',
            'storage','vendor','card','vcf','vcard','wallet','wallet-apple','wallet-google',
            'sitemap','robots','favicon','og','r','claim','preview','index','public',
        ];
    }

    /**
     * The ONE correct way to build a public E-Card share URL for an employee.
     * Prefers the pretty email localpart (e.g. /jarwish9, resolved by the
     * index.php localpart fallback even when the employee id is a random hex),
     * and falls back to the always-resolvable canonical /card/<id> form when
     * the localpart is missing, route-unsafe, or a reserved path.
     *
     * Use this EVERYWHERE a share URL is built server-side. Never derive a
     * card URL from the employee NAME (a name slug matches neither the id nor
     * the localpart and 404s to the request portal).
     *
     * @param string $companySlug  tenant slug
     * @param array  $employee     employee row (needs 'id'; uses 'email' if present)
     */
    public static function employeeShareUrl(string $companySlug, array $employee): string
    {
        $base = 'https://' . $companySlug . '.cardify.om';
        $id   = (string) ($employee['id'] ?? '');
        $email = (string) ($employee['email'] ?? '');

        // Prefer the pretty bare localpart, but ONLY when nginx will actually
        // route it: a single no-dot token (-> index.php bare resolver) or a
        // single-dot first.last (-> digital_card). Multi-dot or otherwise odd
        // localparts are not routed bare and must use the /card/<id> form.
        if ($email !== '' && strpos($email, '@') !== false) {
            $local = strtolower(substr($email, 0, strpos($email, '@')));
            $routableBare = preg_match('/^[a-z0-9][a-z0-9_-]*$/', $local)
                         || preg_match('/^[a-z0-9]+\.[a-z0-9]+$/', $local);
            if ($routableBare && !in_array($local, self::shareReservedSlugs(), true)) {
                return $base . '/' . rawurlencode($local);
            }
        }
        // Canonical, always-resolvable fallback (id lookup + localpart fallback
        // in digital_card.php; the /card/ route accepts any token).
        if ($id !== '') {
            return $base . '/card/' . rawurlencode(strtolower($id));
        }
        return $base . '/';
    }

    /**
     * Resolve a URL path token (employee UUID, full email, or email
     * localpart) to an employee row scoped to a company. THE canonical
     * lookup: mirrors the 3-tier resolution digital_card.php uses for
     * /{slug}/card/{id} and bare-token routes, so any caller that needs
     * "what employee does this token mean" gets identical behaviour to
     * the public card page instead of a re-implemented, drifting copy.
     *
     *   1. token contains '@'      -> exact email match, no status gate
     *      (a stale/inactive employee still resolves on an exact email
     *      hit, matching digital_card.php's current behaviour)
     *   2. exact id match          -> no status gate (same reason)
     *   3. localpart fallback      -> status='active' AND deleted_at IS
     *      NULL, matches dotted/underscored/dashed variants of the same
     *      localpart (e.g. "jarwish9", "ahmed.balushi", "ahmed-balushi")
     *
     * @param string        $token
     * @param string        $companyId
     * @param Database|null $db
     * @return array|null
     */
    public static function resolveEmployeeToken(string $token, string $companyId, $db = null): ?array
    {
        $token = trim($token);
        if ($token === '' || $companyId === '') {
            return null;
        }
        if ($db === null && class_exists('Database')) {
            $db = Database::getInstance();
        }
        if (!$db || !method_exists($db, 'fetchOne')) {
            return null;
        }

        if (strpos($token, '@') !== false) {
            return $db->fetchOne(
                "SELECT * FROM employees WHERE company_id = :cid AND LOWER(email) = :email",
                ['cid' => $companyId, 'email' => strtolower($token)]
            ) ?: null;
        }

        $employee = $db->fetchOne(
            "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
            ['id' => $token, 'cid' => $companyId]
        ) ?: null;
        if ($employee) {
            return $employee;
        }

        try {
            $localLower = strtolower($token);
            $localDashed = str_replace(['.', '_'], '-', $localLower);
            return $db->fetchOne(
                "SELECT * FROM employees
                 WHERE company_id = :cid
                   AND status = 'active'
                   AND deleted_at IS NULL
                   AND (
                        LOWER(SUBSTRING_INDEX(email, '@', 1)) = LOWER(:exact)
                     OR REPLACE(REPLACE(LOWER(SUBSTRING_INDEX(email, '@', 1)), '.', '-'), '_', '-') = LOWER(:dashed)
                   )
                 LIMIT 1",
                ['cid' => $companyId, 'exact' => $localLower, 'dashed' => $localDashed]
            ) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pure mapping: employee + company row -> the canonical parsed-card
     * shape shared with the Scan feature (see ScanParser::emptyParsed()
     * and ScanVcf::build()). No DB, no I/O, so it is unit-testable
     * directly, see tests/php/resolve_card_test.php.
     *
     * Phone numbers are E.164-normalised via Phone::normalize() (default
     * +968); a number that cannot be coerced is passed through verbatim
     * rather than dropped, so a scan still gets a usable digit string.
     * Empty phone/email fields are skipped entirely, not emitted blank.
     *
     * @param array $employee
     * @param array $company
     * @return array
     */
    public static function employeeToScanCard(array $employee, array $company): array
    {
        require_once __DIR__ . '/Phone.php';

        $e164 = function ($raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return '';
            }
            $normalized = Phone::normalize($raw);
            return $normalized ?? $raw;
        };

        $phones = [];
        foreach ([['mobile', 'mobile'], ['phone', 'work'], ['fax', 'fax']] as $spec) {
            [$field, $type] = $spec;
            $raw = trim((string) ($employee[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            $phones[] = ['number' => $e164($raw), 'type' => $type];
        }

        $email = trim((string) ($employee['email'] ?? ''));
        $companyEn = trim((string) ($employee['company_en'] ?? ''));
        if ($companyEn === '') {
            $companyEn = (string) ($company['name'] ?? '');
        }

        return [
            'name_en'    => (string) ($employee['name_en'] ?? ''),
            'name_ar'    => (string) ($employee['name_ar'] ?? ''),
            'title_en'   => (string) ($employee['position_en'] ?? ''),
            'title_ar'   => (string) ($employee['position_ar'] ?? ''),
            'company_en' => $companyEn,
            'company_ar' => (string) ($employee['company_ar'] ?? ''),
            'phones'     => $phones,
            'emails'     => $email !== '' ? [$email] : [],
            'website'    => (string) ($employee['website'] ?? ''),
            'address_en' => (string) ($employee['address_en'] ?? ''),
            'address_ar' => (string) ($employee['address_ar'] ?? ''),
        ];
    }
}
