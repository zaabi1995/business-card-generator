<?php
/**
 * PrintShopAuth, session helper for the print-shop side.
 *
 * Two login paths land here:
 *  1. Legacy: shop owner via email+password (Auth::login). Session
 *     has $_SESSION['user_id'] tied to the print_shops.user_id row.
 *     getCurrentShopId() falls back to PrintShop::getByUserId.
 *  2. Operator: phone or email OTP. loginAsOperator() sets
 *     $_SESSION['ps_operator_id'] / ['ps_print_shop_id'] / ['ps_operator_name'].
 *
 * requireInternalProvider() gates the browse-clients pages on the
 * `print_shops.is_internal_provider` flag.
 */
require_once __DIR__ . '/PrintShop.php';
require_once __DIR__ . '/PrintShopOperator.php';

class PrintShopAuth {

    /**
     * Mint an operator session after a successful OTP verify.
     * Rotates the session id to defeat fixation.
     */
    public static function loginAsOperator(array $operator, array $shop, string $authVia = 'otp'): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['ps_operator_id']   = $operator['id'];
        $_SESSION['ps_operator_name'] = $operator['name'];
        $_SESSION['ps_print_shop_id'] = (int) $shop['id'];
        $_SESSION['ps_auth_via']      = $authVia;

        // Mirror into the existing user_* keys so AuditLog (which
        // reads $_SESSION['user_id'] / user_role) attributes actions
        // cleanly without a model change.
        $_SESSION['user_id']    = 'pso:' . $operator['id'];
        $_SESSION['user_email'] = $operator['email'] ?: ($operator['phone'] ?? '');
        $_SESSION['user_role']  = 'print_shop_operator';

