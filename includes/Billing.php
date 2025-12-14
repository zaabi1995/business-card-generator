<?php
/**
 * Billing Integration
 * Supports Amwal Pay and other payment gateways
 * Based on Amwal Pay official API: https://backend.sa.amwal.tech
 */
class Billing {
    private $gateway = 'amwal';
    private $config = [];
    
    public function __construct($gateway = 'amwal', $config = []) {
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
        
        $amount = $billingCycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
        
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
            'currency' => 'USD',
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
            case 'amwal':
                return $this->createAmwalPaymentIntent($amount, $companyId, $planId, $billingCycle);
            case 'stripe':
                return $this->createStripePaymentIntent($amount, $companyId, $planId, $billingCycle);
            default:
                return ['success' => false, 'error' => 'Unsupported gateway'];
        }
    }
    
    /**
     * Amwal Pay integration
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
            case 'amwal':
                return $this->handleAmwalCallback($payload, $signature);
            case 'stripe':
                return $this->handleStripeWebhook($payload, $signature);
            default:
                return ['success' => false, 'error' => 'Unsupported gateway'];
        }
    }
    
    /**
     * Handle Amwal Pay callback
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
        
        // Verify signature if provided
        if (!empty($signature) && !empty($this->config['secure_key'])) {
            $expectedSignature = $this->generateAmwalSignature($data, $this->config['secure_key']);
            if ($signature !== $expectedSignature) {
                return ['success' => false, 'error' => 'Invalid signature'];
            }
        }
        
        // Find transaction by order_id (stored in transaction_id field)
        $transaction = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE transaction_id = :tid OR gateway_response LIKE :orderId",
            [
                'tid' => $transactionId,
                'orderId' => '%' . $orderId . '%'
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
        $company = $db->fetchOne(
            "SELECT plan FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        
        if (!$company) {
            return null;
        }
        
        $plan = $db->fetchOne(
            "SELECT * FROM subscription_plans WHERE id = :id",
            ['id' => $company['plan'] ?? 'free']
        );
        
        return $plan ? [
            'max_employees' => $plan['max_employees'],
            'max_templates' => $plan['max_templates'],
            'max_storage_mb' => $plan['max_storage_mb']
        ] : null;
    }
    
    /**
     * Check if company can perform action (plan limits)
     */
    public function checkLimit($companyId, $limitType) {
        $limits = $this->getPlanLimits($companyId);
        if (!$limits) {
            return false;
        }
        
        $limit = $limits[$limitType] ?? -1;
        if ($limit === -1) {
            return true; // Unlimited
        }
        
        $db = Database::getInstance();
        
        switch ($limitType) {
            case 'max_employees':
                $count = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM employees WHERE company_id = :id",
                    ['id' => $companyId]
                );
                return ($count['count'] ?? 0) < $limit;
                
            case 'max_templates':
                $count = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM templates WHERE company_id = :id",
                    ['id' => $companyId]
                );
                return ($count['count'] ?? 0) < $limit;
                
            default:
                return true;
        }
    }
    
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
