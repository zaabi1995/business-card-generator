<?php
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once INCLUDES_DIR . '/LogoAccess.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function logoAccessResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        logoAccessResponse(['success' => true, 'state' => LogoAccess::state(), 'csrf' => generateCSRFToken()]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') logoAccessResponse(['error' => 'method_not_allowed'], 405);
    $input = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    if (!validateCSRFToken($input['csrf_token'] ?? '')) logoAccessResponse(['error' => 'invalid_csrf'], 403);
    $action = $input['action'] ?? '';
    $db = Database::getInstance(); $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($action === 'download') {
        if (!RateLimiter::check('logo_ticket', $ip, LogoAccess::paid() ? 300 : 60, 3600)) logoAccessResponse(['error' => 'rate_limited'], 429);
        $companyId = (int)($input['company'] ?? 0); $format = (string)($input['format'] ?? '');
        $company = $db->fetchOne('SELECT * FROM om_companies WHERE id = :id', ['id' => $companyId]);
        if (!$company || !LogoLibrary::canDownload($company) || !in_array($format, LogoAccess::FORMATS, true)) logoAccessResponse(['error' => 'unavailable'], 404);
        if ($format !== 'zip' && !LogoAccess::asset($company, $format)) logoAccessResponse(['error' => 'unavailable'], 404);
        if ($format === 'zip' && !array_filter(LogoLibrary::downloadPaths($company))) logoAccessResponse(['error' => 'unavailable'], 404);
        $ticket = LogoAccess::issueTicket($companyId, $format);
        if (!$ticket) logoAccessResponse(['error' => 'quota_reached', 'state' => LogoAccess::state()], 409);
        logoAccessResponse(['success' => true, 'state' => LogoAccess::state(),
            'url' => '/logo-download?company=' . $companyId . '&format=' . rawurlencode($format) . '&ticket=' . $ticket]);
    }

    // Sign-in is /login for all of cardify.om; the old send_code/verify_code pair is gone.

    if ($action === 'sign_out') {
        // One sign-out: logo access, the IQ test and the Cardify session together.
        require_once INCLUDES_DIR . '/SignOut.php';
        SignOut::all();
        if (session_status() === PHP_SESSION_NONE) session_start();
        logoAccessResponse(['success' => true, 'state' => LogoAccess::state()]);
    }

    if ($action === 'reward_start') {
        $state = LogoAccess::state();
        if (!$state['rewardedUnit'] || $state['remaining'] > 0) logoAccessResponse(['error' => 'ad_unavailable'], 409);
        $token = bin2hex(random_bytes(32));
        $_SESSION['logo_reward_attempt'] = ['token' => $token, 'subject' => LogoAccess::subject(), 'expires' => time() + 300];
        logoAccessResponse(['success' => true, 'challenge' => $token, 'unit' => $state['rewardedUnit']]);
    }
    if ($action === 'reward_granted') {
        if (!LogoAccess::reward((string)($input['challenge'] ?? ''))) logoAccessResponse(['error' => 'ad_unavailable'], 409);
        logoAccessResponse(['success' => true, 'state' => LogoAccess::state()]);
    }

    if ($action === 'intent') {
        $intent = $_SESSION['logo_checkout_intent'] ?? null;
        if (!$intent || $intent['expires'] < time() || $intent['order'] !== ($input['order'] ?? '')
            || $intent['subject'] !== LogoAccess::subject()) logoAccessResponse(['error' => 'checkout_expired'], 409);
        $order = $db->fetchOne('SELECT status FROM logo_pass_orders WHERE id = :id', ['id' => $intent['order']]);
        if (!$order || $order['status'] !== 'pending' || LogoAccess::paid()) logoAccessResponse(['error' => 'checkout_expired'], 409);
        require_once INCLUDES_DIR . '/Payment.php';
        $result = $intent['result'];
        if (empty($result['paymentToken'])) {
            $result['paymentToken'] = Payment::applePayToken($result['publicKey'], $result['clientSecret']);
            if (!$result['paymentToken']) logoAccessResponse(['error' => 'gateway_unavailable'], 502);
            $_SESSION['logo_checkout_intent']['result'] = $result;
        }
        logoAccessResponse($result);
    }

    if ($action === 'checkout') {
        $member = LogoAccess::member();
        if (!$member) logoAccessResponse(['error' => 'sign_in_required'], 401);
        if (LogoAccess::paid()) logoAccessResponse(['error' => 'already_paid', 'state' => LogoAccess::state()], 409);
        $first = trim((string)($input['first_name'] ?? '')); $last = trim((string)($input['last_name'] ?? ''));
        $phone = preg_replace('/[\s()\-]/', '', (string)($input['phone'] ?? ''));
        if (!$first || !$last || mb_strlen($first) > 60 || mb_strlen($last) > 60 || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) logoAccessResponse(['error' => 'invalid_billing'], 422);
        $member = LogoAccess::ensureMember($member['email'], $first . ' ' . $last);
        $db->getConnection()->prepare('UPDATE logo_members SET name = ?, phone = ? WHERE id = ?')->execute([$first . ' ' . $last, $phone, $member['id']]);
        $billingHash = hash('sha256', json_encode([$member['email'], $first, $last, $phone, LogoAccess::price(), LogoAccess::days()]));
        $existing = $_SESSION['logo_checkout_intent'] ?? null;
        if ($existing && $existing['expires'] > time() && $existing['subject'] === LogoAccess::subject() && $existing['billingHash'] === $billingHash) {
            logoAccessResponse($existing['result']);
        }
        if (!RateLimiter::check('logo_checkout', $ip, 10, 3600)) logoAccessResponse(['error' => 'rate_limited'], 429);
        $orderId = generateUUID(); $companyId = (int)($input['company'] ?? 0); $format = (string)($input['format'] ?? '');
        if ($companyId && !in_array($format, LogoAccess::FORMATS, true)) logoAccessResponse(['error' => 'unavailable'], 422);
        $db->insert('logo_pass_orders', ['id' => $orderId, 'member_id' => $member['id'], 'amount' => LogoAccess::price(),
            'days' => LogoAccess::days(), 'return_company_id' => $companyId ?: null, 'return_format' => $format ?: null, 'locale' => currentLocale()]);
        require_once INCLUDES_DIR . '/Payment.php';
        $result = Payment::createIntent('logo_pass', $orderId, LogoAccess::price(), null,
            ['first_name' => $first, 'last_name' => $last, 'email' => $member['email'], 'phone_number' => $phone], 'OMR', 'one_time');
        if (empty($result['success'])) {
            $db->getConnection()->prepare("UPDATE logo_pass_orders SET status = 'failed' WHERE id = ?")->execute([$orderId]);
            logoAccessResponse(['error' => 'gateway_unavailable'], 502);
        }
        $db->getConnection()->prepare('UPDATE logo_pass_orders SET payment_id = ? WHERE id = ?')->execute([$result['payment_id'], $orderId]);
        $response = ['success' => true, 'order' => $orderId, 'publicKey' => $result['public_key'], 'clientSecret' => $result['client_secret'],
            'fallbackUrl' => $result['checkout_url'], 'successUrl' => '/logo-access.php?payment=returned&order=' . $orderId . '&lang=' . currentLocale(),
            'amount' => number_format(LogoAccess::price(), 3, '.', '')];
        $_SESSION['logo_checkout_intent'] = ['order' => $orderId, 'subject' => LogoAccess::subject(), 'billingHash' => $billingHash, 'expires' => time() + 900, 'result' => $response];
        logoAccessResponse($response);
    }

    logoAccessResponse(['error' => 'unknown_action'], 400);
} catch (Throwable $e) {
    error_log('Logo access: ' . $e->getMessage());
    logoAccessResponse(['error' => 'try_again'], 503);
}
