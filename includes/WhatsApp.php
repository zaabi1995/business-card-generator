<?php
/**
 * WhatsApp REST API Integration
 * Sends confirmation messages via WhatsApp API
 */
class WhatsApp {
    private static $db = null;
    private static $apiToken = null;
    private static $enabled = false;
    
    public static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
            self::loadSettings();
        }
    }
    
    /**
     * Load WhatsApp settings from database
     */
    private static function loadSettings() {
        try {
            $tokenSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'whatsapp_api_token']
            );
            
            $enabledSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'whatsapp_enabled']
            );
            
            self::$apiToken = $tokenSetting['setting_value'] ?? '';
            self::$enabled = ($enabledSetting['setting_value'] ?? '0') === '1';
        } catch (Exception $e) {
            // Settings table might not exist yet
            self::$apiToken = '';
            self::$enabled = false;
        }
    }
    
    /**
     * Check if WhatsApp is enabled and configured
     */
    public static function isEnabled() {
        self::init();
        return self::$enabled && !empty(self::$apiToken);
    }
    
    /**
     * Send WhatsApp message
     * @param string $phoneNumber Phone number in international format (e.g., +96812345678)
     * @param string $message Message to send
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendMessage($phoneNumber, $message) {
        self::init();
        
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled or token is not configured'];
        }
        
        // Clean phone number (remove spaces, dashes, etc.)
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        // Ensure phone number starts with +
        if (substr($phoneNumber, 0, 1) !== '+') {
            $phoneNumber = '+' . $phoneNumber;
        }
        
        // WhatsApp REST API endpoint
        // Adjust this URL based on your actual WhatsApp API provider
        // Common providers: Twilio, MessageBird, WhatsApp Business API, etc.
        $apiUrl = 'https://api.whatsapp.com/v1/messages'; // Replace with your actual API endpoint
        
        $payload = [
            'to' => $phoneNumber,
            'message' => $message,
            'type' => 'text'
        ];
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::$apiToken
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'CURL Error: ' . $error];
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            $responseData = json_decode($response, true);
            $errorMsg = $responseData['error']['message'] ?? 'Unknown error';
            return ['success' => false, 'error' => 'API Error (' . $httpCode . '): ' . $errorMsg];
        }
        
        return ['success' => true, 'response' => json_decode($response, true)];
    }
    
    /**
     * Send print order confirmation message
     * @param array $order Print order data
     * @param array $company Company data
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendPrintOrderConfirmation($order, $company) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled'];
        }
        
        // Get company contact number (use admin email's phone or company phone)
        $phoneNumber = $company['phone'] ?? $company['mobile'] ?? null;
        
        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'Company phone number not found'];
        }
        
        // Build confirmation message - handle both old (employee_ids JSON) and new (employee_id single) schemas
        $employeeCount = 1;
        if (!empty($order['employee_ids'])) {
            $decoded = json_decode($order['employee_ids'], true);
            $employeeCount = is_array($decoded) ? count($decoded) : 1;
        }
        $totalQuantity = $employeeCount * ($order['quantity'] ?? 1);
        
        $message = "✅ Print Order Confirmation\n\n";
        $message .= "Order Number: " . $order['order_number'] . "\n";
        $message .= "Company: " . $company['name'] . "\n";
        $message .= "Employees: " . $employeeCount . "\n";
        $message .= "Quantity per Employee: " . ($order['quantity'] ?? 1) . "\n";
        $message .= "Total Cards: " . $totalQuantity . "\n";
        $message .= "Status: " . ucfirst($order['status']) . "\n";
        
        if (!empty($order['notes'])) {
            $message .= "\nNotes: " . $order['notes'];
        }
        
        $message .= "\n\nThank you for your order!";
        
        return self::sendMessage($phoneNumber, $message);
    }
    
    /**
     * Update WhatsApp API token
     */
    public static function updateToken($token) {
        self::init();
        
        try {
            $existing = self::$db->fetchOne(
                "SELECT id FROM system_settings WHERE setting_key = :key",
                ['key' => 'whatsapp_api_token']
            );
            
            if ($existing) {
                self::$db->update('system_settings', 
                    ['setting_value' => $token, 'updated_at' => date('Y-m-d H:i:s')],
                    'setting_key = :key',
                    ['key' => 'whatsapp_api_token']
                );
            } else {
                self::$db->insert('system_settings', [
                    'id' => generateUUID(),
                    'setting_key' => 'whatsapp_api_token',
                    'setting_value' => $token,
                    'description' => 'WhatsApp REST API token for sending confirmation messages'
                ]);
            }
            
            self::$apiToken = $token;
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update WhatsApp enabled status
     */
    public static function updateEnabled($enabled) {
        self::init();
        
        try {
            $value = $enabled ? '1' : '0';
            $existing = self::$db->fetchOne(
                "SELECT id FROM system_settings WHERE setting_key = :key",
                ['key' => 'whatsapp_enabled']
            );
            
            if ($existing) {
                self::$db->update('system_settings',
                    ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')],
                    'setting_key = :key',
                    ['key' => 'whatsapp_enabled']
                );
            } else {
                self::$db->insert('system_settings', [
                    'id' => generateUUID(),
                    'setting_key' => 'whatsapp_enabled',
                    'setting_value' => $value,
                    'description' => 'Enable/disable WhatsApp confirmation messages'
                ]);
            }
            
            self::$enabled = $enabled;
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get WhatsApp settings
     */
    public static function getSettings() {
        self::init();
        
        return [
            'token' => self::$apiToken,
            'enabled' => self::$enabled
        ];
    }
    
    /**
     * Send print order status update message
     * @param array $order Print order data
     * @param string $status New status
     * @param string|null $trackingNumber Tracking number (for shipped orders)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendPrintOrderStatusUpdate($order, $status, $trackingNumber = null) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled'];
        }
        
        // Get phone number from shipping phone or order data
        $phoneNumber = $order['shipping_phone'] ?? null;
        
        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'No phone number available for notification'];
        }
        
        // Build status message based on status
        $statusEmoji = '📦';
        $statusMessage = '';
        
        switch ($status) {
            case 'processing':
                $statusEmoji = '⚙️';
                $statusMessage = "Your print order is now being processed.";
                break;
            case 'printing':
                $statusEmoji = '🖨️';
                $statusMessage = "Your business cards are being printed!";
                break;
            case 'shipped':
                $statusEmoji = '🚚';
                $statusMessage = "Your order has been shipped!";
                if ($trackingNumber) {
                    $statusMessage .= "\nTracking: " . $trackingNumber;
                }
                break;
            case 'delivered':
                $statusEmoji = '✅';
                $statusMessage = "Your business cards have been delivered!";
                break;
            case 'cancelled':
                $statusEmoji = '❌';
                $statusMessage = "Your order has been cancelled. Please contact us if you have questions.";
                break;
            default:
                $statusMessage = "Your order status has been updated to: " . ucfirst($status);
        }
        
        $message = "{$statusEmoji} Order Update\n\n";
        $message .= "Order: #" . ($order['order_number'] ?? $order['id']) . "\n";
        $message .= "Status: " . ucfirst($status) . "\n";
        $message .= "Quantity: " . ($order['quantity'] ?? 100) . " cards\n\n";
        $message .= $statusMessage;
        
        if ($status === 'delivered') {
            $message .= "\n\nThank you for your order! 🙏";
        }
        
        return self::sendMessage($phoneNumber, $message);
    }
}
