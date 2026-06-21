<?php
/**
 * wc.cardify.om/wc-wallet-apple-matches
 *
 * Issues the signed-in player's daily-updating "Matches" Apple Wallet pass:
 * today's World Cup fixtures in the user's timezone (kickoff times, LIVE/FT)
 * plus yesterday's results. Carries the same webServiceURL + per-pass
 * authenticationToken as the player pass so iOS polls for daily updates.
 *
 * Pass content is built by includes/WcWalletMatches.php (shared with the web
 * service so the pushed pass matches the downloaded one).
 */

ob_start();
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/WcHub.php';
    require_once INCLUDES_DIR . '/AppleWalletPass.php';
    require_once INCLUDES_DIR . '/WcWalletMatches.php';

    $user = WcHub::currentUser();
    if (!$user) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Location: https://wc.cardify.om/');
        exit;
    }

    if (!AppleWalletPass::isEnabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Apple Wallet passes are not configured for this installation.\n";
        exit;
    }

    $bytes = WcWalletMatches::build($user);

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="world-cup-matches.pkpass"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('wc_wallet_apple_matches.php: ' . $e->getMessage());
    echo "Could not generate the Apple Wallet matches pass.\n";
    exit;
}
