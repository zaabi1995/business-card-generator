<?php
/**
 * One sign-out for cardify.om (5 Oct 2026).
 *
 * Cardify, print shop operators, the IQ test and logo downloads share one
 * sign-in on /login, so every sign-out ends all of them: the session (Cardify,
 * operator, IQ), the print shop remember-me cookie and the logo-access cookie.
 * Called from /logout.php, the IQ account page and the logo panel.
 */
final class SignOut
{
    public static function all(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();

        try {
            require_once __DIR__ . '/PrintShopAuth.php';
            PrintShopAuth::logout();
        } catch (Throwable $e) {
            error_log('[signout] print shop: ' . $e->getMessage());
        }
        if (isset($_COOKIE['pso_remember']) && !headers_sent()) {
            setcookie('pso_remember', '', [
                'expires' => time() - 3600, 'path' => '/printshop/',
                'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE['pso_remember']);

        try {
            require_once __DIR__ . '/LogoAccess.php';
            LogoAccess::signOut();
        } catch (Throwable $e) {
            error_log('[signout] logo access: ' . $e->getMessage());
        }

        unset($_SESSION['iq_user_id'], $_SESSION['iq_otp'], $_SESSION['login_code']);
        require_once __DIR__ . '/Auth.php';
        Auth::logout();
    }
}
