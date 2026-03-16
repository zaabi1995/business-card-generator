<?php
/**
 * Print Shop Billing Bridge
 * Connects print orders with payments (Paymob) and credit accounts
 */
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/CreditManager.php';

class PrintShopBilling {

    /**
     * Get checkout options for a print order
     */
    public static function getCheckoutOptions(int $orderId): array {
        $db = Database::getInstance();
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        $companyId = $order['company_id'];
        $printShopId = (int)$order['print_shop_id'];

        $creditAccount = CreditManager::getAccount($companyId, $printShopId);
        $canUseCredit = false;
        $availableCredit = 0;
        $canRequestCredit = true;

        if ($creditAccount) {
            if ($creditAccount['status'] === 'approved') {
                $availableCredit = CreditManager::getAvailable($creditAccount['id']);
                $canUseCredit = ($availableCredit >= (float)$order['total']);
            }
            if (in_array($creditAccount['status'], ['pending', 'approved'])) {
                $canRequestCredit = false;
            }
        }

        return [
            'order' => $order,
            'can_pay_online' => true,
            'can_use_credit' => $canUseCredit,
            'credit_account' => $creditAccount,
            'available_credit' => $availableCredit,
            'can_request_credit' => $canRequestCredit
        ];
    }

    /**
     * Process online payment for order via Paymob
     */
    public static function payOnline(int $orderId, array $billingData = []): array {
        $db = Database::getInstance();
        $order = $db->fetchOne(
            "SELECT po.*, c.name as company_name, c.admin_email, c.billing_email, c.phone as company_phone
             FROM print_orders po
             JOIN companies c ON c.id = po.company_id
             WHERE po.id = :id",
            ['id' => $orderId]
        );

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        if ($order['payment_status'] === 'paid') {
            return ['error' => 'Order already paid'];
        }

        // Build billing data from order + company info (real data, no placeholders)
        $billingData = array_merge([
            'first_name' => $order['company_name'] ?? 'Customer',
            'last_name' => '',
            'email' => $order['billing_email'] ?? $order['admin_email'] ?? '',
            'phone_number' => $order['shipping_phone'] ?? $order['company_phone'] ?? '',
            'street' => $order['shipping_address'] ?? '',
            'city' => $order['shipping_city'] ?? '',
            'state' => $order['shipping_state'] ?? '',
            'country' => $order['shipping_country'] ?? 'OM',
            'postal_code' => $order['shipping_postal'] ?? ''
        ], $billingData);

        return Payment::createIntent(
            'print_order',
            (string)$orderId,
            (float)$order['total'],
            $order['company_id'],
            $billingData,
            $order['currency'] ?? 'OMR'
        );
    }

    /**
     * Process online payment for a specific amount (deposit support)
     */
    public static function payOnlineAmount(int $orderId, float $amount, string $description = ''): array {
        $db = Database::getInstance();
        $order = $db->fetchOne(
            "SELECT po.*, c.name as company_name, c.admin_email, c.billing_email, c.phone as company_phone
             FROM print_orders po
             JOIN companies c ON c.id = po.company_id
             WHERE po.id = :id",
            ['id' => $orderId]
        );

        if (!$order) return ['error' => 'Order not found'];
        if ($order['payment_status'] === 'paid') return ['error' => 'Order already paid'];

        $billingData = [
            'first_name' => $order['company_name'] ?? 'Customer',
            'last_name' => '',
            'email' => $order['billing_email'] ?? $order['admin_email'] ?? '',
            'phone_number' => $order['shipping_phone'] ?? $order['company_phone'] ?? '',
            'street' => $order['shipping_address'] ?? '',
            'city' => $order['shipping_city'] ?? '',
            'state' => $order['shipping_state'] ?? '',
            'country' => $order['shipping_country'] ?? 'OM',
            'postal_code' => $order['shipping_postal'] ?? ''
        ];

        return Payment::createIntent(
            'print_order',
            (string)$orderId,
            $amount,
            $order['company_id'],
            $billingData,
            $order['currency'] ?? 'OMR'
        );
    }

    /**
     * Charge order to credit account
     */
    public static function chargeToCredit(int $orderId): array {
        $db = Database::getInstance();
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        if ($order['payment_status'] === 'paid') {
            return ['error' => 'Order already paid'];
        }

        $creditAccount = CreditManager::getAccount($order['company_id'], (int)$order['print_shop_id']);
        if (!$creditAccount || $creditAccount['status'] !== 'approved') {
            return ['error' => 'No active credit account'];
        }

        $result = CreditManager::charge($creditAccount['id'], $orderId, (float)$order['total']);

        if (isset($result['error'])) {
            return $result;
        }

        // Update order payment status
        $db->query(
            "UPDATE print_orders SET payment_status = 'paid', payment_method = 'credit', status = 'confirmed' WHERE id = :id",
            ['id' => $orderId]
        );

        return ['success' => true, 'transaction_id' => $result['transaction_id']];
    }

    /**
     * Upload Purchase Order for an order
     */
    public static function uploadPO(int $orderId, array $file, ?string $poNumber = null): array {
        $db = Database::getInstance();
        $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        // Validate file
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowedTypes)) {
            return ['error' => 'Invalid file type. Allowed: PDF, JPG, PNG'];
        }
        if ($file['size'] > $maxSize) {
            return ['error' => 'File too large. Maximum 5MB'];
        }

        // Create upload directory
        $uploadDir = dirname(__DIR__) . '/uploads/purchase_orders/' . $order['company_id'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Save file
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = $orderId . '_' . time() . '.' . $ext;
        $filePath = $uploadDir . '/' . $safeName;
        $relativePath = 'uploads/purchase_orders/' . $order['company_id'] . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['error' => 'Failed to save file'];
        }

        // Update order
        $db->query(
            "UPDATE print_orders SET po_file_path = :path, po_number = :num, po_required = 1, po_received_at = NOW() WHERE id = :id",
            ['path' => $relativePath, 'num' => $poNumber, 'id' => $orderId]
        );

        return ['po_file_path' => $relativePath, 'po_number' => $poNumber];
    }

    /**
     * Get payment summary for an order
     */
    public static function getOrderPaymentInfo(int $orderId): array {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT po.*, p.status as online_payment_status, p.payment_method as online_method,
                    p.paymob_transaction_id
             FROM print_orders po
             LEFT JOIN payments p ON p.id = po.payment_id
             WHERE po.id = :id",
            ['id' => $orderId]
        );
        return $row ?: [];
    }
}
