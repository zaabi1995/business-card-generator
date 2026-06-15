<?php
/**
 * instant_card.php, POST endpoint for the homepage instant-card funnel.
 * Captures name/title/company/email/colour -> creates an unverified demo card
 * under demo.cardify.om and emails a verify link. Returns JSON.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

require_once INCLUDES_DIR . '/functions.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/InstantCard.php';

// Anonymous form -> no session CSRF token; gate with a same-origin check.
if (function_exists('isSameOriginRequest') && !isSameOriginRequest()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'origin']);
    exit;
}

$result = InstantCard::capture([
    'email'   => $_POST['email']   ?? '',
    'name'    => $_POST['name']    ?? '',
    'title'   => $_POST['title']   ?? '',
    'company' => $_POST['company'] ?? '',
    'color'   => $_POST['color']   ?? '',
    'lang'    => $_POST['lang']    ?? 'en',
    'ip'      => $_SERVER['REMOTE_ADDR']     ?? '',
    'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

if (empty($result['ok'])) { http_response_code(422); }
echo json_encode($result);
