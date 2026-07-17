<?php
/**
 * ScanPassService: the updatable-Wallet-pass lifecycle for Cardify scan cards.
 *
 * Model (two tables, created on demand):
 *   scan_passes            one row per (employee) pass: stable serial, a per-pass
 *                          auth token (protocol requires re-embedding it on
 *                          every rebuild, so it is stored, never logged), a monotonic version, last_modified, and a
 *                          revoked flag. The plaintext token is returned ONCE at
 *                          creation to embed in pass.json; only its hash is kept.
 *   scan_pass_registrations one row per (serial, device): the APNs push token +
 *                          environment, so a card change can notify every device.
 *
 * Security: the client authenticates every web-service call with
 * `Authorization: ApplePass <token>`; we compare it to the stored
 * per-pass token with hash_equals (constant time). Tokens are never logged. Serial -> employee ownership is decided
 * server-side; the client never supplies it. Tokens are never logged.
 */
class ScanPassService
{
    public static function ensureSchema(): void
    {
        $db = Database::getInstance();
        $db->getConnection()->exec(
            "CREATE TABLE IF NOT EXISTS scan_passes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(64) NOT NULL,
                company_id VARCHAR(64) NOT NULL,
                serial_number VARCHAR(64) NOT NULL UNIQUE,
                auth_token VARCHAR(64) NOT NULL,
                version INT NOT NULL DEFAULT 1,
                last_modified DATETIME NOT NULL,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_emp (employee_id),
                KEY idx_serial (serial_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->getConnection()->exec(
            "CREATE TABLE IF NOT EXISTS scan_pass_registrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                serial_number VARCHAR(64) NOT NULL,
                device_library_id VARCHAR(128) NOT NULL,
                push_token VARCHAR(200) NOT NULL,
                environment VARCHAR(16) NOT NULL DEFAULT 'production',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_reg (serial_number, device_library_id),
                KEY idx_reg_serial (serial_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** Get the pass row for an employee, creating it (serial + token) if absent.
     *  Returns ['serial' => , 'token' => plaintext|null, 'version' => , 'row' => ].
     *  The plaintext token is only non-null when the pass was just created. */
    public static function getOrCreateForEmployee(string $employeeId, string $companyId): array
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM scan_passes WHERE employee_id = :e", ['e' => $employeeId]);
        if ($row) {
            return ['serial' => $row['serial_number'], 'token' => $row['auth_token'], 'version' => (int)$row['version'], 'row' => $row];
        }
        $serial = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(24));
        $now = date('Y-m-d H:i:s');
        $db->getConnection()->prepare(
            "INSERT INTO scan_passes (employee_id, company_id, serial_number, auth_token, version, last_modified, revoked, created_at)
             VALUES (:e, :c, :s, :h, 1, :m, 0, :m2)"
        )->execute(['e' => $employeeId, 'c' => $companyId, 's' => $serial, 'h' => $token, 'm' => $now, 'm2' => $now]);
        $row = $db->fetchOne("SELECT * FROM scan_passes WHERE serial_number = :s", ['s' => $serial]);
        return ['serial' => $serial, 'token' => $token, 'version' => 1, 'row' => $row];
    }

    public static function findBySerial(string $serial): ?array
    {
        self::ensureSchema();
        return Database::getInstance()->fetchOne("SELECT * FROM scan_passes WHERE serial_number = :s", ['s' => $serial]) ?: null;
    }

    /** Constant-time check of an ApplePass token against a serial. */
    public static function authorize(string $serial, string $token): bool
    {
        $row = self::findBySerial($serial);
        if (!$row) return false;
        return hash_equals((string)$row['auth_token'], $token);
    }

    public static function register(string $serial, string $deviceLibraryId, string $pushToken, string $environment): string
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT id FROM scan_pass_registrations WHERE serial_number = :s AND device_library_id = :d",
            ['s' => $serial, 'd' => $deviceLibraryId]
        );
        if ($existing) {
            // Idempotent: refresh the push token, report 'existing' (HTTP 200).
            $db->getConnection()->prepare(
                "UPDATE scan_pass_registrations SET push_token = :p, environment = :env WHERE id = :id"
            )->execute(['p' => $pushToken, 'env' => $environment, 'id' => $existing['id']]);
            return 'existing';
        }
        $db->getConnection()->prepare(
            "INSERT INTO scan_pass_registrations (serial_number, device_library_id, push_token, environment, created_at)
             VALUES (:s, :d, :p, :env, :c)"
        )->execute(['s' => $serial, 'd' => $deviceLibraryId, 'p' => $pushToken, 'env' => $environment, 'c' => date('Y-m-d H:i:s')]);
        return 'created';
    }

    public static function unregister(string $serial, string $deviceLibraryId): bool
    {
        self::ensureSchema();
        $stmt = Database::getInstance()->getConnection()->prepare(
            "DELETE FROM scan_pass_registrations WHERE serial_number = :s AND device_library_id = :d"
        );
        $stmt->execute(['s' => $serial, 'd' => $deviceLibraryId]);
        return true;
    }

    /** Serials for a device changed at/after $sinceTag (a version-stamped tag).
     *  Returns ['serialNumbers' => [...], 'lastUpdated' => tag] (empty => 204). */
    public static function serialsForDevice(string $deviceLibraryId, ?string $sinceTag): array
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $regs = $db->fetchAll(
            "SELECT p.serial_number, p.last_modified, p.version
             FROM scan_pass_registrations r JOIN scan_passes p ON p.serial_number = r.serial_number
             WHERE r.device_library_id = :d AND p.revoked = 0",
            ['d' => $deviceLibraryId]
        );
        $since = $sinceTag !== null && $sinceTag !== '' ? strtotime($sinceTag) : 0;
        $serials = [];
        $maxTs = $since;
        foreach ($regs as $r) {
            $ts = strtotime($r['last_modified']);
            if ($ts > $since) {
                $serials[] = $r['serial_number'];
                if ($ts > $maxTs) $maxTs = $ts;
            }
        }
        return ['serialNumbers' => $serials, 'lastUpdated' => (string) ($maxTs ?: time())];
    }

