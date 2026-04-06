<?php
/**
 * Billing Integration
 * Supports Paymob (Unified Checkout), Amwal Pay (legacy), and Stripe
 */
class Billing {
    private $gateway = 'paymob';
    private $config = [];

    public function __construct($gateway = 'paymob', $config = []) {
        $this->gateway = $gateway;
        $this->config = $config;
    }
    
    /**
     * Create a subscription
     */
    public function createSubscription($companyId, $planId, $billingCycle = 'monthly') {
        $db = Database::getInstance();

        // Get plan details
        $plan = $db->fetchOne(
            "SELECT * FROM subscription_plans WHERE id = :id AND is_active = 1",
            ['id' => $planId]
        );

        if (!$plan) {
            return ['success' => false, 'error' => 'Plan not found'];
        }

        // Get company currency and check plan_prices table for correct price
        $company = $db->fetchOne("SELECT currency FROM companies WHERE id = :id", ['id' => $companyId]);
        $currency = $company['currency'] ?? 'OMR';

        $amount = $billingCycle === 'yearly' ? (float)$plan['price_yearly'] : (float)$plan['price_monthly'];

        // Override with per-currency price if available
        try {
            $priceRow = $db->fetchOne(
                "SELECT * FROM plan_prices WHERE plan_id = :pid AND currency = :cur",
                ['pid' => $planId, 'cur' => $currency]
            );
            if ($priceRow) {
                $amount = $billingCycle === 'yearly'
                    ? (float)$priceRow['price_yearly']
                    : (float)$priceRow['price_monthly'];
            }
        } catch (\Throwable $e) {
            // plan_prices may not exist — use subscription_plans fallback
        }

        // Don't send $0 plans to Paymob — activate directly
        if ($amount <= 0) {
            $interval = $billingCycle === 'yearly' ? '+1 year' : '+1 month';
            $db->update('companies', [
                'plan' => $planId,
                'subscription_status' => 'active',
                'subscription_expires_at' => date('Y-m-d H:i:s', strtotime($interval)),
                'subscription_id' => 'free_' . time()
            ], 'id = :id', ['id' => $companyId]);
            return ['success' => true, 'free' => true, 'payment_url' => null, 'amount' => 0];
        }
        
        // Create payment intent with gateway
        $paymentResult = $this->createPaymentIntent($amount, $companyId, $planId, $billingCycle);
        
        if (!$paymentResult['success']) {
            return $paymentResult;
        }

        // Store transaction
        $transactionId = $this->generateUUID();
        $db->insert('payment_transactions', [
            'id' => $transactionId,
            'company_id' => $companyId,
            'plan_id' => $planId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => $billingCycle,
            'transaction_id' => $paymentResult['transaction_id'] ?? null,
            'status' => 'pending',
            'payment_gateway' => $this->gateway,
            'gateway_response' => json_encode($paymentResult)
        ]);
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'payment_url' => $paymentResult['payment_url'] ?? null,
            'payment_data' => $paymentResult['payment_data'] ?? null,
            'amount' => $amount
        ];
    }
    
    /**
     * Create payment intent (gateway-specific)
     */
    private function createPaymentIntent($amount, $companyId, $planId, $billingCycle) {
        switch ($this->gateway) {
            case 'paymob':
                return $this->createPaymobPaymentIntent($amount, $companyId, $planId, $billingCycle);
            case 'amwal':
                return $this->createAmwalPaymentIntent($amount, $companyId, $planId, $billingCycle);
            case 'stripe':
                return $this->createStripePaymentIntent($amount, $companyId, $planId, $billingCycle);
            default:
                return ['success' => false, 'error' => 'Unsupported gateway'];
        }
    }
    
    /**
     * Convert amount to smallest currency unit for Paymob
     * OMR has 3 decimal places (1 OMR = 1000 baisa)
     * USD/EUR/GBP have 2 decimal places (1 USD = 100 cents)
     */
    private function toSmallestUnit($amount, $currency) {
        $currency = strtoupper($currency);
        // OMR, BHD, KWD use 3 decimal places
        $threeDecimal = ['OMR', 'BHD', 'KWD'];
        $multiplier = in_array($currency, $threeDecimal) ? 1000 : 100;
        return (int) round($amount * $multiplier);
    }

    /**
     * Paymob Unified Checkout — delegates to Payment.php
     */
    private function createPaymobPaymentIntent($amount, $companyId, $planId, $billingCycle) {
        require_once __DIR__ . '/Payment.php';

        $db = Database::getInstance();
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        $currency = $company['currency'] ?? 'OMR';

        // Pass billing_cycle explicitly so activateSubscription() doesn't need to guess
        $cycle = in_array($billingCycle, ['monthly', 'yearly']) ? $billingCycle : 'monthly';
        $result = Payment::createIntent('subscription', $planId, $amount, $companyId, [], $currency, $cycle);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        return $result;
    }

    /**
     * Amwal Pay integration (legacy)
     * Based on official Amwal Pay API documentation
     */
    private function createAmwalPaymentIntent($amount, $companyId, $planId, $billingCycle) {
        $merchantId = $this->config['merchant_id'] ?? '';
        $terminalId = $this->config['terminal_id'] ?? '';
        $secureKey = $this->config['secure_key'] ?? '';
        $apiUrl = $this->config['api_url'] ?? 'https://backend.sa.amwal.tech';
        
        if (empty($merchantId) || empty($terminalId) || empty($secureKey)) {
            return ['success' => false, 'error' => 'Amwal Pay credentials not configured. Please set Merchant ID, Terminal ID, and Secure Key.'];
        }
        
        // Generate order ID
        $orderId = 'SUB_' . $companyId . '_' . time();
        
        // Prepare payment data according to Amwal Pay API
        $paymentData = [
            'MerchantId' => $merchantId,
            'TerminalId' => $terminalId,
            'Amount' => number_format($amount, 2, '.', ''),
            'Currency' => 'USD',
            'OrderId' => $orderId,
            'CustomerId' => (string)$companyId,
            'Description' => "Subscription: {$planId} ({$billingCycle})",
            'CallbackUrl' => $this->config['callback_url'] ?? '',
            'ReturnUrl' => $this->config['return_url'] ?? ''
        ];
        
        // Generate signature using Secure Key
        $signatureString = $merchantId . $terminalId . $orderId . $paymentData['Amount'] . $paymentData['Currency'] . $secureKey;
        $signature = hash('sha256', $signatureString);
        $paymentData['Signature'] = $signature;
        
        // Store payment data for process endpoint
        $_SESSION['amwal_payment_' . $orderId] = [
            'company_id' => $companyId,
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
            'order_id' => $orderId,
            'payment_data' => $paymentData
        ];
        
        // Return payment data to be submitted via form to Amwal Pay
        return [
            'success' => true,
            'transaction_id' => $orderId,
            'payment_url' => $apiUrl . '/payment/process',
            'payment_data' => $paymentData,
            'form_action' => $apiUrl . '/payment/process'
        ];
    }
    
    /**
     * Stripe integration (alternative)
     */
    private function createStripePaymentIntent($amount, $companyId, $planId, $billingCycle) {
        $apiKey = $this->config['secret_key'] ?? '';
        
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Stripe credentials not configured'];
        }
        
        // Stripe integration would go here
        // For now, return placeholder
        return ['success' => false, 'error' => 'Stripe integration not implemented'];
    }
    
    /**
     * Handle payment webhook/callback
     */
    public function handleWebhook($payload, $signature) {
        switch ($this->gateway) {
            case 'paymob':
                return $this->handlePaymobCallback($payload, $signature);
            case 'amwal':
                return $this->handleAmwalCallback($payload, $signature);
            case 'stripe':
                return $this->handleStripeWebhook($payload, $signature);
            default:
                return ['success' => false, 'error' => 'Unsupported gateway'];
        }
    }
    
    /**
     * Handle Paymob callback — delegates to Payment.php
     * Falls back to legacy payment_transactions for old subscriptions
     */
    public function handlePaymobCallback($data, $hmac = null) {
        require_once __DIR__ . '/Payment.php';

        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }

        $result = Payment::handleCallback($data, $hmac);

        // If Payment.php didn't find the record, fall back to legacy payment_transactions
        if (!$result['success'] && !empty($result['legacy'])) {
            return $this->handleLegacyPaymobCallback($data, $hmac);
        }

        // Map new status format to old for backward compat
        if ($result['success'] && ($result['status'] ?? '') === 'paid') {
            $result['status'] = 'completed';
        }

        return $result;
    }

    /**
     * Legacy Paymob callback handler for old payment_transactions records
     */
    public function handleLegacyPaymobCallback($data, $hmac = null) {
        // Verify HMAC — required even for legacy records
        $receivedHmac = $hmac ?? ($data['hmac'] ?? null);
        if (empty($receivedHmac)) {
            error_log("Legacy payment callback: Missing HMAC");
            return ['success' => false, 'error' => 'Missing HMAC signature'];
        }
        require_once __DIR__ . '/Payment.php';
        if (!Payment::verifyHmac($data, $receivedHmac)) {
            error_log("Legacy payment callback: HMAC mismatch");
            return ['success' => false, 'error' => 'Invalid HMAC signature'];
        }

        $db = Database::getInstance();

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

        $merchantOrderId = $data['merchant_order_id'] ?? ($data['special_reference'] ?? null);
        $success = $data['success'] ?? null;

        $transaction = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE transaction_id = :tid",
            ['tid' => $merchantOrderId]
        );

        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found'];
        }

        // Idempotency: already completed
        if ($transaction['status'] === 'completed') {
            return ['success' => true, 'status' => 'completed', 'transaction_id' => $transaction['id'], 'idempotent' => true];
        }

        $transactionStatus = ($success === 'true' || $success === true) ? 'completed' : 'failed';

        $db->update('payment_transactions',
            ['status' => $transactionStatus, 'gateway_response' => json_encode($data)],
            'id = :id', ['id' => $transaction['id']]
        );

        if ($transactionStatus === 'completed') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 ' . ($transaction['payment_method'] === 'yearly' ? 'year' : 'month')));
            $db->update('companies',
                [
                    'plan' => $transaction['plan_id'],
                    'subscription_status' => 'active',
                    'subscription_expires_at' => $expiresAt,
                    'subscription_id' => $merchantOrderId
                ],
                'id = :id', ['id' => $transaction['company_id']]
            );
            unset($_SESSION['paymob_payment_' . $merchantOrderId]);
        }

        return ['success' => true, 'status' => $transactionStatus, 'transaction_id' => $transaction['id']];
    }

    /**
     * Handle Amwal Pay callback (legacy)
     * Amwal Pay sends payment status via callback URL
     */
    private function handleAmwalCallback($data, $signature = null) {
        $db = Database::getInstance();
        
        // If data is JSON string, decode it
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        // If data is from POST, use $_POST
        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }
        
        if (empty($data)) {
            return ['success' => false, 'error' => 'No callback data received'];
        }
        
        // Extract callback data
        $orderId = $data['OrderId'] ?? $data['order_id'] ?? null;
        $status = $data['Status'] ?? $data['status'] ?? null;
        $transactionId = $data['TransactionId'] ?? $data['transaction_id'] ?? $orderId;
        $amount = $data['Amount'] ?? $data['amount'] ?? null;
        
        if (empty($orderId)) {
            return ['success' => false, 'error' => 'Order ID missing in callback'];
        }
        
        // Verify signature if secure key is configured (required for production)
        if (!empty($this->config['secure_key'])) {
            if (empty($signature)) {
                error_log('Payment callback: Missing signature for order ' . $orderId);
                return ['success' => false, 'error' => 'Signature required'];
            }
            $expectedSignature = $this->generateAmwalSignature($data, $this->config['secure_key']);
            if (!hash_equals($expectedSignature, $signature)) {
                error_log('Payment callback: Invalid signature for order ' . $orderId);
                return ['success' => false, 'error' => 'Invalid signature'];
            }
        }
        
        // Find transaction by order_id (stored in transaction_id field)
        // Escape LIKE wildcards in orderId to prevent injection
        $escapedOrderId = str_replace(['%', '_'], ['\\%', '\\_'], $orderId);
        $transaction = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE transaction_id = :tid OR gateway_response LIKE :orderId",
            [
                'tid' => $transactionId,
                'orderId' => '%' . $escapedOrderId . '%'
            ]
        );
        
        if (!$transaction) {
            // Try to find by order ID in session
            $sessionKey = 'amwal_payment_' . $orderId;
            if (isset($_SESSION[$sessionKey])) {
                $sessionData = $_SESSION[$sessionKey];
                
                // Create transaction record
                $transactionId = $this->generateUUID();
                $db->insert('payment_transactions', [
                    'id' => $transactionId,
                    'company_id' => $sessionData['company_id'],
                    'plan_id' => $sessionData['plan_id'],
                    'amount' => $sessionData['amount'],
                    'currency' => 'USD',
                    'payment_method' => $sessionData['billing_cycle'],
                    'transaction_id' => $orderId,
                    'status' => 'pending',
                    'payment_gateway' => 'amwal',
                    'gateway_response' => json_encode($data)
                ]);
                
                $transaction = $db->fetchOne(
                    "SELECT * FROM payment_transactions WHERE id = :id",
                    ['id' => $transactionId]
                );
            }
        }
        
        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found'];
        }
        
        // Verify payment amount matches stored transaction amount
        if ($amount !== null && $transaction['amount'] !== null) {
            $callbackAmount = number_format((float)$amount, 2, '.', '');
            $storedAmount = number_format((float)$transaction['amount'], 2, '.', '');
            if ($callbackAmount !== $storedAmount) {
                error_log("Payment callback: Amount mismatch for order {$orderId}. Expected: {$storedAmount}, Got: {$callbackAmount}");
                return ['success' => false, 'error' => 'Payment amount mismatch'];
            }
        }

        // Update transaction status
        $transactionStatus = 'pending';
        if ($status === 'Success' || $status === 'success' || $status === 'Completed' || $status === 'completed') {
            $transactionStatus = 'completed';
        } elseif ($status === 'Failed' || $status === 'failed' || $status === 'Cancelled' || $status === 'cancelled') {
            $transactionStatus = 'failed';
        }

        $db->update('payment_transactions',
            [
                'status' => $transactionStatus,
                'gateway_response' => json_encode($data)
            ],
            'id = :id',
            ['id' => $transaction['id']]
        );

        // If payment successful, update company subscription
        if ($transactionStatus === 'completed') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 ' . ($transaction['payment_method'] === 'yearly' ? 'year' : 'month')));
            
            $db->update('companies',
                [
                    'plan' => $transaction['plan_id'],
                    'subscription_status' => 'active',
                    'subscription_expires_at' => $expiresAt,
                    'subscription_id' => $orderId
                ],
                'id = :id',
                ['id' => $transaction['company_id']]
            );
            
            // Clear session
            unset($_SESSION['amwal_payment_' . $orderId]);
        }
        
        return [
            'success' => true,
            'status' => $transactionStatus,
            'transaction_id' => $transaction['id']
        ];
    }
    
    /**
     * Generate Amwal Pay signature for verification
     */
    private function generateAmwalSignature($data, $secureKey) {
        $merchantId = $data['MerchantId'] ?? $data['merchant_id'] ?? '';
        $terminalId = $data['TerminalId'] ?? $data['terminal_id'] ?? '';
        $orderId = $data['OrderId'] ?? $data['order_id'] ?? '';
        $amount = $data['Amount'] ?? $data['amount'] ?? '';
        $currency = $data['Currency'] ?? $data['currency'] ?? 'USD';
        
        $signatureString = $merchantId . $terminalId . $orderId . $amount . $currency . $secureKey;
        return hash('sha256', $signatureString);
    }
    
    /**
     * Handle Stripe webhook
     */
    private function handleStripeWebhook($payload, $signature) {
        // Stripe webhook handling would go here
        return ['success' => false, 'error' => 'Stripe webhook not implemented'];
    }
    
    /**
     * Check if company has active subscription
     */
    public function hasActiveSubscription($companyId) {
        $db = Database::getInstance();
        $company = $db->fetchOne(
            "SELECT subscription_status, subscription_expires_at FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        
        if (!$company) {
            return false;
        }
        
        if ($company['subscription_status'] !== 'active') {
            return false;
        }
        
        if ($company['subscription_expires_at'] && strtotime($company['subscription_expires_at']) < time()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get company plan limits
     */
    public function getPlanLimits($companyId) {
        $db = Database::getInstance();
        
        // Default limits for free plan
        $defaultLimits = [
            'employees' => 5,
            'templates' => 5,
            'storage' => 1
        ];
        
        try {
            $company = $db->fetchOne(
                "SELECT plan FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            
            if (!$company) {
                return $defaultLimits;
            }
            
            $plan = $db->fetchOne(
                "SELECT * FROM subscription_plans WHERE id = :id",
                ['id' => $company['plan'] ?? 'free']
            );
            
            if (!$plan) {
                return $defaultLimits;
            }
            
            // Try to get limits from JSON column first, then from individual columns
            if (!empty($plan['limits'])) {
                $limits = json_decode($plan['limits'], true);
                if ($limits) {
                    return [
                        'employees' => $limits['employees'] ?? $limits['max_employees'] ?? $defaultLimits['employees'],
                        'templates' => $limits['templates'] ?? $limits['max_templates'] ?? $defaultLimits['templates'],
                        'storage' => $limits['storage'] ?? $limits['max_storage_mb'] ?? $defaultLimits['storage']
                    ];
                }
            }
            
            // Fallback to individual columns
            return [
                'employees' => $plan['max_employees'] ?? $defaultLimits['employees'],
                'templates' => $plan['max_templates'] ?? $defaultLimits['templates'],
                'storage' => $plan['max_storage_mb'] ?? $defaultLimits['storage']
            ];
            
        } catch (Throwable $e) {
            error_log("Billing::getPlanLimits error: " . $e->getMessage());
            return $defaultLimits;
        }
    }
    
    /**
     * Check if company can perform action (plan limits)
     */
    public function checkLimit($companyId, $limitType) {
        $limits = $this->getPlanLimits($companyId);
        if (!$limits) {
            return false; // Fail closed — deny if limits can't be verified
        }
        
        // Map limit type to key
        $limitKey = $limitType;
        if ($limitType === 'max_employees') $limitKey = 'employees';
        if ($limitType === 'max_templates') $limitKey = 'templates';
        
        $limit = $limits[$limitKey] ?? -1;
        if ($limit === -1) {
            return true; // Unlimited
        }
        
        $db = Database::getInstance();
        
        try {
            switch ($limitType) {
                case 'max_employees':
                case 'employees':
                    $count = $db->fetchOne(
                        "SELECT COUNT(*) as count FROM employees WHERE company_id = :id",
                        ['id' => $companyId]
                    );
                    return ($count['count'] ?? 0) < $limit;
                    
                case 'max_templates':
                case 'templates':
                    $count = $db->fetchOne(
                        "SELECT COUNT(*) as count FROM templates WHERE company_id = :id",
                        ['id' => $companyId]
                    );
                    return ($count['count'] ?? 0) < $limit;
                    
                default:
                    return true;
            }
        } catch (Throwable $e) {
            error_log("Billing::checkLimit error: " . $e->getMessage());
            return true; // Allow if we can't check
        }
    }
    
    private function generateUUID() {
        // Delegate to the global helper which uses cryptographically secure random_bytes()
        return \generateUUID();
    }
    
    /**
     * Get quality multiplier based on plan
     * Free users get 1x (~70-100 DPI), paid users get 4x (~400 DPI)
     * 
     * @param string $companyId Company ID
     * @param bool $isPrintShop Whether this is for a print shop (always full quality)
     * @return int Multiplier for image quality
     */
    public static function getQualityMultiplier($companyId, $isPrintShop = false) {
        // Print shops always get full quality
        if ($isPrintShop) {
            return 4;
        }
        
        $billing = new self();
        
        // Check if company has active paid subscription
        if ($billing->hasActiveSubscription($companyId)) {
            return 4; // ~400 DPI for paid users
        }
        
        // Check plan type
        $db = Database::getInstance();
        try {
            $company = $db->fetchOne(
                "SELECT plan FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            
            $plan = $company['plan'] ?? 'free';
            
            // Any non-free plan gets full quality
            if ($plan !== 'free') {
                return 4;
            }
        } catch (Throwable $e) {
            error_log("Billing::getQualityMultiplier error: " . $e->getMessage());
        }
        
        // Free users get lower quality (1x = ~100 DPI base, appears as ~70 DPI in print)
        return 1;
    }
    
    /**
     * Check if QR tracking/analytics is enabled for company
     * Free users don't have access to QR scan analytics
     * 
     * @param string $companyId Company ID
     * @return bool Whether QR tracking analytics is enabled
     */
    public static function isQRTrackingEnabled($companyId) {
        $billing = new self();
        
        // Paid users get QR tracking analytics
        if ($billing->hasActiveSubscription($companyId)) {
            return true;
        }
        
        // Check plan type
        $db = Database::getInstance();
        try {
            $company = $db->fetchOne(
                "SELECT plan FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            
            $plan = $company['plan'] ?? 'free';
            
            // Free users don't get QR tracking analytics
            return $plan !== 'free';
        } catch (Throwable $e) {
            error_log("Billing::isQRTrackingEnabled error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a specific feature is available for company
     * 
     * @param string $companyId Company ID
     * @param string $feature Feature name: 'high_quality', 'qr_tracking', 'bulk_generation', 'api_access', 'custom_branding'
     * @return bool Whether the feature is available
     */
    public static function hasFeature($companyId, $feature) {
        $billing = new self();
        $isPaid = $billing->hasActiveSubscription($companyId);
        
        // Check plan type
        $db = Database::getInstance();
        $plan = 'free';
        try {
            $company = $db->fetchOne(
                "SELECT plan FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            $plan = $company['plan'] ?? 'free';
        } catch (Throwable $e) {
            error_log("Billing::hasFeature error: " . $e->getMessage());
        }
        
        // Feature availability matrix
        $features = [
            'free' => [
                'high_quality' => false,
                'qr_tracking' => false,
                'bulk_generation' => false,
                'api_access' => false,
                'custom_branding' => false,
                'proof_sheets' => true, // Allow proof sheets for all
                'basic_generation' => true,
                'portal_access' => true
            ],
            'starter' => [
                'high_quality' => true,
                'qr_tracking' => true,
                'bulk_generation' => true,
                'api_access' => false,
                'custom_branding' => false,
                'proof_sheets' => true,
                'basic_generation' => true,
                'portal_access' => true
            ],
            'professional' => [
                'high_quality' => true,
                'qr_tracking' => true,
                'bulk_generation' => true,
                'api_access' => true,
                'custom_branding' => true,
                'proof_sheets' => true,
                'basic_generation' => true,
                'portal_access' => true
            ],
            'enterprise' => [
                'high_quality' => true,
                'qr_tracking' => true,
                'bulk_generation' => true,
                'api_access' => true,
                'custom_branding' => true,
                'proof_sheets' => true,
                'basic_generation' => true,
                'portal_access' => true
            ]
        ];
        
        // Default to free plan features if plan not found
        $planFeatures = $features[$plan] ?? $features['free'];
        
        // If paid subscription active, grant all features
        if ($isPaid) {
            return true;
        }
        
        return $planFeatures[$feature] ?? false;
    }
    
    /**
     * Get company plan info with features
     * 
     * @param string $companyId Company ID
     * @return array Plan info with features
     */
    public static function getCompanyPlanInfo($companyId) {
        $billing = new self();
        $db = Database::getInstance();
        
        $info = [
            'plan' => 'free',
            'plan_name' => 'Free',
            'is_paid' => false,
            'subscription_status' => null,
            'expires_at' => null,
            'quality_multiplier' => 1,
            'features' => []
        ];
        
        try {
            $company = $db->fetchOne(
                "SELECT plan, subscription_status, subscription_expires_at FROM companies WHERE id = :id",
                ['id' => $companyId]
            );
            
            if ($company) {
                $info['plan'] = $company['plan'] ?? 'free';
                $info['subscription_status'] = $company['subscription_status'];
                $info['expires_at'] = $company['subscription_expires_at'];
                
                // Get plan name
                $planDetails = $db->fetchOne(
                    "SELECT name FROM subscription_plans WHERE id = :id",
                    ['id' => $info['plan']]
                );
                $info['plan_name'] = $planDetails['name'] ?? ucfirst($info['plan']);
            }
            
            $info['is_paid'] = $billing->hasActiveSubscription($companyId);
            $info['quality_multiplier'] = self::getQualityMultiplier($companyId);
            
            // Add feature flags
            $featureList = ['high_quality', 'qr_tracking', 'bulk_generation', 'api_access', 'custom_branding', 'proof_sheets'];
            foreach ($featureList as $feature) {
                $info['features'][$feature] = self::hasFeature($companyId, $feature);
            }
            
        } catch (Throwable $e) {
            error_log("Billing::getCompanyPlanInfo error: " . $e->getMessage());
        }
        
        return $info;
    }
}
