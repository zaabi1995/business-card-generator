<?php
/**
 * Onboarding state helper, thin wrapper around `company_onboarding` table.
 *
 * Records a company's progress through the 3-step setup wizard:
 *   1. logo
 *   2. colors
 *   3. template
 *   4. first_employee
 *   5. preview
 *   6. invite_team
 *   7. order_cards
 *
 * Each step's payload is stored under the matching key inside `data`.
 */
class Onboarding
{
    public const TOTAL_STEPS = 3;
    // STEP_KEYS must match what the frontend sends on each step
    // (admin/onboarding.php's stepPayload + the keys read into init.data).
    // Step 1 = the logo upload bundle ({url, filename, size, dominant_color}),
    // step 2 = card_design import metadata, step 3 = launch state.
    // Earlier this was 'brand' for step 1 which created a save/read mismatch:
    // payload was stored under data['brand'] but the read code at
    // admin/onboarding.php:62 reads data['logo'], so the wizard kept showing
    // an empty step 1 after refresh.
    public const STEP_KEYS = ['logo','card_design','launch'];

    public static function get(string $companyId): array
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM company_onboarding WHERE company_id = :cid",
            ['cid' => $companyId]
        );
        if (!$row) {
            return [
                'company_id'   => $companyId,
                'step'         => 0,
                'data'         => [],
                'started_at'   => null,
                'updated_at'   => null,
                'completed_at' => null,
                'skipped_at'   => null,
            ];
        }
        $row['data'] = !empty($row['data']) ? (json_decode($row['data'], true) ?: []) : [];
        return $row;
    }

    public static function isComplete(string $companyId): bool
    {
        $row = self::get($companyId);
        return !empty($row['completed_at']);
    }

    public static function shouldShowWizard(string $companyId): bool
    {
        $row = self::get($companyId);
        if (!empty($row['completed_at'])) return false;
        if (!empty($row['skipped_at'])) {
            // Re-show 24h after a skip
            if (strtotime($row['skipped_at']) > time() - 86400) return false;
        }
        return true;
    }

    /**
     * Validate a step payload. Returns empty array when OK, otherwise
     * an array of {field, code} pairs describing missing/invalid fields.
     * Lenient by design: intermediate "save progress" writes where the
     * admin has not yet filled everything should NOT block, only the
     * final saveStep on the last step triggers strict mode.
     */
    public static function validatePayload(int $step, array $payload, bool $strict = false): array
    {
        $errors = [];
        switch ($step) {
            case 1: // brand: logo + auto-extracted palette (replaces old steps 1+2+3)
                if ($strict && empty($payload['url'])) {
                    $errors[] = ['field' => 'logo', 'code' => 'missing_logo'];
                }
                break;
            case 2: // card_design (PDF), optional, skipping is fine
                break;
            case 3: // preview/launch, read-only
                break;
        }
        return $errors;
    }

    public static function saveStep(string $companyId, int $step, array $payload): void
    {
        $db = Database::getInstance();
        $stepKey = self::STEP_KEYS[$step - 1] ?? null;
        if (!$stepKey) throw new InvalidArgumentException('Invalid step: ' . $step);

        $existing = self::get($companyId);
        $data = $existing['data'];
        $data[$stepKey] = $payload;

        $maxStep = max((int) $existing['step'], $step);

        $completedAt = null;
        if ($step >= self::TOTAL_STEPS) {
            $completedAt = date('Y-m-d H:i:s');
        }

        if (empty($existing['started_at']) && $existing['step'] === 0) {
            // First write, insert
            $db->insert('company_onboarding', [
                'company_id'   => $companyId,
                'step'         => $maxStep,
                'data'         => json_encode($data, JSON_UNESCAPED_UNICODE),
                'completed_at' => $completedAt,
            ]);
        } else {
            $db->update(
                'company_onboarding',
                [
                    'step'         => $maxStep,
                    'data'         => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'completed_at' => $completedAt,
                ],
                'company_id = :cid',
                ['cid' => $companyId]
            );
        }

        // Sync the legacy `companies.onboarding_completed` flag (used by the
        // dashboard's amber Finish Setup banner) with our new completion
        // timestamp so the two onboarding state stores agree.
        if ($completedAt) {
            try {
                $db->update(
                    'companies',
                    ['onboarding_completed' => 1],
                    'id = :cid',
                    ['cid' => $companyId]
                );
            } catch (Throwable $e) {
                error_log('[Onboarding] failed to sync companies.onboarding_completed: ' . $e->getMessage());
            }
        }

        // Funnel telemetry: emit one audit-log row per step save plus a
        // separate `onboarding_completed` entry on the final step. Keeps
        // the funnel visible on /admin/audit-logs without another table.
        if (class_exists('AuditLog')) {
            AuditLog::log(
                'onboarding_step_saved',
                'onboarding',
                $companyId,
                null,
                ['step' => $step, 'step_key' => $stepKey],
                $companyId
            );
            if ($completedAt) {
                AuditLog::log(
                    'onboarding_completed',
                    'onboarding',
                    $companyId,
                    null,
                    ['total_steps' => self::TOTAL_STEPS],
                    $companyId
                );
            }
        }

        // Dispatch welcome email + WA when the wizard transitions to
        // completed. Silent-fail so notifier hiccups never break save.
        if ($completedAt) {
            try {
                self::dispatchWelcome($companyId, $data);
            } catch (Throwable $e) {
                error_log('[onboarding] welcome dispatch failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Fire wizard_completed notifications to admin + any employee seeded
     * in step 4. Idempotent at the Notifier layer (every call logs to
     * notification_log so duplicate-supression can be added later).
     */
    public static function dispatchWelcome(string $companyId, array $data): void
    {
        if (!class_exists('Notifier')) {
            require_once __DIR__ . '/Notifier.php';
        }
        $db = Database::getInstance();
        $company = $db->fetchOne(
            "SELECT id, name, name_en, name_ar, slug, admin_email, phone FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        if (!$company) return;

        $companyName = $company['name'] ?? ($company['name_en'] ?? 'your company');
        $slug        = $company['slug'] ?? '';
        $firstEmp    = $data['first_employee'] ?? [];
        $host        = defined('APP_HOST') ? APP_HOST : 'cardify.om';
        // Tenant subdomain is the canonical URL; bare host is a fallback
        // only for unnamed tenants (should be impossible in practice).
        $tenantBase  = $slug ? 'https://' . $slug . '.' . $host : 'https://' . $host;
        $dashboardUrl = $tenantBase . '/admin/';

        // Resolve the first employee's REAL card URL from the persisted row,
        // never from the name slug (a name slug matches neither the id nor
        // the email localpart and 404s to the request portal).
        if (!class_exists('CardifyConvention')) {
            require_once __DIR__ . '/CardifyConvention.php';
        }
        $emp = null;
        $empEmail = strtolower(trim((string) ($firstEmp['email'] ?? '')));
        if ($empEmail !== '') {
            $emp = $db->fetchOne(
                "SELECT id, email FROM employees WHERE company_id = :cid AND LOWER(email) = :em AND deleted_at IS NULL LIMIT 1",
                ['cid' => $companyId, 'em' => $empEmail]
            );
        }
        if (!$emp) {
            $emp = $db->fetchOne(
                "SELECT id, email FROM employees WHERE company_id = :cid AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 1",
                ['cid' => $companyId]
            );
        }
        if ($emp && $slug) {
            $cardUrl = CardifyConvention::employeeShareUrl($slug, $emp);
        } else {
            $cardUrl = $slug ? $dashboardUrl : ('https://' . $host . '/');
        }

        $adminName  = $data['admin_name'] ?? ($firstEmp['name'] ?? $companyName);
        $adminEmail = $company['admin_email'] ?? ($firstEmp['email'] ?? '');
        $adminPhone = $company['phone'] ?? ($data['admin_phone'] ?? ($firstEmp['phone'] ?? ''));

        Notifier::send('wizard_completed',
            ['name' => $adminName, 'email' => $adminEmail, 'phone' => $adminPhone, 'company_id' => $companyId],
            ['name' => $adminName, 'companyName' => $companyName, 'cardUrl' => $cardUrl, 'dashboardUrl' => $dashboardUrl]
        );
    }

    /**
     * Merge arbitrary key/value pairs into company_onboarding.data without
     * advancing the step counter. Used by register.php to seed admin's
     * own name/email/phone into the wizard state so step 4 (first_employee)
     * pre-fills on first visit.
     */
    public static function saveMeta(string $companyId, array $meta): void
    {
        if (!$meta) return;
        $db = Database::getInstance();
        $existing = self::get($companyId);
        $data = $existing['data'];
        foreach ($meta as $k => $v) { $data[$k] = $v; }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (empty($existing['started_at']) && $existing['step'] === 0) {
            $db->insert('company_onboarding', [
                'company_id' => $companyId,
                'step'       => 0,
                'data'       => $payload,
            ]);
        } else {
            $db->update(
                'company_onboarding',
                ['data' => $payload],
                'company_id = :cid',
                ['cid' => $companyId]
            );
        }
    }

    public static function markSkipped(string $companyId): void
    {
        $db = Database::getInstance();
        $existing = self::get($companyId);
        if ($existing['step'] === 0 && empty($existing['started_at'])) {
            $db->insert('company_onboarding', [
                'company_id' => $companyId,
                'step'       => 0,
                'skipped_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $db->update(
                'company_onboarding',
                ['skipped_at' => date('Y-m-d H:i:s')],
                'company_id = :cid',
                ['cid' => $companyId]
            );
        }
        if (class_exists('AuditLog')) {
            AuditLog::log(
                'onboarding_skipped',
                'onboarding',
                $companyId,
                null,
                ['last_step' => (int) $existing['step']],
                $companyId
            );
        }
    }

    public static function markCompleted(string $companyId): void
    {
        $db = Database::getInstance();
        $existing = self::get($companyId);
        $wasAlreadyCompleted = !empty($existing['completed_at']);
        $row = [
            'step'         => self::TOTAL_STEPS,
            'completed_at' => date('Y-m-d H:i:s'),
        ];
        if (empty($existing['started_at']) && $existing['step'] === 0) {
            $db->insert('company_onboarding', array_merge(['company_id' => $companyId], $row));
        } else {
            $db->update('company_onboarding', $row, 'company_id = :cid', ['cid' => $companyId]);
        }
        if (!$wasAlreadyCompleted) {
            try {
                self::dispatchWelcome($companyId, $existing['data'] ?? []);
            } catch (Throwable $e) {
                error_log('[onboarding] welcome dispatch (markCompleted) failed: ' . $e->getMessage());
            }
        }
    }
}