    /** Card changed: bump version + last_modified, return the devices to notify.
     *  The push itself is sent by the caller via an ApnsProvider (empty payload;
     *  Wallet then pulls the new pass). Returns the registration push tokens. */
    public static function onCardChanged(string $employeeId): array
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $pass = $db->fetchOne("SELECT * FROM scan_passes WHERE employee_id = :e", ['e' => $employeeId]);
        if (!$pass) return []; // no updatable pass exists yet (card never added to Wallet)
        $now = date('Y-m-d H:i:s');
        $db->getConnection()->prepare(
            "UPDATE scan_passes SET version = version + 1, last_modified = :m WHERE id = :id"
        )->execute(['m' => $now, 'id' => $pass['id']]);
        return $db->fetchAll(
            "SELECT push_token, environment, device_library_id FROM scan_pass_registrations WHERE serial_number = :s",
            ['s' => $pass['serial_number']]
        );
    }

    public static function revoke(string $employeeId): void
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $db->getConnection()->prepare("UPDATE scan_passes SET revoked = 1, last_modified = :m WHERE employee_id = :e")
            ->execute(['m' => date('Y-m-d H:i:s'), 'e' => $employeeId]);
    }

    /** Remove a dead registration (called when APNs reports the token invalid). */
    public static function removeRegistration(string $serial, string $deviceLibraryId): void
    {
        self::unregister($serial, $deviceLibraryId);
    }

    /** Account/card deletion cleanup: drop the pass + all its registrations. */
    public static function deleteForEmployee(string $employeeId): void
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $pass = $db->fetchOne("SELECT serial_number FROM scan_passes WHERE employee_id = :e", ['e' => $employeeId]);
        if ($pass) {
            $db->getConnection()->prepare("DELETE FROM scan_pass_registrations WHERE serial_number = :s")
                ->execute(['s' => $pass['serial_number']]);
        }
        $db->getConnection()->prepare("DELETE FROM scan_passes WHERE employee_id = :e")->execute(['e' => $employeeId]);
    }
}
