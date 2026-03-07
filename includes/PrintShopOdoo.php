<?php
/**
 * Print Shop Odoo ERP Integration
 * Allows individual print shops to connect their own Odoo instance
 * for automatic quotation, invoice, and order management
 */
class PrintShopOdoo {
    private $db;
    private $printShopId;
    private $settings;
    private $uid = null;
    
    // Supported ERP systems
    const ERP_ODOO = 'odoo';
    const ERP_SAP = 'sap';
    const ERP_ZOHO = 'zoho';
    const ERP_QUICKBOOKS = 'quickbooks';
    
    public function __construct($printShopId) {
        $this->db = Database::getInstance();
        $this->printShopId = $printShopId;
        $this->loadSettings();
    }
    
    /**
     * Load ERP settings for this print shop
     */
    private function loadSettings() {
        $shop = $this->db->fetchOne(
            "SELECT erp_enabled, erp_system, erp_url, erp_database, erp_username, 
                    erp_api_key, erp_company_id, erp_settings, erp_sync_enabled,
                    quotation_prefix, invoice_prefix, delivery_note_prefix,
                    tax_rate, tax_number, payment_terms_days, quotation_validity_days,
                    require_quotation, require_po, auto_invoice, currency, name
             FROM print_shops WHERE id = :id",
            ['id' => $this->printShopId]
        );
        
        $this->settings = $shop ?: [];
        
        // Parse JSON settings if present
        if (!empty($this->settings['erp_settings'])) {
            $this->settings['erp_settings'] = json_decode($this->settings['erp_settings'], true) ?: [];
        } else {
            $this->settings['erp_settings'] = [];
        }
    }
    
    /**
     * Check if ERP integration is enabled and configured
     */
    public function isEnabled() {
        return !empty($this->settings['erp_enabled']) 
            && !empty($this->settings['erp_url']) 
            && !empty($this->settings['erp_database'])
            && !empty($this->settings['erp_username']);
    }
    
    /**
     * Check if auto-sync is enabled
     */
    public function isSyncEnabled() {
        return $this->isEnabled() && !empty($this->settings['erp_sync_enabled']);
    }
    
    /**
     * Get ERP system type
     */
    public function getErpSystem() {
        return $this->settings['erp_system'] ?? self::ERP_ODOO;
    }
    
    /**
     * Get settings
     */
    public function getSettings() {
        return [
            'erp_enabled' => $this->settings['erp_enabled'] ?? false,
            'erp_system' => $this->settings['erp_system'] ?? 'odoo',
            'erp_url' => $this->settings['erp_url'] ?? '',
            'erp_database' => $this->settings['erp_database'] ?? '',
            'erp_username' => $this->settings['erp_username'] ?? '',
            'erp_api_key' => !empty($this->settings['erp_api_key']) ? '***' : '',
            'erp_company_id' => $this->settings['erp_company_id'] ?? '',
            'erp_sync_enabled' => $this->settings['erp_sync_enabled'] ?? false,
            'erp_settings' => $this->settings['erp_settings'] ?? [],
            'quotation_prefix' => $this->settings['quotation_prefix'] ?? 'QT-',
            'invoice_prefix' => $this->settings['invoice_prefix'] ?? 'INV-',
            'delivery_note_prefix' => $this->settings['delivery_note_prefix'] ?? 'DN-',
            'tax_rate' => $this->settings['tax_rate'] ?? 0,
            'tax_number' => $this->settings['tax_number'] ?? '',
            'payment_terms_days' => $this->settings['payment_terms_days'] ?? 30,
            'quotation_validity_days' => $this->settings['quotation_validity_days'] ?? 30,
            'require_quotation' => $this->settings['require_quotation'] ?? false,
            'require_po' => $this->settings['require_po'] ?? false,
            'auto_invoice' => $this->settings['auto_invoice'] ?? true
        ];
    }
    
