<?php
/**
 * LinkedIn OAuth, captures org (Cardify company page) access token.
 * Saves to system_settings.linkedin_org_access_token.
 * Scopes: openid profile email w_member_social w_organization_social r_organization_social
 *
 * Hardened 2026-05-06 (E2E loop iter 21): same fixes as
 * api/linkedin/callback.php (admin auth required on both legs, state
 * validated via session, no info disclosure on errors, no token preview).
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'super_admin', 'company', 'company_admin'], true)) {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Admin access required.</p>';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$client_id     = defined('LINKEDIN_CLIENT_ID') ? LINKEDIN_CLIENT_ID : '';
$client_secret = defined('LINKEDIN_CLIENT_SECRET') ? LINKEDIN_CLIENT_SECRET : '';
$host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
$redirect_uri = 'https://' . $host . '/api/linkedin/connect-company';

if (isset($_GET['code'])) {
    $expected = $_SESSION['linkedin_org_oauth_state'] ?? '';
    $received = (string) ($_GET['state'] ?? '');
    unset($_SESSION['linkedin_org_oauth_state']);
    if (!$expected || !hash_equals($expected, $received)) {
        http_response_code(400);
        echo '<h1>Invalid state</h1><p>The OAuth state token did not match. Start the flow again from /admin.</p>';
        exit;
    }

    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => (string) $_GET['code'],
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[linkedin/connect-company] curl: ' . $curlErr);
        echo '<h1>Network error talking to LinkedIn</h1><p>Try again. The server log has the details.</p>';
        exit;
    }

    $data = json_decode((string) $res, true);
    if (empty($data['access_token'])) {
        $errCode = (string) ($data['error'] ?? 'unknown');
        error_log('[linkedin/connect-company] LinkedIn ' . $httpCode . ' err=' . $errCode . ' body=' . substr((string) $res, 0, 500));
        echo '<h1 style="color:red">OAuth failed</h1><p>HTTP ' . (int) $httpCode . ' (' . htmlspecialchars($errCode) . '). Check the server log for details.</p>';
        exit;
    }
    $token   = $data['access_token'];
    $expires = isset($data['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $data['expires_in']) : 'unknown';

    // Verify the token can post as Cardify org
    $ch2 = curl_init('https://api.linkedin.com/v2/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-Restli-Protocol-Version: 2.0.0',
        ],
        CURLOPT_TIMEOUT    => 15,
    ]);
    $aclRes = curl_exec($ch2);
    curl_close($ch2);
    $acl  = json_decode((string) $aclRes, true);
    $orgs = [];
    foreach (($acl['elements'] ?? []) as $el) {
        if (isset($el['organization'])) $orgs[] = $el['organization'];
    }

    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (id, setting_key, setting_value, updated_at)
             VALUES (UUID(), ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $stmt->execute(['linkedin_org_access_token', $token]);
        $stmt->execute(['linkedin_org_token_expires', $expires]);
    } catch (Exception $e) {
        error_log('[linkedin/connect-company] db: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<h1>Could not save token</h1><p>The database rejected the write. Check the server log.</p>';
        exit;
    }

    echo '<html><body style="font-family:system-ui;max-width:640px;margin:60px auto;text-align:center">';
    echo '<h1 style="color:#0a66c2">&#10003; Cardify Company Page Connected</h1>';
    echo '<p>Org access token saved. Expires: ' . htmlspecialchars($expires) . '</p>';
    echo '<p><strong>Organizations this token can post as:</strong></p>';
    echo '<ul style="text-align:left;display:inline-block">';
    foreach ($orgs as $o) echo '<li>' . htmlspecialchars($o) . '</li>';
    if (!$orgs) echo '<li style="color:#c00">(none, you may not be a Cardify page admin, or w_organization_social scope is missing)</li>';
    echo '</ul>';
    echo '<p><a href="/admin">Back to Admin</a></p></body></html>';
    exit;
}

if (isset($_GET['error'])) {
    $errCode = (string) $_GET['error'];
    $errMsg  = substr((string) ($_GET['error_description'] ?? $errCode), 0, 200);
    echo 'OAuth error: ' . htmlspecialchars($errMsg) . ' (' . htmlspecialchars($errCode) . ')';
    exit;
}

$scopes = 'openid profile email w_member_social w_organization_social r_organization_social';
$state  = bin2hex(random_bytes(16));
$_SESSION['linkedin_org_oauth_state'] = $state;
$authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'state'         => $state,
    'scope'         => $scopes,
]);
header('Location: ' . $authUrl);
exit;
