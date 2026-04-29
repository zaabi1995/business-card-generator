<?php
/**
 * Impersonation helper, "Login as" for super admins.
 *
 * Session shape when impersonating:
 *   $_SESSION['impersonator'] = [
 *     'admin_id', 'admin_email', 'admin_name',
 *     'started_at', 'audit_id',
 *     'target_company_id', 'target_company_name',
 *   ]
 * The rest of the session is overwritten to look like the target user.
 * Exiting restores the admin session from the stash.
 */
class Impersonation
{
    /** Is the current session impersonating another account? */
    public static function isActive(): bool
    {
        return !empty($_SESSION['impersonator']) && is_array($_SESSION['impersonator']);
    }

    /** Current stash (or null). */
    public static function getStash(): ?array
    {
        return self::isActive() ? $_SESSION['impersonator'] : null;
    }

    /**
     * Start impersonating a company admin.
     * Caller must have already enforced super_admin + CSRF.
     *
     * @return array{success:bool,error?:string,redirect?:string}
     */
    public static function start(string $companyId): array
    {
        if (!class_exists('Auth')) {
            return ['success' => false, 'error' => 'Auth unavailable'];
        }

        // Block nested impersonation.
        if (self::isActive()) {
            return ['success' => false, 'error' => 'Already impersonating, exit current session first'];
        }

        $db = Database::getInstance();
        if (!$db || !$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }

        $admin = Auth::getCurrentUser();
        if (!$admin || ($admin['role'] ?? null) !== 'super_admin') {
            return ['success' => false, 'error' => 'Only super admins can impersonate'];
        }

        $company = $db->fetchOne(
            "SELECT * FROM companies WHERE id = :id AND status = 'active'",
            ['id' => $companyId]
        );
        if (!$company) {
            return ['success' => false, 'error' => 'Company not found or inactive'];
        }

        $auditId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
        $startedAt = date('c');

        // Stash admin identity so we can restore it.
        $stash = [
            'admin_id' => $admin['id'] ?? null,
            'admin_email' => $admin['email'] ?? null,
            'admin_name' => $admin['name'] ?? null,
            'started_at' => $startedAt,
            'audit_id' => $auditId,
            'target_company_id' => $company['id'],
            'target_company_name' => $company['name'],
        ];

        // Audit BEFORE we overwrite the session so actor_id is the admin.
        if (class_exists('AuditLog')) {
            AuditLog::log(
                'impersonate_start',
                'company',
                $company['id'],
                null,
                [
                    'admin_id' => $stash['admin_id'],
                    'admin_email' => $stash['admin_email'],
                    'target_company_id' => $company['id'],
                    'target_company_name' => $company['name'],
                    'target_admin_email' => $company['admin_email'] ?? null,
                    'audit_id' => $auditId,
                    'started_at' => $startedAt,
                ],
                $company['id']
            );
        }

        // Overwrite session to look like the company admin.
        // Mirrors Auth::loginCompany() so the rest of the app "just works".
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = 'company_' . $company['id'];
        $_SESSION['user_company_id'] = $company['id'];
        $_SESSION['company_id'] = $company['id'];
        $_SESSION['company_slug'] = $company['slug'];
        $_SESSION['company_name'] = $company['name'];
        $_SESSION['user_role'] = 'company_admin';
        $_SESSION['user_email'] = $company['admin_email'] ?? '';
        $_SESSION['user_name'] = $company['name'] ?? 'Admin';
        $_SESSION['impersonator'] = $stash;

        $basePath = function_exists('getBasePath') ? getBasePath() : '/';
        $redirect = $basePath . $company['slug'] . '/admin/';

        return ['success' => true, 'redirect' => $redirect];
    }