    /**
     * Update ERP settings for this print shop
     */
    public function updateSettings($data) {
        try {
            $updateData = [];
            
            // ERP connection settings
            if (isset($data['erp_enabled'])) $updateData['erp_enabled'] = $data['erp_enabled'] ? 1 : 0;
            if (isset($data['erp_system'])) $updateData['erp_system'] = $data['erp_system'];
            if (isset($data['erp_url'])) $updateData['erp_url'] = rtrim($data['erp_url'], '/');
            if (isset($data['erp_database'])) $updateData['erp_database'] = $data['erp_database'];
            if (isset($data['erp_username'])) $updateData['erp_username'] = $data['erp_username'];
            if (isset($data['erp_api_key']) && $data['erp_api_key'] !== '***') {
                $updateData['erp_api_key'] = $data['erp_api_key'];
            }
            if (isset($data['erp_company_id'])) $updateData['erp_company_id'] = $data['erp_company_id'];
            if (isset($data['erp_sync_enabled'])) $updateData['erp_sync_enabled'] = $data['erp_sync_enabled'] ? 1 : 0;
            if (isset($data['erp_settings'])) {
                $updateData['erp_settings'] = is_array($data['erp_settings']) 
                    ? json_encode($data['erp_settings']) 
                    : $data['erp_settings'];
            }
            
            // Document settings
            if (isset($data['quotation_prefix'])) $updateData['quotation_prefix'] = $data['quotation_prefix'];
            if (isset($data['invoice_prefix'])) $updateData['invoice_prefix'] = $data['invoice_prefix'];
            if (isset($data['delivery_note_prefix'])) $updateData['delivery_note_prefix'] = $data['delivery_note_prefix'];
            if (isset($data['tax_rate'])) $updateData['tax_rate'] = floatval($data['tax_rate']);
            if (isset($data['tax_number'])) $updateData['tax_number'] = $data['tax_number'];
            if (isset($data['payment_terms_days'])) $updateData['payment_terms_days'] = intval($data['payment_terms_days']);
            if (isset($data['quotation_validity_days'])) $updateData['quotation_validity_days'] = intval($data['quotation_validity_days']);
            if (isset($data['require_quotation'])) $updateData['require_quotation'] = $data['require_quotation'] ? 1 : 0;
            if (isset($data['require_po'])) $updateData['require_po'] = $data['require_po'] ? 1 : 0;
            if (isset($data['auto_invoice'])) $updateData['auto_invoice'] = $data['auto_invoice'] ? 1 : 0;
            
            if (!empty($updateData)) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                $this->db->update('print_shops', $updateData, 'id = :id', ['id' => $this->printShopId]);
                $this->loadSettings(); // Reload settings
            }
            
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test connection to ERP
     */
    public function testConnection() {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'ERP integration is not enabled or configured'];
        }
        
        $erpSystem = $this->getErpSystem();
        
