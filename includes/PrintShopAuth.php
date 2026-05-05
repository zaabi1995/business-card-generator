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
}
