<?php
// LinkedIn OAuth callback — saves access token to system_settings
require_once __DIR__ . '/../../config.php';

$client_id = defined('LINKEDIN_CLIENT_ID') ? LINKEDIN_CLIENT_ID : '';
$client_secret = defined('LINKEDIN_CLIENT_SECRET') ? LINKEDIN_CLIENT_SECRET : '';
$host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
$redirect_uri = 'https://' . $host . '/api/linkedin/callback';

// Step 1: If we have a code, exchange it for a token
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curlError) {
        echo "<h1>cURL Error</h1><pre>$curlError</pre>";
        exit;
    }
    
    $data = json_decode($response, true);
    
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
                $expires = date('Y-m-d H:i:s', time() + $data['expires_in']);
                $stmt->execute(['linkedin_token_expires', $expires]);
            }
            
            echo '<html><body style="font-family:system-ui;max-width:600px;margin:50px auto;text-align:center">';
            echo '<h1 style="color:#0a66c2">&#10003; LinkedIn Connected (Personal)!</h1>';
            echo '<p>Organization access token saved successfully.</p>';
            echo '<p>Expires: ' . htmlspecialchars($expires) . '</p>';
            echo '<p style="color:#666">Token: ' . htmlspecialchars(substr($data['access_token'], 0, 20)) . '...</p>';
            echo '<p><a href="/admin">Back to Admin</a></p>';
            echo '</body></html>';
        } catch (Exception $e) {
            echo '<h1>DB Error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        }
    } else {
        echo '<html><body style="font-family:system-ui;max-width:600px;margin:50px auto">';
        echo '<h1 style="color:red">Error from LinkedIn</h1>';
        echo '<p>HTTP ' . $httpCode . '</p>';
        echo '<pre>' . htmlspecialchars($response) . '</pre>';
        echo '</body></html>';
    }
    exit;
}

// Step 2: If error
if (isset($_GET['error'])) {
    echo 'Error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    exit;
}

// Step 3: No code - redirect to LinkedIn auth
$scopes = 'openid profile w_member_social';
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
