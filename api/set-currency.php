<?php
/**
 * POST /api/set-currency.php
 * Body: { "currency": "AED" }
 * Sets the user's display currency preference.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Currency.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$currency = strtoupper($input['currency'] ?? '');

if (empty($currency)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'currency required']);
    exit;
}

if (!isset(Currency::supportedCurrencies()[$currency])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'currency not supported']);
    exit;
}

$ok = Currency::setUserCurrency($currency);
echo json_encode(['success' => $ok, 'currency' => $currency]);
