<?php
/**
 * CustomDomain, helper for Pro-tier custom domains feature.
 *
 * Owners point a domain at cardify.om via CNAME; this class CRUDs the
 * employee_custom_domains table and verifies DNS.
 */

class CustomDomain
{
    /** Hosts that are NEVER treated as custom domains. */
    public static function primaryHosts(): array
    {
        return [
            'cardify.om',
            'www.cardify.om',
            'localhost',
            '127.0.0.1',
            'cardify.test',
            'cardify.local',
        ];
    }

    /** Lowercase host with port stripped. */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (($p = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $p);
        }
        // strip trailing dot from FQDN
        return rtrim($host, '.');
    }

    /** Basic FQDN sanity. Not a full validator, RFC 1035-ish. */
    public static function isValidDomain(string $domain): bool
    {
        $domain = self::normalizeHost($domain);
        if ($domain === '' || strlen($domain) > 253) return false;
        if (strpos($domain, '.') === false) return false;
        if (in_array($domain, self::primaryHosts(), true)) return false;
        // labels: letters, digits, hyphens; not starting/ending with hyphen
        return (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Find row by Host header. Returns null if not found / not verified. */
    public static function findVerifiedByHost(string $host): ?array
    {
        $host = self::normalizeHost($host);
        if (in_array($host, self::primaryHosts(), true)) return null;

        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM employee_custom_domains WHERE domain = :d AND verified = 1 LIMIT 1",
            ['d' => $host]
        );
        return $row ?: null;
    }

    public static function findById(string $id, ?string $companyId = null): ?array
    {
        $db = Database::getInstance();
        if ($companyId) {
            $row = $db->fetchOne(
                "SELECT * FROM employee_custom_domains WHERE id = :id AND company_id = :c",
                ['id' => $id, 'c' => $companyId]
            );
        } else {
            $row = $db->fetchOne(
                "SELECT * FROM employee_custom_domains WHERE id = :id",
                ['id' => $id]
            );
        }
        return $row ?: null;
    }

    public static function listForCompany(string $companyId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT cd.*, e.name_en AS employee_name, e.email AS employee_email
             FROM employee_custom_domains cd
             LEFT JOIN employees e ON e.id = cd.employee_id
             WHERE cd.company_id = :c
             ORDER BY cd.created_at DESC",
            ['c' => $companyId]
        );
    }

    public static function listForEmployee(string $employeeId, string $companyId): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT * FROM employee_custom_domains
             WHERE employee_id = :e AND company_id = :c
             ORDER BY created_at DESC",
            ['e' => $employeeId, 'c' => $companyId]
        );
    }

    /** Add a domain. Returns ['success'=>bool, 'error'?=>string, 'id'?=>string]. */
    public static function add(string $employeeId, string $companyId, string $domain): array
    {
        $domain = self::normalizeHost($domain);
        if (!self::isValidDomain($domain)) {
            return ['success' => false, 'error' => 'Invalid domain'];
        }

        $db = Database::getInstance();

        // Confirm employee belongs to company
        $emp = $db->fetchOne(
            "SELECT id FROM employees WHERE id = :id AND company_id = :c",
            ['id' => $employeeId, 'c' => $companyId]
        );
        if (!$emp) {
            return ['success' => false, 'error' => 'Employee not found'];
        }

        // Domain must be globally unique
        $existing = $db->fetchOne(
            "SELECT id FROM employee_custom_domains WHERE domain = :d",
            ['d' => $domain]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'Domain already registered'];
        }

        $id = self::uuid();
        $token = self::generateToken();

        try {
            $db->query(
                "INSERT INTO employee_custom_domains
                 (id, employee_id, company_id, domain, verified, ssl_status, verification_token, created_at)
                 VALUES (:id, :e, :c, :d, 0, 'pending', :t, NOW())",
                ['id' => $id, 'e' => $employeeId, 'c' => $companyId, 'd' => $domain, 't' => $token]
            );
        } catch (Exception $ex) {
            return ['success' => false, 'error' => 'DB error: ' . $ex->getMessage()];
        }

        return ['success' => true, 'id' => $id, 'verification_token' => $token];
    }

    /** Run DNS lookup and update verified flag. Returns ['verified'=>bool, 'message'=>string]. */
    public static function verify(string $id, string $companyId): array
    {
        $row = self::findById($id, $companyId);
        if (!$row) return ['verified' => false, 'message' => 'Not found'];

        $domain = $row['domain'];
        $message = '';
        $ok = false;

        // Try CNAME first
        $cnames = @dns_get_record($domain, DNS_CNAME);
        if (is_array($cnames)) {
            foreach ($cnames as $r) {
                $target = strtolower(rtrim($r['target'] ?? '', '.'));
                if ($target === 'cardify.om' || $target === 'www.cardify.om') {
                    $ok = true;
                    $message = 'CNAME verified -> ' . $target;
                    break;
                }
            }
        }

        // Fallback: A record matches cardify.om's IP
        if (!$ok) {
            $expectedIp = @gethostbyname('cardify.om');
            $aRecs = @dns_get_record($domain, DNS_A);
            if (is_array($aRecs) && $expectedIp && $expectedIp !== 'cardify.om') {
                foreach ($aRecs as $r) {
                    if (($r['ip'] ?? '') === $expectedIp) {
                        $ok = true;
                        $message = 'A record verified -> ' . $expectedIp;
                        break;
                    }
                }
            }
            if (!$ok && empty($message)) {
                $message = 'No CNAME or A record pointing to cardify.om found';
            }
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE employee_custom_domains
             SET verified = :v, last_checked_at = NOW(), last_check_message = :m
             WHERE id = :id",
            ['v' => $ok ? 1 : 0, 'm' => substr($message, 0, 255), 'id' => $id]
        );

        return ['verified' => $ok, 'message' => $message];
    }

    public static function setSslStatus(string $id, string $companyId, string $status): bool
    {
        if (!in_array($status, ['pending', 'active', 'failed'], true)) return false;
        $row = self::findById($id, $companyId);
        if (!$row) return false;

        $db = Database::getInstance();
        $db->query(
            "UPDATE employee_custom_domains SET ssl_status = :s WHERE id = :id",
            ['s' => $status, 'id' => $id]
        );
        return true;
    }

    public static function delete(string $id, string $companyId): bool
    {
        $row = self::findById($id, $companyId);
        if (!$row) return false;

        $db = Database::getInstance();
        $db->query(
            "DELETE FROM employee_custom_domains WHERE id = :id AND company_id = :c",
            ['id' => $id, 'c' => $companyId]
        );
        return true;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
