<?php
/**
 * Onboarding state helper, thin wrapper around `company_onboarding` table.
 *
 * Records a company's progress through the 7-step setup wizard:
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
    public const TOTAL_STEPS = 7;
    public const STEP_KEYS = ['logo','colors','template','first_employee','preview','invite_team','order_cards'];

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
     * admin has not yet filled everything should NOT block — only the
     * final saveStep on the last step triggers strict mode.
     */
    public static function validatePayload(int $step, array $payload, bool $strict = false): array
    {
        $errors = [];
        switch ($step) {
            case 1: // logo
                if ($strict && empty($payload['url'])) {
                    $errors[] = ['field' => 'logo', 'code' => 'missing_logo'];
                }
                break;
            case 2: // colors
                $primary = $payload['primary'] ?? '';
                $accent  = $payload['accent']  ?? '';
                if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $primary)) {
                    $errors[] = ['field' => 'colors.primary', 'code' => 'invalid_hex'];
                }
                if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $accent)) {
                    $errors[] = ['field' => 'colors.accent', 'code' => 'invalid_hex'];
                }
                break;
            case 3: // template
                $tpl = $payload['template'] ?? $payload ?? null;
                if (is_string($payload)) $tpl = $payload;
                if (is_array($tpl)) $tpl = $tpl['template'] ?? null;
                $allowed = ['minimal', 'bold', 'classic'];
                if (!in_array($tpl, $allowed, true)) {
                    if ($strict) $errors[] = ['field' => 'template', 'code' => 'invalid_template'];
                }
                break;
            case 4: // first_employee
                $name  = trim((string) ($payload['name'] ?? ''));
                $email = trim((string) ($payload['email'] ?? ''));
                if ($name === '') $errors[] = ['field' => 'first_employee.name', 'code' => 'missing_name'];
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ['field' => 'first_employee.email', 'code' => 'invalid_email'];
                }
                break;
            case 5: // preview — read-only, no validation
                break;
            case 6: // invite_team — everything optional
                if (!empty($payload['csv']['parsed']['errors']) && $strict) {
                    // Non-fatal: expose parse errors but do not block save.
                }
                break;
            case 7: // order_cards
                $qty = (int) ($payload['per_person'] ?? 0);
                if ($qty > 0 && $qty < 50) {
                    $errors[] = ['field' => 'order_cards.per_person', 'code' => 'qty_below_min'];
                }
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
        $row = [
            'step'         => self::TOTAL_STEPS,
            'completed_at' => date('Y-m-d H:i:s'),
        ];
        if (empty($existing['started_at']) && $existing['step'] === 0) {
            $db->insert('company_onboarding', array_merge(['company_id' => $companyId], $row));
        } else {
            $db->update('company_onboarding', $row, 'company_id = :cid', ['cid' => $companyId]);
        }
    }
}
