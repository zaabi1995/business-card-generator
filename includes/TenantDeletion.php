<?php
/**
 * TenantDeletion, safe company deletion with a grace period.
 *
 * Best-practice "delete a tenant" flow: instead of an immediate hard DELETE
 * (which cascades away employees, cards, orders and is irreversible), we
 * SCHEDULE the deletion. The company is deactivated now (so it goes offline
 * immediately) and a `tenant_deletions` row records when it may be permanently
 * purged. A super admin can cancel any time before `purge_after`.
 *
 * Tables (migration 085): tenant_deletions(company_id PK, requested_by, reason,
 * requested_at, purge_after, cancelled_at, purged_at).
 *
 * NOTE: the actual hard purge (removing rows once purge_after passes) is a
 * separate reaper cron, not yet implemented. Scheduling + deactivation +
 * cancel are handled here.
 */
class TenantDeletion
{
    const DEFAULT_GRACE_DAYS = 30;

    private static function db()
    {
        return Database::getInstance();
    }

    /**
     * Schedule a company for deletion and deactivate it immediately.
     *
     * @return array{success:bool,error?:string,purge_after?:string}
     */
    public static function requestDelete(string $companyId, ?string $requestedBy = null, string $reason = '', int $graceDays = self::DEFAULT_GRACE_DAYS): array
    {
        $db = self::db();
        if (!$db || !$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }

        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        if (!$company) {
            return ['success' => false, 'error' => 'Company not found'];
        }

        $graceDays = max(1, $graceDays);
        $now = date('Y-m-d H:i:s');
        $purgeAfter = date('Y-m-d H:i:s', time() + ($graceDays * 86400));

        try {
            $existing = $db->fetchOne(
                "SELECT company_id FROM tenant_deletions WHERE company_id = :id",
                ['id' => $companyId]
            );

            if ($existing) {
                // Re-arm a previously scheduled/cancelled deletion.
                $db->update('tenant_deletions', [
                    'requested_by' => $requestedBy,
                    'reason'       => $reason,
                    'requested_at' => $now,
                    'purge_after'  => $purgeAfter,
                    'cancelled_at' => null,
                    'purged_at'    => null,
                ], 'company_id = :id', ['id' => $companyId]);
            } else {
                $db->insert('tenant_deletions', [
                    'company_id'   => $companyId,
                    'requested_by' => $requestedBy,
                    'reason'       => $reason,
                    'requested_at' => $now,
                    'purge_after'  => $purgeAfter,
                ]);
            }

            // Deactivate now so the tenant is offline during the grace window.
            $db->update('companies', ['status' => 'suspended', 'updated_at' => $now], 'id = :id', ['id' => $companyId]);

            if (class_exists('AuditLog')) {
                AuditLog::logCompany('delete_scheduled', $companyId, $company, [
                    'purge_after' => $purgeAfter,
                    'reason'      => $reason,
                    'grace_days'  => $graceDays,
                ]);
            }

            return ['success' => true, 'purge_after' => $purgeAfter];
        } catch (Exception $e) {
            error_log('[TenantDeletion::requestDelete] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to schedule deletion: ' . $e->getMessage()];
        }
    }

    /**
     * Cancel a scheduled deletion and reactivate the company.
     *
     * @return array{success:bool,error?:string}
     */
    public static function cancel(string $companyId): array
    {
        $db = self::db();
        if (!$db || !$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }

        $row = self::pending($companyId);
        if (!$row) {
            return ['success' => false, 'error' => 'No scheduled deletion to cancel'];
        }

        try {
            $db->update('tenant_deletions', ['cancelled_at' => dbNow()], 'company_id = :id', ['id' => $companyId]);
            $db->update('companies', ['status' => 'active', 'updated_at' => dbNow()], 'id = :id', ['id' => $companyId]);

            if (class_exists('AuditLog')) {
                AuditLog::logCompany('delete_cancelled', $companyId, $row, null);
            }
            return ['success' => true];
        } catch (Exception $e) {
            error_log('[TenantDeletion::cancel] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to cancel deletion: ' . $e->getMessage()];
        }
    }

    /**
     * Return the active (not cancelled, not yet purged) deletion row, or null.
     */
    public static function pending(string $companyId): ?array
    {
        $db = self::db();
        if (!$db || !$db->isConnected()) {
            return null;
        }
        try {
            $row = $db->fetchOne(
                "SELECT * FROM tenant_deletions
                 WHERE company_id = :id AND cancelled_at IS NULL AND purged_at IS NULL",
                ['id' => $companyId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}
