<?php
/**
 * Odoo ERP Integration
 * Syncs print orders, quotations, invoices, and delivery notes with Odoo
 */
class OdooIntegration {
    private static $db = null;
    private static $url = null;
    private static $database = null;
    private static $username = null;
    private static $password = null;
    private static $enabled = false;
    
    public static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
            self::loadSettings();
        }
    }
    
    /**
     * Load Odoo settings from database
     */
    private static function loadSettings() {
        try {
            $urlSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'odoo_url']
            );
            $dbSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'odoo_database']
            );
            $userSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'odoo_username']
            );
            $passSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'odoo_password']
            );
            $enabledSetting = self::$db->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = :key",
                ['key' => 'odoo_enabled']
            );
            
            self::$url = $urlSetting['setting_value'] ?? '';
            self::$database = $dbSetting['setting_value'] ?? '';
            self::$username = $userSetting['setting_value'] ?? '';
            self::$password = $passSetting['setting_value'] ?? '';
            self::$enabled = ($enabledSetting['setting_value'] ?? '0') === '1';
        } catch (Exception $e) {
            self::$enabled = false;
        }
    }
    
    /**
     * Check if Odoo is enabled and configured
     */
    public static function isEnabled() {
        self::init();
        return self::$enabled && !empty(self::$url) && !empty(self::$database) && !empty(self::$username);
    }
    
    /**
     * Authenticate with Odoo and get UID
     */
    private static function authenticate() {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Odoo integration is not enabled or configured'];
        }
        
        $endpoint = rtrim(self::$url, '/') . '/xmlrpc/2/common';
        
        $request = xmlrpc_encode_request('authenticate', [
            self::$database,
            self::$username,
            self::$password,
            []
        ]);
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Failed to connect to Odoo'];
        }
        
        $result = xmlrpc_decode($response);
        
        if (is_array($result) && isset($result['faultCode'])) {
            return ['success' => false, 'error' => 'Odoo authentication failed: ' . ($result['faultString'] ?? 'Unknown error')];
        }
        
        if (!is_numeric($result)) {
            return ['success' => false, 'error' => 'Invalid authentication response'];
        }
        
        return ['success' => true, 'uid' => $result];
    }
    
    /**
     * Execute Odoo RPC call
     */
    private static function executeRPC($model, $method, $args, $uid = null) {
        if (!$uid) {
            $auth = self::authenticate();
            if (!$auth['success']) {
                return $auth;
            }
            $uid = $auth['uid'];
        }
        
        $endpoint = rtrim(self::$url, '/') . '/xmlrpc/2/object';
        
        $request = xmlrpc_encode_request('execute_kw', [
            self::$database,
            $uid,
            self::$password,
            $model,
            $method,
            $args
        ]);
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Odoo RPC call failed'];
        }
        
        $result = xmlrpc_decode($response);
        
        if (is_array($result) && isset($result['faultCode'])) {
            return ['success' => false, 'error' => 'Odoo error: ' . ($result['faultString'] ?? 'Unknown error')];
        }
        
        return ['success' => true, 'data' => $result];
    }
    
    /**
     * Sync print order to Odoo as a Sale Order
     */
    public static function syncOrderToOdoo($orderId) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Odoo integration is not enabled'];
        }
        
        self::init();
        
        // Get order details
        $order = self::$db->fetchOne(
            "SELECT po.*, c.name as company_name, c.admin_email as company_email 
             FROM print_orders po 
             LEFT JOIN companies c ON po.company_id = c.id 
             WHERE po.id = :id",
            ['id' => $orderId]
        );
        
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Get employee count for quantity - handle both old (employee_ids JSON) and new (employee_id single) schemas
        $employeeCount = 1;
        if (!empty($order['employee_ids'])) {
            $employeeIds = json_decode($order['employee_ids'], true);
            $employeeCount = is_array($employeeIds) ? count($employeeIds) : 1;
        }
        $totalQuantity = $employeeCount * ($order['quantity'] ?? 1);
        
        // Prepare sale order data
        $saleOrderData = [
            'name' => $order['order_number'],
            'partner_id' => self::findOrCreatePartner($order['company_name'], $order['company_email']),
            'date_order' => date('Y-m-d H:i:s'),
            'client_order_ref' => $order['order_number'],
            'note' => 'Print Order: ' . $order['order_number'] . "\n" . 
                     'Employees: ' . $employeeCount . "\n" . 
                     'Quantity per employee: ' . ($order['quantity'] ?? 1) . "\n" .
                     ($order['notes'] ? 'Notes: ' . $order['notes'] : ''),
            'order_line' => [[
                0, 0, [
                    'product_id' => self::getPrintProductId(),
                    'product_uom_qty' => $totalQuantity,
                    'price_unit' => 0, // Set price in Odoo or via quotation
                ]
            ]]
        ];
        
        // Create sale order in Odoo
        $result = self::executeRPC('sale.order', 'create', [$saleOrderData]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $odooOrderId = $result['data'];
        
        // Store Odoo order ID in print_orders
        try {
            self::$db->update('print_orders',
                ['print_provider_order_id' => 'odoo_' . $odooOrderId],
                'id = :id',
                ['id' => $orderId]
            );
        } catch (Exception $e) {
            // Continue even if update fails
        }
        
        return ['success' => true, 'odoo_order_id' => $odooOrderId];
    }
    
    /**
     * Sync quotation to Odoo
     */
    public static function syncQuotationToOdoo($orderId, $quotationFilePath) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Odoo integration is not enabled'];
        }
        
        // Get order
        $order = self::$db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Find or create sale order in Odoo
        $odooOrderId = null;
        if (!empty($order['print_provider_order_id']) && strpos($order['print_provider_order_id'], 'odoo_') === 0) {
            $odooOrderId = str_replace('odoo_', '', $order['print_provider_order_id']);
        } else {
            $syncResult = self::syncOrderToOdoo($orderId);
            if (!$syncResult['success']) {
                return $syncResult;
            }
            $odooOrderId = $syncResult['odoo_order_id'];
        }
        
        // Attach quotation file to Odoo sale order
        $attachmentResult = self::attachFileToOrder($odooOrderId, $quotationFilePath, 'Quotation');
        
        return $attachmentResult;
    }
    
    /**
     * Sync invoice to Odoo
     */
    public static function syncInvoiceToOdoo($orderId, $invoiceFilePath) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Odoo integration is not enabled'];
        }
        
        // Get order
        $order = self::$db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Find or create sale order in Odoo
        $odooOrderId = null;
        if (!empty($order['print_provider_order_id']) && strpos($order['print_provider_order_id'], 'odoo_') === 0) {
            $odooOrderId = str_replace('odoo_', '', $order['print_provider_order_id']);
        } else {
            $syncResult = self::syncOrderToOdoo($orderId);
            if (!$syncResult['success']) {
                return $syncResult;
            }
            $odooOrderId = $syncResult['odoo_order_id'];
        }
        
        // Create invoice in Odoo from sale order
        $invoiceResult = self::executeRPC('sale.order', 'action_invoice_create', [[$odooOrderId]]);
        
        if ($invoiceResult['success'] && !empty($invoiceResult['data'])) {
            $invoiceIds = $invoiceResult['data'];
            if (!empty($invoiceIds)) {
                // Attach invoice file
                self::attachFileToOrder($invoiceIds[0], $invoiceFilePath, 'Invoice', 'account.move');
            }
        }
        
        // Also attach to sale order
        self::attachFileToOrder($odooOrderId, $invoiceFilePath, 'Invoice');
        
        return ['success' => true];
    }
    
    /**
     * Sync delivery note to Odoo
     */
    public static function syncDeliveryNoteToOdoo($orderId, $deliveryNoteFilePath) {
        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'Odoo integration is not enabled'];
        }
        
        // Get order
        $order = self::$db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Find or create sale order in Odoo
        $odooOrderId = null;
        if (!empty($order['print_provider_order_id']) && strpos($order['print_provider_order_id'], 'odoo_') === 0) {
            $odooOrderId = str_replace('odoo_', '', $order['print_provider_order_id']);
        } else {
            $syncResult = self::syncOrderToOdoo($orderId);
            if (!$syncResult['success']) {
                return $syncResult;
            }
            $odooOrderId = $syncResult['odoo_order_id'];
        }
        
        // Attach delivery note file to Odoo sale order
        $attachmentResult = self::attachFileToOrder($odooOrderId, $deliveryNoteFilePath, 'Delivery Note');
        
        return $attachmentResult;
    }
    
    /**
     * Find or create partner (customer) in Odoo
     */
    private static function findOrCreatePartner($name, $email) {
        // Search for existing partner
        $searchResult = self::executeRPC('res.partner', 'search', [[['email', '=', $email]]]);
        
        if ($searchResult['success'] && !empty($searchResult['data'])) {
            return $searchResult['data'][0];
        }
        
        // Create new partner
        $partnerData = [
            'name' => $name,
            'email' => $email,
            'customer_rank' => 1,
            'is_company' => true
        ];
        
        $createResult = self::executeRPC('res.partner', 'create', [$partnerData]);
        
        if ($createResult['success']) {
            return $createResult['data'];
        }
        
        // Fallback: return default partner or handle error
        return false;
    }
    
    /**
     * Get or create print product in Odoo
     */
    private static function getPrintProductId() {
        // Try to find existing product
        $searchResult = self::executeRPC('product.product', 'search', [[['name', '=', 'Business Card Printing']]]);
        
        if ($searchResult['success'] && !empty($searchResult['data'])) {
            return $searchResult['data'][0];
        }
        
        // Create product if not found
        $productData = [
            'name' => 'Business Card Printing',
            'type' => 'service',
            'sale_ok' => true,
            'purchase_ok' => false,
            'list_price' => 0
        ];
        
        $createResult = self::executeRPC('product.product', 'create', [$productData]);
        
        if ($createResult['success']) {
            return $createResult['data'];
        }
        
        return false;
    }
    
    /**
     * Attach file to Odoo record
     */
    private static function attachFileToOrder($odooRecordId, $filePath, $description, $model = 'sale.order') {
        $fileContent = file_get_contents(getFilePath($filePath));
        if ($fileContent === false) {
            return ['success' => false, 'error' => 'Failed to read file'];
        }
        
        $fileName = basename($filePath);
        $fileBase64 = base64_encode($fileContent);
        
        $attachmentData = [
            'name' => $fileName,
            'description' => $description,
            'res_model' => $model,
            'res_id' => $odooRecordId,
            'type' => 'binary',
            'datas' => $fileBase64,
            'mimetype' => mime_content_type(getFilePath($filePath)) ?: 'application/pdf'
        ];
        
        return self::executeRPC('ir.attachment', 'create', [$attachmentData]);
    }
    
    /**
     * Update Odoo settings
     */
    public static function updateSettings($url, $database, $username, $password, $enabled) {
        self::init();
        
        try {
            $settings = [
                'odoo_url' => $url,
                'odoo_database' => $database,
                'odoo_username' => $username,
                'odoo_password' => $password,
                'odoo_enabled' => $enabled ? '1' : '0'
            ];
            
            foreach ($settings as $key => $value) {
                $existing = self::$db->fetchOne(
                    "SELECT id FROM system_settings WHERE setting_key = :key",
                    ['key' => $key]
                );
                
                if ($existing) {
                    self::$db->update('system_settings',
                        ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')],
                        'setting_key = :key',
                        ['key' => $key]
                    );
                } else {
                    self::$db->insert('system_settings', [
                        'id' => generateUUID(),
                        'setting_key' => $key,
                        'setting_value' => $value,
                        'description' => 'Odoo ERP integration setting'
                    ]);
                }
            }
            
            // Reload settings
            self::loadSettings();
            
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get Odoo settings
     */
    public static function getSettings() {
        self::init();
        
        return [
            'url' => self::$url,
            'database' => self::$database,
            'username' => self::$username,
            'password' => self::$password ? '***' : '', // Don't expose password
            'enabled' => self::$enabled
        ];
    }
    
    /**
     * Test Odoo connection
     */
    public static function testConnection() {
        $auth = self::authenticate();
        return $auth;
    }
}
