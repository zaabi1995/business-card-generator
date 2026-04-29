<?php
/**
 * Seeds 5 placeholder employees so a fresh tenant has data to play with
 * the first time they land on the dashboard, pre-wizard. The seeded
 * employee IDs are stored under `company_onboarding.data.demo_employee_ids`
 * so clearing is idempotent and only touches rows we created.
 *
 * Call site: admin/index.php (dashboard) on first visit of a tenant
 * with zero employees and no completed onboarding. Clear endpoint:
 * admin/demo-clear.php (POST, CSRF).
 */
class DemoData
{
    public const MAX_SEED = 5;

    /**
     * Return the demo-employee id list saved on company_onboarding, or
     * empty array if none has been seeded yet.
     */
    public static function seededIds(string $companyId): array
    {
        $state = Onboarding::get($companyId);
        $ids = $state['data']['demo_employee_ids'] ?? [];
        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /**
     * True iff the company has zero employees, has not started or
     * skipped onboarding, and has not been seeded already.
     */
    public static function shouldSeed(string $companyId): bool
    {
        $db = Database::getInstance();
        $empCount = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM employees WHERE company_id = :cid",
            ['cid' => $companyId]
        )['c'] ?? 0);
        if ($empCount > 0) return false;

        if (self::seededIds($companyId)) return false;

        $state = Onboarding::get($companyId);
        if (!empty($state['completed_at'])) return false;
        return true;
    }

    /**
     * Insert 5 placeholder employees. Idempotent: if seeded ids are
     * already stored, no-ops. Returns the inserted id list.
     */
    public static function seed(string $companyId): array
    {
        if (self::seededIds($companyId)) return self::seededIds($companyId);

        $db = Database::getInstance();
        $rows = [
            ['name' => 'Sarah Al-Balushi',    'title' => 'Marketing Manager',  'email' => 'demo-sarah@example.com',   'phone' => '+968 9000 0001'],
            ['name' => 'Ahmed Al-Siyabi',     'title' => 'Senior Engineer',    'email' => 'demo-ahmed@example.com',   'phone' => '+968 9000 0002'],
            ['name' => 'Fatma Al-Harthi',     'title' => 'Account Executive',  'email' => 'demo-fatma@example.com',   'phone' => '+968 9000 0003'],
            ['name' => 'Khalid Al-Rashidi',   'title' => 'Operations Lead',    'email' => 'demo-khalid@example.com',  'phone' => '+968 9000 0004'],
            ['name' => 'Noor Al-Zadjali',     'title' => 'Product Designer',   'email' => 'demo-noor@example.com',    'phone' => '+968 9000 0005'],
        ];

        $ids = [];
        foreach ($rows as $r) {
            $eid = generateUUID();
            try {
                $db->insert('employees', [
                    'id'          => $eid,
                    'company_id'  => $companyId,
                    'email'       => $r['email'],
                    'name_en'     => $r['name'],
                    'position_en' => $r['title'],
                    'phone'       => $r['phone'],
                    'mobile'      => $r['phone'],
                    'status'      => 'active',
                ]);
                $ids[] = $eid;
            } catch (Throwable $e) {
                error_log('[demo-data] seed insert failed: ' . $e->getMessage());
            }
        }

        // Stash on onboarding state so Clear knows what to remove.
        $state = Onboarding::get($companyId);
        $data = $state['data'];
        $data['demo_employee_ids'] = $ids;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!empty($state['started_at']) || (int)$state['step'] > 0) {
            $db->update('company_onboarding', ['data' => $payload], 'company_id = :cid', ['cid' => $companyId]);
        } else {
            $db->insert('company_onboarding', [
                'company_id' => $companyId,
                'step'       => 0,
                'data'       => $payload,
            ]);
        }

        return $ids;
    }

    /**
     * Remove seeded demo employees + clear company sample-card flags.
     * Returns the number of employee rows deleted.
     */
    public static function clear(string $companyId): int
    {
        $ids = self::seededIds($companyId);
        if (!$ids) return 0;

        $db = Database::getInstance();
        $deleted = 0;
        foreach ($ids as $eid) {
            try {
                $db->query(
                    "DELETE FROM employees WHERE id = :id AND company_id = :cid",
                    ['id' => $eid, 'cid' => $companyId]
                );
                $deleted++;
            } catch (Throwable $e) {
                error_log('[demo-data] delete failed for ' . $eid . ': ' . $e->getMessage());
            }
        }

        // Clear sample card flags so the demo-based sample does not
        // linger on the public profile after the real data lands.
        try {
            $db->query(
                "UPDATE companies SET sample_card_front = NULL, sample_card_back = NULL WHERE id = :cid",
                ['cid' => $companyId]
            );
        } catch (Throwable $e) { /* non-fatal */ }

        // Wipe demo_employee_ids from onboarding state.
        $state = Onboarding::get($companyId);
        $data = $state['data'];
        unset($data['demo_employee_ids']);
        try {
            $db->update(
                'company_onboarding',
                ['data' => json_encode($data, JSON_UNESCAPED_UNICODE)],
                'company_id = :cid',
                ['cid' => $companyId]
            );
        } catch (Throwable $e) { /* non-fatal */ }

        return $deleted;
    }
}
