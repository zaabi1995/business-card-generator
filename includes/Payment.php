<?php
/**
 * Unified Paymob Payment Handler
 * Handles both subscription and print order payments via Paymob Unified Checkout
 * Reads config from PHP constants (PAYMOB_SECRET_KEY, PAYMOB_PUBLIC_KEY, etc.)
 */

class Payment {

    /**
     * Convert amount to smallest currency unit for Paymob
     * OMR/BHD/KWD = 3 decimals (×1000), others = 2 decimals (×100)
     */
    public static function toSmallestUnit(float $amount, string $currency = 'OMR'): int {
        $threeDecimal = ['OMR', 'BHD', 'KWD'];
        $multiplier = in_array(strtoupper($currency), $threeDecimal) ? 1000 : 100;
        return (int) round($amount * $multiplier);
    }

    /**
     * Compute Paymob HMAC-SHA512 signature
     */
    public static function computeHmac(array $data, string $secret): string {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order', 'owner', 'pending',
            'source_data_pan', 'source_data_sub_type', 'source_data_type', 'success'
        ];

        $concatenated = '';
        foreach ($fields as $field) {
            $value = $data[$field] ?? '';
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $concatenated .= (string)$value;
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    /**
     * Verify HMAC signature from Paymob callback
     */
    public static function verifyHmac(array $data, string $hmac): bool {
        $secret = defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '';
        if (empty($secret)) return false;
        $computed = self::computeHmac($data, $secret);
        return hash_equals($computed, $hmac);
    }

    /**
     * Create Paymob payment intent for subscription or print order
     *
     * @param string $type 'subscription' or 'print_order'
     * @param string $referenceId plan_id or print order id
     * @param float $amount in full currency units (e.g. 5.000 OMR)
     * @param string $companyId
     * @param array $billingData Override billing data ['first_name','last_name','email','phone_number']
     * @param string $currency
     * @return array ['payment_id'=>...,'checkout_url'=>...] or ['error'=>...]
     */
    public static function createIntent(
        string $type,
        string $referenceId,
        float $amount,
        string $companyId,
        array $billingData = [],
        string $currency = 'OMR'
    ): array {
        $secretKey = defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '';
        $publicKey = defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '';
        $integrationIds = defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : '';

        if (empty($secretKey) || empty($publicKey)) {
            return ['error' => 'Paymob credentials not configured'];
        }

        $paymentMethods = array_map('intval', array_filter(explode(',', $integrationIds)));
        if (empty($paymentMethods)) {
            return ['error' => 'Paymob integration IDs not configured'];
        }

        $db = Database::getInstance();

        // Get company info for real billing data
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        if (!$company) {
            return ['error' => 'Company not found'];
        }

        // Use company currency if not specified
        if ($currency === 'OMR' && !empty($company['currency'])) {
            $currency = $company['currency'];
        }

        $amountCents = self::toSmallestUnit($amount, $currency);
        $prefix = ($type === 'subscription') ? 'SUB' : 'PO';
        $specialReference = "{$prefix}_{$companyId}_{$referenceId}_" . time();

        // Build real billing data from company info, with overrides
        $companyName = $company['name'] ?? 'Customer';
        $nameParts = explode(' ', $companyName, 2);
        $paymobBilling = [
            'first_name' => $billingData['first_name'] ?? $nameParts[0],
            'last_name' => $billingData['last_name'] ?? ($nameParts[1] ?? $nameParts[0]),
            'phone_number' => $billingData['phone_number'] ?? $billingData['phone'] ?? ($company['phone'] ?? '+96800000000'),
            'email' => $billingData['email'] ?? ($company['billing_email'] ?? $company['admin_email'] ?? 'customer@cardify.om'),
            'apartment' => 'N/A',
            'floor' => 'N/A',
            'street' => $billingData['street'] ?? ($company['address'] ?? 'N/A'),
            'building' => 'N/A',
            'shipping_method' => 'N/A',
            'postal_code' => $billingData['postal_code'] ?? ($company['postal_code'] ?? 'N/A'),
            'city' => $billingData['city'] ?? ($company['city'] ?? 'Muscat'),
            'country' => $billingData['country'] ?? ($company['country'] ?? 'OM'),
            'state' => $billingData['state'] ?? ($company['state'] ?? 'N/A')
        ];

        // Create payment record in DB
        $paymentId = generateUUID();
        $db->insert('payments', [
            'id' => $paymentId,
            'company_id' => $companyId,
            'type' => $type,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => $currency,
            'special_reference' => $specialReference,
            'status' => 'pending'
        ]);

        // Build Paymob intention payload
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
        $callbackUrl = $baseUrl . getBasePath() . 'paymob/callback.php';

        $payload = [
            'amount' => $amountCents,
            'currency' => strtoupper($currency),
            'payment_methods' => $paymentMethods,
            'billing_data' => $paymobBilling,
            'special_reference' => $specialReference,
            'expiration' => 3600,
            'notification_url' => $callbackUrl,
            'redirection_url' => $callbackUrl,
            'items' => []
        ];

        // Add item description based on type
        if ($type === 'subscription') {
            $payload['items'][] = [
                'name' => 'Cardify Subscription',
                'amount' => $amountCents,
                'description' => "Subscription plan for {$companyName}",
                'quantity' => 1
            ];
        } elseif ($type === 'print_order') {
            $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $referenceId]);
            $payload['items'][] = [
                'name' => 'Business Card Print Order',
                'amount' => $amountCents,
                'description' => ($order ? "Order #{$order['order_number']} - {$order['quantity']} cards" : "Print Order #{$referenceId}"),
                'quantity' => 1
            ];
        }

