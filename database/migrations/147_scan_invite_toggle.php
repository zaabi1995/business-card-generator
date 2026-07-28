<?php
/**
 * Per-company switch for the one-time WhatsApp claim invite.
 *
 * api/scan/invite.php sends an unsolicited WhatsApp to a person who was
 * SCANNED, from the company's own line. It is one message per shadow profile
 * ever and cannot be recalled, so whether it is allowed at all is a policy
 * decision for the company, not a product default.
 *
 * DEFAULT 0. The endpoint stays fully built and fully off until an admin turns
 * it on deliberately. A company that never touches the setting can never send.
 */
function migration_147_scan_invite_toggle(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $exists = $pdo->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'company_settings'
               AND column_name = 'scan_invite_enabled'"
        )->fetchColumn();

        if ($exists) {
            $result['messages'][] = 'scan_invite_enabled already present';
        } else {
            $pdo->exec(
                "ALTER TABLE company_settings
                 ADD COLUMN scan_invite_enabled TINYINT(1) NOT NULL DEFAULT 0
                 AFTER notify_on_employee_edit"
            );
            $result['messages'][] = 'added company_settings.scan_invite_enabled DEFAULT 0';
        }
        $result['success'] = true;
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
