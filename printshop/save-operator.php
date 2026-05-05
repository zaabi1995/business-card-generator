<?php
/**
 * POST handler for printshop/operators.php (add + edit + disable).
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/PrintShopOperator.php';
require_once INCLUDES_DIR . '/AuditLog.php';

$ctx = PrintShopAuth::requireLogin();
$shopId = (int) $ctx['shop']['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . getBasePath() . 'printshop/operators.php');
    exit;
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}

$id   = trim($_POST['id'] ?? '');
$data = [
    'name'   => $_POST['name']   ?? '',
    'phone'  => $_POST['phone']  ?? '',
    'email'  => $_POST['email']  ?? '',
    'status' => $_POST['status'] ?? 'active',
];

// If editing, ensure the row belongs to this shop
if ($id !== '') {
    $existing = PrintShopOperator::getById($id);
    if (!$existing || (int)$existing['print_shop_id'] !== $shopId) {
        $_SESSION['ps_operators_flash'] = 'Operator not found.';
        header('Location: ' . getBasePath() . 'printshop/operators.php');
        exit;
    }
}

$res = PrintShopOperator::save($shopId, $data, $id !== '' ? $id : null);

$flashMap = [
    'name_required'             => 'Name is required.',
    'phone_or_email_required'   => 'Provide a phone or email.',
    'invalid_email'             => 'Email looks invalid.',
    'duplicate_phone_or_email'  => 'That phone or email is already in use.',
    'save_failed'               => 'Could not save the operator.',
];

if ($res['success']) {
    $_SESSION['ps_operators_flash'] = $id ? 'Operator updated.' : 'Operator added.';
    AuditLog::log($id ? 'update' : 'create', 'print_shop_operator', $res['id'], null, [
        'shop_id' => $shopId,
        'name'    => $data['name'],
    ]);
} else {
    $_SESSION['ps_operators_flash'] = $flashMap[$res['error'] ?? ''] ?? ('Error: ' . ($res['error'] ?? 'unknown'));
}

header('Location: ' . getBasePath() . 'printshop/operators.php');
exit;