        switch ($erpSystem) {
            case self::ERP_ODOO:
                return $this->testOdooConnection();
            case self::ERP_ZOHO:
                return $this->testZohoConnection();
            case self::ERP_QUICKBOOKS:
                return $this->testQuickBooksConnection();
            default:
                return ['success' => false, 'error' => 'Unsupported ERP system: ' . $erpSystem];
        }
    }
    
    // =========================================================================
    // ODOO INTEGRATION
    // =========================================================================
    
    /**
     * Test Odoo connection
     */
    private function testOdooConnection() {
        $result = $this->odooAuthenticate();
        if ($result['success']) {
            return ['success' => true, 'message' => 'Connection successful! UID: ' . $result['uid']];
        }
        return $result;
    }
    
    /**
     * Authenticate with Odoo
     */
    private function odooAuthenticate() {
        if (!function_exists('xmlrpc_encode_request')) {
            return ['success' => false, 'error' => 'PHP XML-RPC extension is not installed. Please contact your hosting provider.'];
        }
        
        $endpoint = $this->settings['erp_url'] . '/xmlrpc/2/common';
        
        $request = xmlrpc_encode_request('authenticate', [
            $this->settings['erp_database'],
            $this->settings['erp_username'],
            $this->settings['erp_api_key'], // Password/API key
            []
        ]);
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'error' => 'Connection failed: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Failed to connect to Odoo (HTTP ' . $httpCode . ')'];
        }
        
        $result = xmlrpc_decode($response);
        
        if (is_array($result) && isset($result['faultCode'])) {
            return ['success' => false, 'error' => 'Authentication failed: ' . ($result['faultString'] ?? 'Unknown error')];
        }
        
        if (!is_numeric($result) || $result === false) {
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        $this->uid = $result;
        return ['success' => true, 'uid' => $result];
    }
    
    /**
     * Execute Odoo RPC call
     */
    private function odooExecute($model, $method, $args, $kwargs = []) {
        if (!$this->uid) {
            $auth = $this->odooAuthenticate();
            if (!$auth['success']) {
                return $auth;
            }
        }
        
        $endpoint = $this->settings['erp_url'] . '/xmlrpc/2/object';
        
        $params = [
            $this->settings['erp_database'],
            $this->uid,
            $this->settings['erp_api_key'],
            $model,
            $method,
            $args
        ];
        
        if (!empty($kwargs)) {
            $params[] = $kwargs;
        }
        
        $request = xmlrpc_encode_request('execute_kw', $params);
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Odoo API call failed'];
        }
        
        $result = xmlrpc_decode($response);
        
        if (is_array($result) && isset($result['faultCode'])) {
            return ['success' => false, 'error' => 'Odoo error: ' . ($result['faultString'] ?? 'Unknown error')];
        }
        
        return ['success' => true, 'data' => $result];
    }
    
    /**
     * Create or find customer in Odoo
     */
    public function findOrCreateCustomer($companyId) {
        // Get company details
        $company = $this->db->fetchOne(
            "SELECT name, admin_email, phone, address, city, country FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        
        if (!$company) {
            return ['success' => false, 'error' => 'Company not found'];
        }
        
        // Search for existing partner by email
        $search = $this->odooExecute('res.partner', 'search', [
            [['email', '=', $company['admin_email']]]
        ]);
        
        if ($search['success'] && !empty($search['data'])) {
            return ['success' => true, 'partner_id' => $search['data'][0]];
        }
        
        // Create new partner
        $partnerData = [
            'name' => $company['name'],
            'email' => $company['admin_email'],
            'phone' => $company['phone'] ?? '',
            'street' => $company['address'] ?? '',
            'city' => $company['city'] ?? '',
            'country_id' => $this->getOdooCountryId($company['country'] ?? ''),
            'customer_rank' => 1,
            'is_company' => true
        ];
        
        $result = $this->odooExecute('res.partner', 'create', [$partnerData]);
        
        if ($result['success']) {
            return ['success' => true, 'partner_id' => $result['data']];
        }
        
        return $result;
    }
    
    /**
     * Get Odoo country ID from country name/code
     */
    private function getOdooCountryId($country) {
        if (empty($country)) return false;
        
        $search = $this->odooExecute('res.country', 'search', [
            ['|', ['name', 'ilike', $country], ['code', '=', strtoupper(substr($country, 0, 2))]]
        ]);
        
        if ($search['success'] && !empty($search['data'])) {
            return $search['data'][0];
        }
        
        return false;
    }
    
    /**
     * Get or create print product in Odoo
     */
    private function getOrCreateProduct($name = 'Business Card Printing') {
        // Search for existing product
        $search = $this->odooExecute('product.product', 'search', [
            [['name', '=', $name]]
        ]);
        
        if ($search['success'] && !empty($search['data'])) {
            return $search['data'][0];
        }
        
        // Create product
        $productData = [
            'name' => $name,
            'type' => 'service',
            'sale_ok' => true,
            'purchase_ok' => false,
            'list_price' => 0,
            'default_code' => 'BC-PRINT'
        ];
        
        $result = $this->odooExecute('product.product', 'create', [$productData]);
        
        return $result['success'] ? $result['data'] : false;
    }
    
    /**
     * Create quotation in Odoo
     */
    public function createQuotation($orderId) {
        if (!$this->isSyncEnabled()) {
            return ['success' => false, 'error' => 'ERP sync is not enabled'];
        }
        
        // Get order details - use shipping info from order as companies table doesn't have address fields
        $order = $this->db->fetchOne(
            "SELECT po.*, c.name as company_name, c.admin_email as company_email,
                    po.shipping_phone as company_phone,
                    po.shipping_address as company_address,
                    po.shipping_city as company_city,
                    po.shipping_country as company_country
             FROM print_orders po
             LEFT JOIN companies c ON po.company_id = c.id
             WHERE po.id = :id AND po.print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Find or create customer
        $customer = $this->findOrCreateCustomer($order['company_id']);
        if (!$customer['success']) {
            return $customer;
        }
        
        // Get product
        $productId = $this->getOrCreateProduct();
        if (!$productId) {
            return ['success' => false, 'error' => 'Failed to create product in Odoo'];
        }
        
        // Calculate validity date
        $validityDays = $this->settings['quotation_validity_days'] ?? 30;
        $validityDate = date('Y-m-d', strtotime("+{$validityDays} days"));
        
        // Calculate amounts
        $subtotal = floatval($order['subtotal'] ?? $order['total'] ?? 0);
        $taxRate = floatval($this->settings['tax_rate'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        
        // Generate quotation number
        $quotationNumber = $this->generateDocumentNumber('quotation');
        
        // Create sale order (quotation) in Odoo
        $orderData = [
            'partner_id' => $customer['partner_id'],
            'date_order' => date('Y-m-d H:i:s'),
            'validity_date' => $validityDate,
            'client_order_ref' => $order['order_number'],
            'note' => $this->buildOrderNotes($order),
            'state' => 'draft', // Quotation state
            'order_line' => [[
                0, 0, [
                    'product_id' => $productId,
                    'name' => 'Business Card Printing - ' . ($order['quantity'] ?? 100) . ' cards',
                    'product_uom_qty' => 1,
                    'price_unit' => $subtotal
                ]
            ]]
        ];
        
        // Add payment terms if configured
        if (!empty($this->settings['erp_settings']['payment_term_id'])) {
            $orderData['payment_term_id'] = $this->settings['erp_settings']['payment_term_id'];
        }
        
        $result = $this->odooExecute('sale.order', 'create', [$orderData]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $odooOrderId = $result['data'];
        
        // Get the Odoo quotation number
        $odooOrder = $this->odooExecute('sale.order', 'read', [[$odooOrderId], ['name']]);
        $odooQuotationNumber = $odooOrder['success'] ? ($odooOrder['data'][0]['name'] ?? $quotationNumber) : $quotationNumber;
        
        // Update local order with Odoo reference
        $this->db->update('print_orders', [
            'quotation_number' => $odooQuotationNumber,
            'quotation_issued_at' => date('Y-m-d H:i:s'),
            'quotation_valid_until' => $validityDate,
            'quotation_amount' => $subtotal,
            'quotation_external_id' => $odooOrderId,
            'erp_system' => 'odoo',
            'erp_order_id' => $odooOrderId,
            'erp_sync_status' => 'synced',
            'erp_last_sync' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $orderId]);
        
        return [
            'success' => true,
            'quotation_number' => $odooQuotationNumber,
            'odoo_order_id' => $odooOrderId
        ];
    }
    
    /**
     * Confirm quotation and create sale order
     */
    public function confirmQuotation($orderId) {
        $order = $this->db->fetchOne(
            "SELECT quotation_external_id FROM print_orders WHERE id = :id AND print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order || empty($order['quotation_external_id'])) {
            return ['success' => false, 'error' => 'No quotation found for this order'];
        }
        
        // Confirm the sale order in Odoo
        $result = $this->odooExecute('sale.order', 'action_confirm', [[$order['quotation_external_id']]]);
        
        if ($result['success']) {
            $this->db->update('print_orders', [
                'quotation_accepted' => 1,
                'quotation_accepted_at' => date('Y-m-d H:i:s'),
                'erp_sync_status' => 'synced',
                'erp_last_sync' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $orderId]);
        }
        
        return $result;
    }
    
    /**
     * Create invoice in Odoo
     */
    public function createInvoice($orderId) {
        if (!$this->isSyncEnabled()) {
            return ['success' => false, 'error' => 'ERP sync is not enabled'];
        }
        
        $order = $this->db->fetchOne(
            "SELECT * FROM print_orders WHERE id = :id AND print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        $odooOrderId = $order['erp_order_id'] ?? $order['quotation_external_id'];
        
        if (empty($odooOrderId)) {
            // Create quotation first if not exists
            $quotationResult = $this->createQuotation($orderId);
            if (!$quotationResult['success']) {
                return $quotationResult;
            }
            $odooOrderId = $quotationResult['odoo_order_id'];
            
            // Confirm it
            $this->confirmQuotation($orderId);
        }
        
        // Create invoice from sale order
        $result = $this->odooExecute('sale.order', 'action_invoice_create', [[$odooOrderId]]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $invoiceIds = $result['data'];
        $invoiceId = is_array($invoiceIds) ? $invoiceIds[0] : $invoiceIds;
        
        // Get invoice details from Odoo
        $invoiceData = $this->odooExecute('account.move', 'read', [[$invoiceId], ['name', 'amount_total']]);
        $invoiceNumber = $invoiceData['success'] ? ($invoiceData['data'][0]['name'] ?? '') : '';
        $invoiceAmount = $invoiceData['success'] ? ($invoiceData['data'][0]['amount_total'] ?? 0) : 0;
        
        // Calculate due date
        $paymentTerms = $this->settings['payment_terms_days'] ?? 30;
        $dueDate = date('Y-m-d', strtotime("+{$paymentTerms} days"));
        
        // Update local order
        $this->db->update('print_orders', [
            'invoice_number' => $invoiceNumber,
            'invoice_external_id' => $invoiceId,
            'invoice_amount' => $invoiceAmount,
            'invoice_issued_at' => date('Y-m-d H:i:s'),
            'invoice_due_date' => $dueDate,
            'erp_sync_status' => 'synced',
            'erp_last_sync' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $orderId]);
        
        return [
            'success' => true,
            'invoice_number' => $invoiceNumber,
            'invoice_id' => $invoiceId
        ];
    }
    
    /**
     * Mark invoice as paid in Odoo
     */
    public function markInvoicePaid($orderId, $paymentDate = null, $paymentMethod = null) {
        $order = $this->db->fetchOne(
            "SELECT invoice_external_id FROM print_orders WHERE id = :id AND print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order || empty($order['invoice_external_id'])) {
            return ['success' => false, 'error' => 'No invoice found for this order'];
        }
        
        // Post the invoice if in draft
        $this->odooExecute('account.move', 'action_post', [[$order['invoice_external_id']]]);
        
        // Register payment - this creates a payment and reconciles it
        // Note: This is a simplified version. Full implementation would use account.payment
        $result = $this->odooExecute('account.move', 'write', [
            [$order['invoice_external_id']],
            ['payment_state' => 'paid']
        ]);
        
        if ($result['success']) {
            $this->db->update('print_orders', [
                'invoice_paid' => 1,
                'invoice_paid_at' => $paymentDate ?? date('Y-m-d H:i:s'),
                'payment_status' => 'paid',
                'erp_sync_status' => 'synced',
                'erp_last_sync' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $orderId]);
        }
        
        return $result;
    }
    
    /**
     * Create delivery order in Odoo
     */
    public function createDeliveryNote($orderId) {
        $order = $this->db->fetchOne(
            "SELECT * FROM print_orders WHERE id = :id AND print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order || empty($order['erp_order_id'])) {
            return ['success' => false, 'error' => 'No Odoo order found'];
        }
        
        // Get picking (delivery) from sale order
        $pickings = $this->odooExecute('sale.order', 'read', [
            [$order['erp_order_id']], 
            ['picking_ids']
        ]);
        
        if (!$pickings['success'] || empty($pickings['data'][0]['picking_ids'])) {
            return ['success' => false, 'error' => 'No delivery order found in Odoo'];
        }
        
        $pickingId = $pickings['data'][0]['picking_ids'][0];
        
        // Validate the picking (mark as done)
        $result = $this->odooExecute('stock.picking', 'button_validate', [[$pickingId]]);
        
        if ($result['success']) {
            // Get delivery note number
            $deliveryData = $this->odooExecute('stock.picking', 'read', [[$pickingId], ['name']]);
            $deliveryNumber = $deliveryData['success'] ? ($deliveryData['data'][0]['name'] ?? '') : '';
            
            $this->db->update('print_orders', [
                'delivery_note_number' => $deliveryNumber,
                'delivery_note_external_id' => $pickingId,
                'delivery_note_issued_at' => date('Y-m-d H:i:s'),
                'shipped_at' => date('Y-m-d H:i:s'),
                'status' => 'shipped',
                'erp_sync_status' => 'synced',
                'erp_last_sync' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $orderId]);
            
            return ['success' => true, 'delivery_number' => $deliveryNumber];
        }
        
        return $result;
    }
    
    /**
     * Attach file to Odoo record
     */
    public function attachFile($odooRecordId, $filePath, $description, $model = 'sale.order') {
        $fullPath = defined('BASE_DIR') ? BASE_DIR . '/' . ltrim($filePath, '/') : $filePath;
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        $fileContent = file_get_contents($fullPath);
        $fileName = basename($filePath);
        
        $attachmentData = [
            'name' => $fileName,
            'description' => $description,
            'res_model' => $model,
            'res_id' => intval($odooRecordId),
            'type' => 'binary',
            'datas' => base64_encode($fileContent),
            'mimetype' => mime_content_type($fullPath) ?: 'application/pdf'
        ];
        
        return $this->odooExecute('ir.attachment', 'create', [$attachmentData]);
    }
    
    /**
     * Sync order to Odoo (full sync)
     */
    public function syncOrder($orderId) {
        $order = $this->db->fetchOne(
            "SELECT * FROM print_orders WHERE id = :id AND print_shop_id = :shop_id",
            ['id' => $orderId, 'shop_id' => $this->printShopId]
        );
        
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        $results = [];
        
        // Create quotation if not exists
        if (empty($order['quotation_external_id'])) {
            $results['quotation'] = $this->createQuotation($orderId);
            if (!$results['quotation']['success']) {
                return ['success' => false, 'error' => 'Failed to create quotation', 'details' => $results];
            }
        }
        
        // Attach files if present
        $order = $this->db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $orderId]);
        $odooOrderId = $order['erp_order_id'] ?? $order['quotation_external_id'];
        
        if (!empty($order['po_file_path']) && $odooOrderId) {
            $results['po_attachment'] = $this->attachFile($odooOrderId, $order['po_file_path'], 'Purchase Order');
        }
        
        if (!empty($order['quotation_file_path']) && $odooOrderId) {
            $results['quotation_attachment'] = $this->attachFile($odooOrderId, $order['quotation_file_path'], 'Quotation');
        }
        
        return ['success' => true, 'results' => $results];
    }
    
    /**
     * Generate document number with prefix
     */
    public function generateDocumentNumber($type) {
        $prefix = '';
        switch ($type) {
            case 'quotation':
                $prefix = $this->settings['quotation_prefix'] ?? 'QT-';
                break;
            case 'invoice':
                $prefix = $this->settings['invoice_prefix'] ?? 'INV-';
                break;
            case 'delivery':
                $prefix = $this->settings['delivery_note_prefix'] ?? 'DN-';
                break;
        }
        
        return $prefix . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    }
    
    /**
     * Build order notes for Odoo
     */
    private function buildOrderNotes($order) {
        $notes = [];
        $notes[] = "Order Number: " . ($order['order_number'] ?? 'N/A');
        $notes[] = "Quantity: " . ($order['quantity'] ?? 100) . " cards";
        
        if (!empty($order['paper_type'])) {
            $notes[] = "Paper: " . $order['paper_type'];
        }
        if (!empty($order['finish'])) {
            $notes[] = "Finish: " . $order['finish'];
        }
        if (!empty($order['express_delivery'])) {
            $notes[] = "Express Delivery: Yes";
        }
        if (!empty($order['notes'])) {
            $notes[] = "Customer Notes: " . $order['notes'];
        }
        
        return implode("\n", $notes);
    }
    
    // =========================================================================
    // ZOHO INTEGRATION (Placeholder)
    // =========================================================================
    
    private function testZohoConnection() {
        return ['success' => false, 'error' => 'Zoho integration coming soon'];
    }
    
    // =========================================================================
    // QUICKBOOKS INTEGRATION (Placeholder)
    // =========================================================================
    
    private function testQuickBooksConnection() {
        return ['success' => false, 'error' => 'QuickBooks integration coming soon'];
    }
    
    // =========================================================================
    // STATIC HELPERS
    // =========================================================================
    
    /**
     * Get supported ERP systems
     */
    public static function getSupportedSystems() {
        return [
            self::ERP_ODOO => [
                'name' => 'Odoo',
                'description' => 'Open source ERP with full integration support',
                'supported' => true,
                'fields' => ['url', 'database', 'username', 'api_key']
            ],
            self::ERP_ZOHO => [
                'name' => 'Zoho Books',
                'description' => 'Cloud-based accounting software',
                'supported' => false,
                'coming_soon' => true
            ],
            self::ERP_QUICKBOOKS => [
                'name' => 'QuickBooks',
                'description' => 'Popular accounting software',
                'supported' => false,
                'coming_soon' => true
            ],
            self::ERP_SAP => [
                'name' => 'SAP Business One',
                'description' => 'Enterprise resource planning',
                'supported' => false,
                'coming_soon' => true
            ]
        ];
    }
}
