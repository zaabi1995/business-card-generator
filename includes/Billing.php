<?php
/**
 * Billing Integration
 * Supports Amwal Pay and other payment gateways
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
     */
    private function createAmwalPaymentIntent($amount, $companyId, $planId, $billingCycle) {
        $apiKey = $this->config['api_key'] ?? '';
        $merchantId = $this->config['merchant_id'] ?? '';
        $apiUrl = $this->config['api_url'] ?? 'https://api.amwal.com/v1';
        
        if (empty($apiKey) || empty($merchantId)) {
            return ['success' => false, 'error' => 'Amwal Pay credentials not configured'];
        }
        
        // Prepare payment data
        $paymentData = [
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'currency' => 'USD',
            'order_id' => 'sub_' . $companyId . '_' . time(),
            'customer_id' => $companyId,
            'description' => "Subscription: {$planId} ({$billingCycle})",
            'callback_url' => $this->config['callback_url'] ?? '',
            'return_url' => $this->config['return_url'] ?? ''
        ];
        
        // Make API request to Amwal Pay
        $ch = curl_init($apiUrl . '/payments/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($paymentData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Amwal Pay API error: ' . $response];
        }
        
        $result = json_decode($response, true);
        
        return [
            'success' => true,
            'transaction_id' => $result['transaction_id'] ?? null,
            'payment_url' => $result['payment_url'] ?? null,
            'gateway_response' => $result
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
     * Handle payment webhook
     */
    public function handleWebhook($payload, $signature) {
        switch ($this->gateway) {
            case 'amwal':
                return $this->handleAmwalWebhook($payload, $signature);
            case 'stripe':
                return $this->handleStripeWebhook($payload, $signature);
            default:
                return ['success' => false, 'error' => 'Unsupported gateway'];
        }
    }
    
    /**
     * Handle Amwal Pay webhook
     */
    private function handleAmwalWebhook($payload, $signature) {
        $db = Database::getInstance();
        
        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payload, $this->config['webhook_secret'] ?? '');
        if ($signature !== $expectedSignature) {
            return ['success' => false, 'error' => 'Invalid signature'];
        }
        
        $data = json_decode($payload, true);
        
        if ($data['status'] === 'success' || $data['status'] === 'completed') {
            // Update transaction
            $db->update('payment_transactions', 
                ['status' => 'completed'],
                'transaction_id = :tid',
                ['tid' => $data['transaction_id']]
            );
            
            // Update company subscription
            $transaction = $db->fetchOne(
                "SELECT * FROM payment_transactions WHERE transaction_id = :tid",
                ['tid' => $data['transaction_id']]
            );
            
            if ($transaction) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 ' . ($transaction['payment_method'] === 'yearly' ? 'year' : 'month')));
                
                $db->update('companies',
                    [
                        'plan' => $transaction['plan_id'],
                        'subscription_status' => 'active',
                        'subscription_expires_at' => $expiresAt
                    ],
                    'id = :id',
                    ['id' => $transaction['company_id']]
                );
            }
        }
        
        return ['success' => true];
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