        PrintShopOperator::touchLogin($operator['id']);
    }

    /**
     * @return ['operator' => ?array, 'shop' => ?array, 'via' => string]
     */
    public static function context(): array {
        $opId  = $_SESSION['ps_operator_id'] ?? null;
        $shopId = $_SESSION['ps_print_shop_id'] ?? null;
        if ($opId && $shopId) {
            $op   = PrintShopOperator::getById($opId);
            $shop = PrintShop::getById($shopId);
            if ($op && $shop && $op['status'] === 'active' && (int)$op['print_shop_id'] === (int)$shop['id']) {
                return ['operator' => $op, 'shop' => $shop, 'via' => $_SESSION['ps_auth_via'] ?? 'otp'];
            }
        }

        // Try the 30-day signed remember-me cookie. When the HMAC + ttl
        // + operator status all check out, mint a session and treat the
        // operator as logged in for this request (and subsequent ones).
        if (!empty($_COOKIE['pso_remember'])) {
            $restored = self::tryRestoreFromRememberCookie($_COOKIE['pso_remember']);
            if ($restored) {
                return ['operator' => $restored['operator'], 'shop' => $restored['shop'], 'via' => 'remember'];
            }
        }

        // Legacy path: shop owner logged in via email+password
        if (!empty($_SESSION['user_id']) && strpos((string)$_SESSION['user_id'], 'pso:') !== 0) {
            $shop = PrintShop::getByUserId($_SESSION['user_id']);
            if ($shop) {
                return ['operator' => null, 'shop' => $shop, 'via' => 'owner'];
            }
        }
        return ['operator' => null, 'shop' => null, 'via' => null];
    }

    public static function currentShop(): ?array {
        return self::context()['shop'];
    }

    public static function currentOperator(): ?array {
        return self::context()['operator'];
    }

    public static function requireLogin(): array {
        $ctx = self::context();
        if (!$ctx['shop']) {
            header('Location: ' . getBasePath() . 'printshop/login.php');
            exit;
        }
        return $ctx;
    }

    public static function requireInternalProvider(): array {
        $ctx = self::requireLogin();
        if (empty($ctx['shop']['is_internal_provider'])) {
            http_response_code(403);
            echo '<h1>403</h1><p>This area is only available to internal-provider print shops.</p>';
            exit;
        }
        return $ctx;
    }

    /**
     * Validate a `pso_remember` cookie. On success, mint an operator
     * session and return the resolved [operator, shop] tuple. On any
     * failure (bad signature, expired, disabled operator), null.
     */
    private static function tryRestoreFromRememberCookie(string $cookie): ?array {
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2) return null;
        [$payloadB64, $sig] = $parts;
        $secret = defined('APP_SECRET') ? APP_SECRET : ($_SERVER['APP_SECRET'] ?? 'cardify-default-secret');
        $expected = hash_hmac('sha256', $payloadB64, $secret);
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(base64_decode($payloadB64) ?: '', true);
        if (!is_array($payload)) return null;
        if (empty($payload['op']) || empty($payload['sh']) || empty($payload['exp'])) return null;
        if ((int) $payload['exp'] < time()) return null;
        $op = PrintShopOperator::getById((string) $payload['op']);
        if (!$op || ($op['status'] ?? '') !== 'active') return null;
        if ((int) $op['print_shop_id'] !== (int) $payload['sh']) return null;
        $shop = PrintShop::getById((int) $payload['sh']);
        if (!$shop) return null;
        // Mint a fresh session so subsequent requests don't re-validate the cookie.
        self::loginAsOperator($op, $shop, 'remember');
        return ['operator' => $op, 'shop' => $shop];
    }

    public static function logout(): void {
        unset(
            $_SESSION['ps_operator_id'],
            $_SESSION['ps_operator_name'],
            $_SESSION['ps_print_shop_id'],
            $_SESSION['ps_auth_via']
        );
        if (($_SESSION['user_role'] ?? '') === 'print_shop_operator') {
            unset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_role']);
        }
    }

    /**
     * Capability check, computed from (shop.tier, operator.role).
     *
     * Tiers:
     *   - 'admin'    BHD-style. Cross-tenant + cancel + refund + everything.
     *   - 'standard' Own-shop only. Cancel gated to operator.role='admin'.
     *
     * Roles within a shop:
     *   - 'admin'    Cancel, refund, manage operators + pricing + settings.
     *   - 'operator' Advance status, view reports. No cancel/refund.
     *   - 'viewer'   Read-only.
     *
     * Capabilities checked here (extend as the matrix grows):
     *   - cancel_order      Issue a cancellation on a print order.
     *   - refund_order      Trigger a refund (admin tier shops only).
     *   - browse_clients    See / open every Cardify tenant from the
     *                        print-shop side. Admin tier shops only.
     *   - manage_operators  Add / edit / disable operators.
     *   - manage_pricing    Edit per-client pricing overrides.
     *   - manage_settings   Edit shop profile + payment terms.
     *
     * Pass an explicit context to test against arbitrary shop/operator data
     * (e.g. when cross-checking a sub-resource); default reads the current
     * session via context().
     *
     * @return bool
     */
    public static function can(string $capability, ?array $contextOverride = null): bool {
        $ctx  = $contextOverride ?? self::context();
        $shop = $ctx['shop']     ?? null;
        $op   = $ctx['operator'] ?? null;
        if (!$shop) return false;

        $shopTier = ($shop['tier']     ?? 'standard') ?: 'standard';
        $opRole   = ($op['role']       ?? null);
        // Legacy owner login (no operator row) treats the user as admin
        // within their own shop. Keeps existing single-operator shops
        // working without a backfill on every login.
        if ($opRole === null) $opRole = 'admin';

        switch ($capability) {
            case 'cancel_order':
                // Admin-tier shops: any operator role except 'viewer' can cancel.
                // Standard shops: only operator.role='admin' can cancel.
                if ($shopTier === 'admin')    return $opRole !== 'viewer';
                return $opRole === 'admin';

            case 'refund_order':
                // Refunds touch money. Admin tier shops + admin operator only.
                return ($shopTier === 'admin') && ($opRole === 'admin');

            case 'browse_clients':
                // Cross-tenant browse is admin-tier only.
                return ($shopTier === 'admin') && ($opRole !== 'viewer');

            case 'manage_operators':
            case 'manage_pricing':
            case 'manage_settings':
                return $opRole === 'admin';

            case 'advance_status':
                // Anyone with operator+ role can advance order status.
                return $opRole !== 'viewer';

            case 'view':
                // Everyone signed in can view.
                return true;

            default:
                // Unknown capabilities default to deny.
                return false;
        }
    }
}
