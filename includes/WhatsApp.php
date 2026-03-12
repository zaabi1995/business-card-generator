<?php
/**
 * WhatsApp REST API Integration
 * Sends confirmation messages via Dardasha REST API
 */
class WhatsApp {
    private static $db = null;
    private static $settings = [];

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
            $keys = ['whatsapp_api_token', 'whatsapp_enabled', 'whatsapp_api_url', 'whatsapp_session_id'];
            self::$settings = [];
            foreach ($keys as $key) {
                $row = self::$db->fetchOne(
                    "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                    ['key' => $key]
                );
                if ($row !== false && $row !== null) {
                    self::$settings[$key] = $row['setting_value'];
                }
            }
        } catch (Exception $e) {
            // Settings table might not exist yet
            self::$settings = [];
        }
    }

    /**
     * Check if WhatsApp is enabled and configured
     */
    public static function isEnabled() {
        self::init();
        return (self::$settings['whatsapp_enabled'] ?? '0') === '1'
            && !empty(self::$settings['whatsapp_api_token']);
    }

    /**
     * Send WhatsApp message via Dardasha REST API
     * @param string $phoneNumber Phone number in any format (e.g., +96812345678 or 96812345678)
     * @param string $message Message to send
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendMessage($phoneNumber, $message) {
        self::init();

        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled or token is not configured'];
        }

        // Strip + and any non-digit characters
        $phone = preg_replace('/[^0-9]/', '', ltrim($phoneNumber, '+'));

        $apiUrl   = self::$settings['whatsapp_api_url']    ?? 'https://dardasha.om/api/send-message';
        $token    = self::$settings['whatsapp_api_token']  ?? '';
        $sessionId = self::$settings['whatsapp_session_id'] ?? 'anna';

        $payload = [
            'phone'     => $phone,
            'message'   => $message,
            'sessionId' => $sessionId
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'CURL Error: ' . $error];
        }

        $responseData = json_decode($response, true);

        if (isset($responseData['success']) && $responseData['success'] === true) {
            return ['success' => true, 'messageId' => $responseData['messageId'] ?? null];
        }

        $errorMsg = $responseData['error'] ?? $responseData['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'error' => 'API Error: ' . $errorMsg];
    }

    /**
     * Notify employee that their digital business card is ready
     * @param array $employee Employee data (must include phone/mobile)
     * @param array $company Company data
     * @param string $digitalCardUrl URL to the digital card
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendCardReadyNotification($employee, $company, $digitalCardUrl) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled'];
        }

        $phoneNumber = $employee['phone'] ?? $employee['mobile'] ?? null;

        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'Employee phone number not found'];
        }

        $name    = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
        $company_name = $company['name'] ?? '';

        $message  = "Hi {$name}! 👋\n\n";
        $message .= "Your business card is ready!\n\n";
        if ($company_name) {
            $message .= "Company: {$company_name}\n";
        }
        $message .= "View your digital card here:\n{$digitalCardUrl}";

        return self::sendMessage($phoneNumber, $message);
    }

    /**
     * Notify employee that their card request has been approved
     * @param array $employee Employee data (must include phone/mobile)
     * @param array $company Company data
     * @param string $digitalCardUrl URL to the digital card
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendCardRequestApproved($employee, $company, $digitalCardUrl) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp API is not enabled'];
        }

        $phoneNumber = $employee['phone'] ?? $employee['mobile'] ?? null;

        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'Employee phone number not found'];
        }

        $name         = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
        $company_name = $company['name'] ?? '';

        $message  = "Hi {$name}! 🎉\n\n";
        $message .= "Your card request has been approved!\n\n";
        if ($company_name) {
            $message .= "Company: {$company_name}\n";
        }
        $message .= "View your digital card here:\n{$digitalCardUrl}";

        return self::sendMessage($phoneNumber, $message);
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

        $phoneNumber = $company['phone'] ?? $company['mobile'] ?? null;

        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'Company phone number not found'];
        }

        // Handle both old (employee_ids JSON array) and new (employee_id single) schemas
        $employeeCount = 1;
        if (!empty($order['employee_ids'])) {
            $decoded = json_decode($order['employee_ids'], true);
            $employeeCount = is_array($decoded) ? count($decoded) : 1;
        }
        $totalQuantity = $employeeCount * ($order['quantity'] ?? 1);

        $message  = "✅ Print Order Confirmation\n\n";
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

        $phoneNumber = $order['shipping_phone'] ?? null;

        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'No phone number available for notification'];
        }

        $statusEmoji   = '📦';
        $statusMessage = '';

        switch ($status) {
            case 'processing':
                $statusEmoji   = '⚙️';
                $statusMessage = "Your print order is now being processed.";
                break;
            case 'printing':
                $statusEmoji   = '🖨️';
                $statusMessage = "Your business cards are being printed!";
                break;
            case 'shipped':
                $statusEmoji   = '🚚';
                $statusMessage = "Your order has been shipped!";
                if ($trackingNumber) {
                    $statusMessage .= "\nTracking: " . $trackingNumber;
                }
                break;
            case 'delivered':
                $statusEmoji   = '✅';
                $statusMessage = "Your business cards have been delivered!";
                break;
            case 'cancelled':
                $statusEmoji   = '❌';
                $statusMessage = "Your order has been cancelled. Please contact us if you have questions.";
                break;
            default:
                $statusMessage = "Your order status has been updated to: " . ucfirst($status);
        }

        $message  = "{$statusEmoji} Order Update\n\n";
        $message .= "Order: #" . ($order['order_number'] ?? $order['id']) . "\n";
        $message .= "Status: " . ucfirst($status) . "\n";
        $message .= "Quantity: " . ($order['quantity'] ?? 100) . " cards\n\n";
        $message .= $statusMessage;

        if ($status === 'delivered') {
            $message .= "\n\nThank you for your order! 🙏";
        }

        return self::sendMessage($phoneNumber, $message);
    }

    /**
     * Update WhatsApp API token
     */
    public static function updateToken($token) {
        self::init();
        return self::upsertSetting('whatsapp_api_token', $token, 'WhatsApp REST API token for sending messages');
    }

    /**
     * Update WhatsApp API URL
     */
    public static function updateApiUrl($url) {
        self::init();
        return self::upsertSetting('whatsapp_api_url', $url, 'Dardasha REST API endpoint URL');
    }

    /**
     * Update WhatsApp session ID
     */
    public static function updateSessionId($sessionId) {
        self::init();
        return self::upsertSetting('whatsapp_session_id', $sessionId, 'Dardasha session/line ID (e.g. anna)');
    }

    /**
     * Update WhatsApp enabled status
     */
    public static function updateEnabled($enabled) {
        self::init();
        $result = self::upsertSetting('whatsapp_enabled', $enabled ? '1' : '0', 'Enable/disable WhatsApp confirmation messages');
        if ($result['success']) {
            self::$settings['whatsapp_enabled'] = $enabled ? '1' : '0';
        }
        return $result;
    }

    /**
     * Get WhatsApp settings
     */
    public static function getSettings() {
        self::init();

        return [
            'token'      => self::$settings['whatsapp_api_token']    ?? '',
            'enabled'    => (self::$settings['whatsapp_enabled']      ?? '0') === '1',
            'api_url'    => self::$settings['whatsapp_api_url']       ?? 'https://dardasha.om/api/send-message',
            'session_id' => self::$settings['whatsapp_session_id']    ?? 'anna'
        ];
    }

    /**
     * Insert or update a setting in system_settings
     */
    private static function upsertSetting($key, $value, $description = '') {
        try {
            $existing = self::$db->fetchOne(
                "SELECT id FROM system_settings WHERE setting_key = :key",
                ['key' => $key]
            );

            if ($existing) {
                self::$db->update(
                    'system_settings',
                    ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')],
                    'setting_key = :key',
                    ['key' => $key]
                );
            } else {
                self::$db->insert('system_settings', [
                    'id'            => generateUUID(),
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'description'   => $description
                ]);
            }

            self::$settings[$key] = $value;
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