    /**
     * Stop impersonating and restore the admin session.
     *
     * @return array{success:bool,error?:string,redirect?:string}
     */
    public static function stop(): array
    {
        if (!self::isActive()) {
            $basePath = function_exists('getBasePath') ? getBasePath() : '/';
            return ['success' => true, 'redirect' => $basePath . 'admin/super/companies.php'];
        }

        $stash = $_SESSION['impersonator'];
        $startedAt = $stash['started_at'] ?? null;
        $stoppedAt = date('c');
        $durationSeconds = null;
        if ($startedAt) {
            $durationSeconds = max(0, strtotime($stoppedAt) - strtotime($startedAt));
        }

        // Log stop (still in target session, logger will record target as actor,
        // so pass admin identity in the payload).
        if (class_exists('AuditLog')) {
            AuditLog::log(
                'impersonate_stop',
                'company',
                $stash['target_company_id'] ?? null,
                [
                    'audit_id' => $stash['audit_id'] ?? null,
                    'started_at' => $startedAt,
                ],
                [
                    'admin_id' => $stash['admin_id'] ?? null,
                    'admin_email' => $stash['admin_email'] ?? null,
                    'target_company_id' => $stash['target_company_id'] ?? null,
                    'stopped_at' => $stoppedAt,
                    'duration_seconds' => $durationSeconds,
                    'audit_id' => $stash['audit_id'] ?? null,
                ],
                $stash['target_company_id'] ?? null
            );
        }

        // Restore admin session.
        $db = Database::getInstance();
        $admin = null;
        if ($db && $db->isConnected() && !empty($stash['admin_id'])) {
            $admin = $db->fetchOne(
                "SELECT * FROM users WHERE id = :id AND status = 'active'",
                ['id' => $stash['admin_id']]
            );
        }

        // Clear session and rebuild as super admin.
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        if (!$admin) {
            // Admin user gone, force re-login.
            $basePath = function_exists('getBasePath') ? getBasePath() : '/';
            return ['success' => true, 'redirect' => $basePath . 'login.php'];
        }

        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['user_email'] = $admin['email'];
        $_SESSION['user_name'] = $admin['name'] ?? $admin['email'];
        $_SESSION['user_role'] = $admin['role'] ?? 'super_admin';
        $_SESSION['user_company_id'] = $admin['company_id'] ?? null;

        $basePath = function_exists('getBasePath') ? getBasePath() : '/';
        return ['success' => true, 'redirect' => $basePath . 'admin/super/companies.php'];
    }

    /**
     * Render the sticky impersonation banner. Safe to call unconditionally ,
     * emits nothing when not impersonating.
     */
    public static function renderBanner(): void
    {
        if (!self::isActive()) {
            return;
        }
        $stash = $_SESSION['impersonator'];
        $basePath = function_exists('getBasePath') ? getBasePath() : '/';
        $csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
        $target = htmlspecialchars($stash['target_company_name'] ?? 'user', ENT_QUOTES, 'UTF-8');
        $admin = htmlspecialchars($stash['admin_email'] ?? 'admin', ENT_QUOTES, 'UTF-8');
        $startedAt = htmlspecialchars($stash['started_at'] ?? '', ENT_QUOTES, 'UTF-8');
        $stopUrl = htmlspecialchars($basePath . 'admin/impersonate.php?action=stop', ENT_QUOTES, 'UTF-8');
        ?>
        <style>
            body { padding-top: 44px !important; }
            #impersonation-banner { font-family: ui-sans-serif, system-ui, sans-serif; }
            #impersonation-banner a, #impersonation-banner strong { color: inherit; }
        </style>
        <div id="impersonation-banner"
             role="alert"
             style="position:fixed;top:0;left:0;right:0;z-index:1000;background:#fbbf24;color:#78350f;
                    border-bottom:2px solid #b45309;box-shadow:0 2px 8px rgba(0,0,0,.15);">
            <div style="padding:8px 16px;display:flex;align-items:center;justify-content:space-between;gap:16px;font-size:14px;font-weight:600;">
                <div>
                    <span style="margin-right:8px;">⚠️</span>
                    You are logged in as <strong><?php echo $target; ?></strong>
                    <span style="font-weight:400;opacity:.85;">
                        (admin: <?php echo $admin; ?>, started <?php echo $startedAt; ?>)
                    </span>
                </div>
                <form method="POST" action="<?php echo $stopUrl; ?>" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit"
                            style="background:#78350f;color:#fff;padding:4px 12px;border-radius:4px;
                                   font-weight:700;border:0;cursor:pointer;">
                        Exit impersonation
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /** Clear impersonation stash, called from logout paths so it never persists. */
    public static function clearStash(): void
    {
        unset($_SESSION['impersonator']);
    }
}
