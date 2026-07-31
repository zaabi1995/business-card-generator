<?php
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/WalletThemeCatalog.php';
require_once INCLUDES_DIR . '/ScanPassService.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';
require_once __DIR__ . '/_ratelimit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ctx = $method === 'POST'
    ? ScanAuth::requireEmployeeMutation()
    : ScanAuth::requireEmployee();
scanRateLimit($ctx, 'wallet_themes', 120);

$employeeId = (string) $ctx['employee_id'];
$companyId = (string) $ctx['company_id'];
$db = Database::getInstance();

$passExists = static function () use ($db, $employeeId): bool {
    try {
        return (bool) $db->fetchOne(
            "SELECT id
               FROM scan_passes
              WHERE employee_id = :employee_id AND revoked = 0
              LIMIT 1",
            ['employee_id' => $employeeId]
        );
    } catch (Throwable $e) {
        return false;
    }
};

$walletUrl = static function () use ($db, $employeeId, $companyId): ?string {
    if (!AppleWalletPass::isEnabled()) {
        return null;
    }
    $company = $db->fetchOne(
        'SELECT slug FROM companies WHERE id = :company_id LIMIT 1',
        ['company_id' => $companyId]
    );
    $slug = is_array($company) ? trim((string) ($company['slug'] ?? '')) : '';
    if ($slug === '') {
        return null;
    }
    return getTenantUrl($slug, '/wallet_apple.php')
        . '?i=' . rawurlencode($employeeId)
        . '&c=' . rawurlencode($slug)
        . '&lang=en';
};

try {
    if ($method === 'GET') {
        echo json_encode([
            'success' => true,
            'themes' => WalletThemeCatalog::listForProfile(
                $employeeId,
                $companyId
            ),
            'selection' => WalletThemeCatalog::resolvePreference(
                $employeeId,
                $companyId
            ),
            'pass_exists' => $passExists(),
            'wallet_pass_url' => $walletUrl(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $themeId = $body['theme_id'] ?? '';
    $overrides = $body['overrides'] ?? [];
    if (!is_string($themeId) || !is_array($overrides)) {
        throw new InvalidArgumentException('wallet_theme_invalid');
    }
    $selection = WalletThemeCatalog::savePreference(
        $employeeId,
        $companyId,
        $themeId,
        $overrides
    );
    $walletUpdatePending = false;
    try {
        require_once INCLUDES_DIR . '/ApnsProvider.php';
        $registrations = ScanPassService::onCardChanged($employeeId);
        if ($registrations && defined('APPLE_WALLET_PASS_TYPE_ID')) {
            apnsProvider()->pushPassUpdates(
                APPLE_WALLET_PASS_TYPE_ID,
                $registrations
            );
        }
    } catch (Throwable $e) {
        $walletUpdatePending = true;
        error_log('[scan/wallet-themes] Wallet push: ' . $e->getMessage());
    }
    echo json_encode([
        'success' => true,
        'selection' => $selection,
        'pass_exists' => $passExists(),
        'wallet_pass_url' => $walletUrl(),
        'wallet_update_pending' => $walletUpdatePending,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage();
    $status = $code === 'wallet_theme_not_found' ? 404 : 422;
    if ($code === 'profile_forbidden') {
        $status = 403;
    }
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $code]);
} catch (Throwable $e) {
    error_log('[scan/wallet-themes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
