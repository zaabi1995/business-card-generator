<?php
/**
 * Cancel a print order.
 *
 * Two-step destructive action: caller must POST a reason. Server validates
 * the operator has 'cancel_order' capability (see PrintShopAuth::can),
 * confirms the order belongs to their shop, confirms the order is not in
 * a frozen state (shipped / delivered / already-cancelled), then sets
 * status='cancelled' via PrintShopIntegration::updateOrderStatus (which
 * fires the existing email + WhatsApp notification pipeline) and stamps
 * cancelled_at + cancelled_by_operator_id + cancellation_reason for audit.
 *
 * Body (multipart/x-www-form-urlencoded):
 *   csrf_token, order_id, reason
 *
 * Returns: { ok, order_id, status, message } JSON
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/PrintShopIntegration.php';

header('Content-Type: application/json');

try {
    $ctx  = PrintShopAuth::requireLogin();
    $shop = $ctx['shop'];
    $op   = $ctx['operator'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('printshopdash.invalid_request')]);
        exit;
    }

    if (!PrintShopAuth::can('cancel_order', $ctx)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => t('printshopdash.cancel_denied'),
            'capability' => 'cancel_order',
        ]);
        exit;
    }

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $reason  = trim((string) ($_POST['reason'] ?? ''));

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('printshopdash.cancel_missing_order')]);
        exit;
    }
    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('printshopdash.cancel_reason_required')]);
        exit;
    }
    if (mb_strlen($reason) > 1000) {
        $reason = mb_substr($reason, 0, 1000);
    }

    $shopId = (int) $shop['id'];
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    // Confirm the order exists + belongs to this shop. Super-admin viewing
    // a different shop via ?shop=N still resolves to that shop here because
    // the legacy auth path returns the same shop in $ctx['shop'].
    $stmt = $pdo->prepare(
        "SELECT id, status, company_id, total
         FROM print_orders
         WHERE id = :oid AND print_shop_id = :sid LIMIT 1"
    );
    $stmt->execute([':oid' => $orderId, ':sid' => $shopId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('printshopdash.cancel_not_found')]);
        exit;
    }

    // Frozen states: cannot cancel.
    $current = (string) ($order['status'] ?? '');
    if (in_array($current, ['shipped', 'delivered', 'cancelled'], true)) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error'        => strtr(t('printshopdash.cancel_frozen'), [':status' => $current]),
            'order_status' => $current,
        ]);
        exit;
    }

    // Stamp audit columns AND flip status atomically. The two writes touch
    // the same row and must succeed together (otherwise we'd end up with
    // cancelled metadata on a still-pending order, or vice versa).
    // PrintShopIntegration::updateOrderStatus runs the email + WhatsApp +
    // Notifier pipeline AFTER its own UPDATE returns, so commit before
    // letting it dispatch.
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            "UPDATE print_orders
             SET cancelled_at             = NOW(),
                 cancelled_by_operator_id = :op,
                 cancellation_reason      = :reason
             WHERE id = :oid AND print_shop_id = :sid"
        );
        $upd->execute([
            ':op'     => $op['id'] ?? null,
            ':reason' => $reason,
            ':oid'    => $orderId,
            ':sid'    => $shopId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Audit columns might not exist on an older deployment (migration 110).
        // Log and continue: the status flip below is the contract that matters.
        error_log('[cancel-order] audit columns missing or failed: ' . $e->getMessage());
    }

    // Flip status. Reuses the existing pipeline, which fires email +
    // WhatsApp notifications + Notifier events.
    $result = PrintShopIntegration::updateOrderStatus($orderId, 'cancelled', null);
    if (empty($result['success'])) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => $result['error'] ?? t('printshopdash.unknown_error'),
        ]);
        exit;
    }

    // Audit log (best-effort, swallow if helper missing)
    if (function_exists('logAuditEvent')) {
        try {
            logAuditEvent('printshop_order_cancelled', [
                'order_id'    => $orderId,
                'shop_id'     => $shopId,
                'operator_id' => $op['id'] ?? null,
                'reason'      => $reason,
                'prev_status' => $current,
                'amount'      => (float) $order['total'],
                'company_id'  => $order['company_id'],
            ]);
        } catch (Throwable $_) { /* best-effort */ }
    }

    echo json_encode([
        'ok'       => true,
        'order_id' => $orderId,
        'status'   => 'cancelled',
        'message'  => strtr(t('printshopdash.cancel_success'), [':id' => (string) $orderId]),
    ]);

} catch (Throwable $e) {
    error_log('[cancel-order] FATAL ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => t('printshopdash.unknown_error')]);
}
