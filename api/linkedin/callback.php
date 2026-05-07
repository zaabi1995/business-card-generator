<?php
// LinkedIn OAuth callback. Saves access token to system_settings.
//
// Hardened 2026-05-06 (E2E loop iter 21):
//   - require admin auth on BOTH legs (the redirect-out and the come-back).
//     Previously: any anonymous visitor could initiate the flow + an attacker
//     could trick BHD's admin into approving a hostile LinkedIn app and have
//     the resulting access_token saved server-side, replacing the legitimate
//     one (account-takeover for the LinkedIn-connected page).
//   - persist `state` in session and validate it on return. Previously: state
//     was generated for the auth URL but never stored, so the state echo on
//     return was meaningless; now state mismatch is fatal.
//   - stop reflecting raw cURL errors / LinkedIn HTTP response bodies / DB
//     exception messages / partial access_tokens to the browser. All of
//     those leak server internals and even part of the secret token.
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
$redirect_uri = 'https://' . $host . '/api/linkedin/callback';

// Step 1: come-back from LinkedIn with ?code=
if (isset($_GET['code'])) {
    // Validate state
    $expected = $_SESSION['linkedin_oauth_state'] ?? '';
    $received = (string) ($_GET['state'] ?? '');
    unset($_SESSION['linkedin_oauth_state']); // single-use
    if (!$expected || !hash_equals($expected, $received)) {
        http_response_code(400);
        echo '<h1>Invalid state</h1><p>The OAuth state token did not match. Start the flow again from /admin.</p>';
        exit;
    }

    $code = (string) $_GET['code'];

    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 30,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        error_log('[linkedin/callback] curl: ' . $curlError);
        echo '<h1>Network error talking to LinkedIn</h1><p>Try again. The server log has the details.</p>';
        exit;
    }

    $data = json_decode((string) $response, true);

    if (isset($data['access_token'])) {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("INSERT INTO system_settings (id, setting_key, setting_value, updated_at)
                        VALUES (UUID(), ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute(['linkedin_access_token', $data['access_token']]);

            $expires = 'unknown';
            if (isset($data['expires_in'])) {
                $expires = date('Y-m-d H:i:s', time() + (int) $data['expires_in']);
                $stmt->execute(['linkedin_token_expires', $expires]);
            }

            echo '<html><body style="font-family:system-ui;max-width:600px;margin:50px auto;text-align:center">';
            echo '<h1 style="color:#0a66c2">&#10003; LinkedIn Connected!</h1>';
            echo '<p>Access token saved.</p>';
            echo '<p>Expires: ' . htmlspecialchars($expires) . '</p>';
            // NOTE: never echo any portion of the access_token. Even 20 chars
            // is enough to identify the token in logs / browser history / etc.
            echo '<p><a href="/admin">Back to Admin</a></p>';
            echo '</body></html>';
        } catch (Exception $e) {
            error_log('[linkedin/callback] db: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo '<h1>Could not save token</h1><p>The database rejected the write. Check the server log.</p>';
        }
    } else {
        $errCode = (string) ($data['error'] ?? 'unknown');
        $errMsg  = (string) ($data['error_description'] ?? '');
        error_log('[linkedin/callback] LinkedIn ' . $httpCode . ' err=' . $errCode . ' desc=' . substr($errMsg, 0, 200));
        echo '<html><body style="font-family:system-ui;max-width:600px;margin:50px auto">';
        echo '<h1 style="color:red">LinkedIn rejected the code</h1>';
        // Echo the labelled error code only. Do NOT dump the raw response body.
        echo '<p>HTTP ' . (int) $httpCode . ' (' . htmlspecialchars($errCode) . ')</p>';
        echo '<p><a href="/api/linkedin/callback">Try again</a></p>';
        echo '</body></html>';
    }
    exit;
}

// Step 2: LinkedIn returned an explicit error
if (isset($_GET['error'])) {
    $errCode = (string) $_GET['error'];
    // error_description is reflected from a third party, htmlspecialchars
    // already neutralises it, but cap length to avoid pathological payloads.
    $errMsg = substr((string) ($_GET['error_description'] ?? $errCode), 0, 200);
    echo 'Error: ' . htmlspecialchars($errMsg) . ' (' . htmlspecialchars($errCode) . ')';
    exit;
}

// Step 3: kick off the auth flow with a fresh, session-bound state token
$scopes = 'openid profile w_member_social';
$state  = bin2hex(random_bytes(16));
$_SESSION['linkedin_oauth_state'] = $state;

$authUrl = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'state'         => $state,
    'scope'         => $scopes,
]);

header('Location: ' . $authUrl);
exit;
