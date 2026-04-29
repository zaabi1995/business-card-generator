<?php
/**
 * LinkedIn OAuth, captures org (Cardify company page) access token.
 * Saves to system_settings.linkedin_org_access_token.
 * Scopes: openid profile email w_member_social w_organization_social r_organization_social
 */
require_once __DIR__ . '/../../config.php';

$client_id = defined('LINKEDIN_CLIENT_ID') ? LINKEDIN_CLIENT_ID : '';
$client_secret = defined('LINKEDIN_CLIENT_SECRET') ? LINKEDIN_CLIENT_SECRET : '';
$host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
$redirect_uri = 'https://' . $host . '/api/linkedin/connect-company';

if (isset($_GET['code'])) {
    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $redirect_uri,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    $data = json_decode($res, true);
    if (empty($data['access_token'])) {
        echo '<h1 style="color:red">OAuth failed</h1><pre>' . htmlspecialchars($res) . '</pre>';
        exit;
    }
    $token = $data['access_token'];
    $expires = isset($data['expires_in']) ? date('Y-m-d H:i:s', time() + $data['expires_in']) : 'unknown';

    // Verify the token can post as Cardify org
    $ch2 = curl_init('https://api.linkedin.com/v2/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-Restli-Protocol-Version: 2.0.0',
        ],
    ]);
    $aclRes = curl_exec($ch2);
    $acl = json_decode($aclRes, true);
    $orgs = [];
    foreach (($acl['elements'] ?? []) as $el) {
        if (isset($el['organization'])) $orgs[] = $el['organization'];
    }

    // Save
    $conn = Database::getInstance()->getConnection();
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (id, setting_key, setting_value, updated_at)
         VALUES (UUID(), ?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );
    $stmt->execute(['linkedin_org_access_token', $token]);
    $stmt->execute(['linkedin_org_token_expires', $expires]);

    echo '<html><body style="font-family:system-ui;max-width:640px;margin:60px auto;text-align:center">';
    echo '<h1 style="color:#0a66c2">&#10003; Cardify Company Page Connected</h1>';
    echo '<p>Org access token saved. Expires: ' . htmlspecialchars($expires) . '</p>';
    echo '<p><strong>Organizations this token can post as:</strong></p>';
    echo '<ul style="text-align:left;display:inline-block">';
    foreach ($orgs as $o) echo '<li>' . htmlspecialchars($o) . '</li>';
    if (!$orgs) echo '<li style="color:#c00">(none &mdash; you may not be a Cardify page admin, or w_organization_social scope is missing)</li>';
    echo '</ul>';
    echo '<p><a href="/admin">Back to Admin</a></p></body></html>';
    exit;
}

if (isset($_GET['error'])) {
    echo 'OAuth error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    exit;
}

$scopes = 'openid profile email w_member_social w_organization_social r_organization_social';
$state = bin2hex(random_bytes(16));
$authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'state' => $state,
    'scope' => $scopes,
]);
header('Location: ' . $authUrl);
exit;