        // Call Paymob Intention API
        $ch = curl_init('https://oman.paymob.com/v1/intention/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $secretKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Paymob API curl error: " . $curlError);
            $db->update('payments', ['status' => 'failed', 'callback_data' => json_encode(['curl_error' => $curlError])], 'id = :id', ['id' => $paymentId]);
            return ['error' => 'Payment gateway connection failed'];
        }

        $responseData = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("Paymob API error [{$httpCode}]: " . $response);
            $db->update('payments', ['status' => 'failed', 'callback_data' => $response], 'id = :id', ['id' => $paymentId]);
            $errorMsg = $responseData['message'] ?? $responseData['detail'] ?? 'Payment gateway error';
            return ['error' => $errorMsg];
        }

        $clientSecret = $responseData['client_secret'] ?? null;
        if (empty($clientSecret)) {
            error_log("Paymob API: No client_secret: " . $response);
            $db->update('payments', ['status' => 'failed', 'callback_data' => $response], 'id = :id', ['id' => $paymentId]);
            return ['error' => 'Invalid gateway response'];
        }

        // Update payment with intention ID
        $intentionId = $responseData['id'] ?? null;
        if ($intentionId) {
            $db->update('payments', ['paymob_intention_id' => $intentionId], 'id = :id', ['id' => $paymentId]);
        }

        $checkoutUrl = 'https://oman.paymob.com/unifiedcheckout/?publicKey=' . urlencode($publicKey) . '&clientSecret=' . urlencode($clientSecret);

        // Store in session for callback matching
        $_SESSION["paymob_payment_{$specialReference}"] = [
            'payment_id' => $paymentId,
            'type' => $type,
            'reference_id' => $referenceId,
            'company_id' => $companyId,
            'amount' => $amountCents,
            'currency' => $currency
        ];

        return [
            'success' => true,
            'payment_id' => $paymentId,
            'checkout_url' => $checkoutUrl,
            'payment_url' => $checkoutUrl, // Alias for backward compat with Billing.php
            'special_reference' => $specialReference,
            'transaction_id' => $specialReference // Alias for backward compat
        ];
    }

    /**
     * Handle Paymob callback (both GET redirect and POST webhook)
     */
    public static function handleCallback(array $data, ?string $hmac = null): array {
        $db = Database::getInstance();

        // Flatten nested Paymob response (POST webhook has 'obj' wrapper)
        if (isset($data['obj'])) {
            $obj = $data['obj'];
            $data = array_merge($data, $obj);
            if (isset($obj['order'])) {
                $data['order'] = is_array($obj['order']) ? ($obj['order']['id'] ?? '') : $obj['order'];
            }
            if (isset($obj['source_data'])) {
                $data['source_data_pan'] = $obj['source_data']['pan'] ?? '';
                $data['source_data_sub_type'] = $obj['source_data']['sub_type'] ?? '';
                $data['source_data_type'] = $obj['source_data']['type'] ?? '';
            }
        }

        // Merge GET params if available
        if (!empty($_GET)) {
            $data = array_merge($data, $_GET);
        }

        $success = $data['success'] ?? null;
        $transactionId = $data['id'] ?? null;
        $orderId = $data['order'] ?? null;
        $merchantOrderId = $data['merchant_order_id'] ?? ($data['special_reference'] ?? null);
        $amountCents = $data['amount_cents'] ?? null;
        $currency = $data['currency'] ?? 'OMR';

        // Verify HMAC
        $receivedHmac = $hmac ?? ($data['hmac'] ?? null);
        if (!empty($receivedHmac)) {
            if (!self::verifyHmac($data, $receivedHmac)) {
                error_log("Payment callback: HMAC mismatch for transaction {$transactionId}");
                return ['success' => false, 'error' => 'Invalid HMAC signature'];
            }
        }

        if (empty($merchantOrderId)) {
            error_log("Payment callback: No merchant_order_id/special_reference");
            return ['success' => false, 'error' => 'Missing order reference'];
        }

        // Find payment in new payments table
        $payment = self::getBySpecialReference($merchantOrderId);

        // Fallback to session
        if (!$payment) {
            $sessionKey = "paymob_payment_{$merchantOrderId}";
            if (isset($_SESSION[$sessionKey])) {
                $sessionData = $_SESSION[$sessionKey];
                $payment = self::getById($sessionData['payment_id']);
            }
        }

        // If still not found, this might be an old subscription callback — delegate to legacy
        if (!$payment) {
            error_log("Payment callback: Not found in payments table for {$merchantOrderId}, checking legacy");
            return ['success' => false, 'error' => 'Payment not found', 'legacy' => true, 'merchant_order_id' => $merchantOrderId];
        }

        // Verify amount matches
        if ($amountCents !== null && $payment['amount'] !== null) {
            $expectedAmount = self::toSmallestUnit((float)$payment['amount'], $payment['currency'] ?? $currency);
            if ((int)$amountCents !== $expectedAmount) {
                error_log("Payment callback: Amount mismatch for {$merchantOrderId}. Expected: {$expectedAmount}, Got: {$amountCents}");
                return ['success' => false, 'error' => 'Amount mismatch'];
            }
        }

        // Determine status
        $isSuccess = ($success === 'true' || $success === true);
        $status = $isSuccess ? 'paid' : 'failed';
        $paymentMethod = $data['source_data_type'] ?? null;

        // Update payment record
        $db->update('payments',
            [
                'status' => $status,
                'paymob_order_id' => $orderId,
                'paymob_transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'callback_data' => json_encode($data)
            ],
            'id = :id',
            ['id' => $payment['id']]
        );

        // Clear session
        unset($_SESSION["paymob_payment_{$merchantOrderId}"]);

        // Dispatch based on type
        if ($isSuccess) {
            if ($payment['type'] === 'subscription') {
                self::activateSubscription($payment);
            } elseif ($payment['type'] === 'print_order') {
                self::confirmPrintOrder($payment);
            }
        }

        return [
            'success' => $isSuccess,
            'payment_id' => $payment['id'],
            'type' => $payment['type'],
            'reference_id' => $payment['reference_id'],
            'status' => $status
        ];
    }

    /**
     * Activate subscription after successful payment
     */
    private static function activateSubscription(array $payment): void {
        $db = Database::getInstance();
        $planId = $payment['reference_id'];
        $companyId = $payment['company_id'];

        // Default to monthly; check if yearly by looking at plan price
        $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $planId]);
        $isYearly = false;
        if ($plan && (float)$plan['price_yearly'] > 0 && abs((float)$payment['amount'] - (float)$plan['price_yearly']) < 0.01) {
            $isYearly = true;
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime($isYearly ? '+1 year' : '+1 month'));

        $db->update('companies',
            [
                'plan' => $planId,
                'subscription_status' => 'active',
                'subscription_expires_at' => $expiresAt,
                'subscription_id' => $payment['id']
            ],
            'id = :id',
            ['id' => $companyId]
        );
    }

    /**
     * Confirm print order after successful payment
     */
    private static function confirmPrintOrder(array $payment): void {
        $db = Database::getInstance();
        $orderId = $payment['reference_id'];

        $db->query(
            "UPDATE print_orders SET payment_status = 'paid', payment_method = 'online', payment_id = :pid, status = 'confirmed' WHERE id = :id",
            ['pid' => $payment['id'], 'id' => $orderId]
        );

        // Send notifications
        try {
            if (file_exists(INCLUDES_DIR . '/PrintShopIntegration.php')) {
                require_once INCLUDES_DIR . '/PrintShopIntegration.php';
                PrintShopIntegration::sendStatusUpdateEmail($orderId, 'confirmed', null);
            }
        } catch (Exception $e) {
            error_log("Payment notification failed for order {$orderId}: " . $e->getMessage());
        }
    }

    // --- Query methods ---

    public static function getById(string $paymentId): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM payments WHERE id = :id", ['id' => $paymentId]);
        return $row ?: null;
    }

    public static function getByCompany(string $companyId, ?string $type = null): array {
        $db = Database::getInstance();
        $sql = "SELECT * FROM payments WHERE company_id = :cid";
        $params = ['cid' => $companyId];
        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }
        $sql .= " ORDER BY created_at DESC";
        return $db->fetchAll($sql, $params);
    }

    public static function getBySpecialReference(string $ref): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM payments WHERE special_reference = :ref", ['ref' => $ref]);
        return $row ?: null;
    }
}
