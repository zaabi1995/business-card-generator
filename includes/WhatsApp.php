<?php
/**
 * WhatsApp REST API Integration
 * Sends messages via Dardasha REST API using config.php constants.
 *
 * Config in config.php:
 *   DARDASHA_API_URL — Dardasha send endpoint
 *   DARDASHA_TOKEN   — superadmin JWT for cardify.om's business_id
 *   DARDASHA_FROM    — BHD line number (96897707134 as of 2026-04-10)
 *
 * Fallback: if constants aren't set, falls back to system_settings table
 * (legacy behavior — kept so existing setups don't break during migration).
 */
class WhatsApp {
    private static $db = null;
    private static $settings = [];
    private static $useConstants = null;

    public static function init() {
        if (self::$useConstants !== null) return;
        self::$useConstants = defined('DARDASHA_API_URL') && defined('DARDASHA_TOKEN') && defined('DARDASHA_FROM');
        if (!self::$useConstants) {
            self::$db = Database::getInstance();
            self::loadSettings();
        }
    }

    /**
     * Legacy: load settings from DB when constants aren't defined.
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
            self::$settings = [];
        }
    }

    /**
     * Check if WhatsApp is enabled and configured.
     */
    public static function isEnabled() {
        self::init();
        if (self::$useConstants) {
            return !empty(DARDASHA_TOKEN) && !empty(DARDASHA_FROM) && !empty(DARDASHA_API_URL);
        }
        return (self::$settings['whatsapp_enabled'] ?? '0') === '1'
            && !empty(self::$settings['whatsapp_api_token']);
    }

    /**
     * Normalize phone number to E.164 format without +.
     * Handles Omani numbers: 8-digit → prepend 968.
     */
    public static function normalizePhone($phone) {
        $clean = preg_replace('/[^0-9]/', '', ltrim((string)$phone, '+'));
        if (str_starts_with($clean, '00')) $clean = substr($clean, 2);
        if (str_starts_with($clean, '0') && strlen($clean) === 8) $clean = '968' . substr($clean, 1);
        if (!str_starts_with($clean, '968') && !str_starts_with($clean, '971') && !str_starts_with($clean, '966') && strlen($clean) === 8) {
            $clean = '968' . $clean;
        }
        return $clean;
    }

    /**
     * Send WhatsApp message via Dardasha REST API.
     *
     * @param string $phoneNumber Phone number in any format
     * @param string $message Message to send
     * @return array ['success' => bool, 'error' => string|null, 'messageId' => string|null]
     */
    public static function sendMessage($phoneNumber, $message) {
        self::init();

        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp not enabled or token not configured'];
        }

        $to = self::normalizePhone($phoneNumber);

        if (self::$useConstants) {
            $apiUrl = DARDASHA_API_URL;
            $token  = DARDASHA_TOKEN;
            $from   = DARDASHA_FROM;
            // Dardasha's /send_message endpoint expects token IN the JSON body, not a header
            $payload = [
                'messageType' => 'text',
                'requestType' => 'POST',
                'token' => $token,
                'from' => $from,
                'to' => $to,
                'text' => $message,
            ];
            $headers = ['Content-Type: application/json'];
        } else {
            $apiUrl = self::$settings['whatsapp_api_url'] ?? 'http://127.0.0.1:3000/api/messages/api/send';
            $token  = self::$settings['whatsapp_api_token'] ?? '';
            $from   = self::$settings['whatsapp_session_id'] ?? '96897707134';
            $payload = ['to' => $to, 'from' => $from, 'text' => $message];
            $headers = ['Content-Type: application/json', 'X-API-Key: ' . $token];
        }

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[WhatsApp] CURL error to $to: $curlErr");
            return ['success' => false, 'error' => 'CURL Error: ' . $curlErr, 'messageId' => null];
        }

        $data = json_decode($response, true);
        if (($data['success'] ?? false) === true) {
            $messageId = $data['data']['messageId'] ?? $data['messageId'] ?? null;
            return ['success' => true, 'error' => null, 'messageId' => $messageId];
        }

        $errMsg = $data['error'] ?? $data['message'] ?? ('HTTP ' . $httpCode);
        error_log("[WhatsApp] Send failed to $to: $errMsg");
        return ['success' => false, 'error' => 'API Error: ' . $errMsg, 'messageId' => null];
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

        // Use company phone, or fall back to shipping_phone on the order itself
        $phoneNumber = $company['phone'] ?? $company['mobile'] ?? $order['shipping_phone'] ?? null;

        if (empty($phoneNumber)) {
            return ['success' => false, 'error' => 'No phone number for order confirmation'];
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
     * Notify print shop via WhatsApp when a new order is placed
     * @param array $order Print order data
     * @param array $printShop Print shop data (must have 'whatsapp' or 'phone' field)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendPrintShopOrderAlert($order, $printShop) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp not enabled'];
        }

        $phone = $printShop['whatsapp'] ?? $printShop['phone'] ?? null;
        if (empty($phone)) {
            return ['success' => false, 'error' => 'No print shop WhatsApp/phone number'];
        }

        $message  = "🖨️ New Print Order!\n\n";
        $message .= "Order: " . ($order['order_number'] ?? $order['id']) . "\n";
        $message .= "Company: " . ($order['company_name'] ?? 'N/A') . "\n";
        $message .= "Qty: " . ($order['quantity'] ?? 100) . " cards\n";
        $message .= "Paper: " . ucfirst($order['paper_type'] ?? 'standard') . "\n";
        $message .= "Finish: " . ucfirst($order['finish'] ?? 'matte') . "\n";
        if (!empty($order['express_delivery'])) {
            $message .= "Express Delivery: Yes\n";
        }
        $message .= "\nPlease confirm via your dashboard.";

        return self::sendMessage($phone, $message);
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
