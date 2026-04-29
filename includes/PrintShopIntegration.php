<?php
/**
 * Print Shop Integration - Cardify
 * Handles integration with external print shops for ordering physical business cards
 */

class PrintShopIntegration {
    
    private static $settingsKey = 'print_shop_settings';
    
    /**
     * Generate a UUID v4
     */
    private static function generateUUID() {
        // Delegate to the global helper which uses cryptographically secure random_bytes()
        return \generateUUID();
    }
    
    /**
     * Get print shop settings
     */
    public static function getSettings() {
        $db = Database::getInstance();
        
        if (!$db->isConnected()) {
            return self::getDefaultSettings();
        }
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ?");
            $stmt->execute([self::$settingsKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['value'])) {
                $settings = json_decode($result['value'], true);
                // Mask the API key for display
                if (!empty($settings['api_key'])) {
                    $settings['api_key_masked'] = str_repeat('•', min(12, strlen($settings['api_key'])));
                }
                return array_merge(self::getDefaultSettings(), $settings);
            }
        } catch (PDOException $e) {
            error_log("PrintShop: Error getting settings: " . $e->getMessage());
        }
        
        return self::getDefaultSettings();
    }
    
    /**
     * Get default settings
     */
    private static function getDefaultSettings() {
        return [
            'enabled' => false,
            'provider' => 'custom', // custom, printful, vistaprint, moo
            'api_endpoint' => '',
            'api_key' => '',
            'api_key_masked' => '',
            'shop_name' => '',
            'shop_email' => '',
            'webhook_url' => '',
            'default_quantity' => 100,
            'default_paper' => 'matte',
            'default_finish' => 'standard',
            'pricing' => [
                'per_card' => 0.10,
                'setup_fee' => 5.00,
                'shipping' => 10.00
            ],
            'card_sizes' => [
                'standard' => ['width' => 3.5, 'height' => 2, 'unit' => 'in'],
                'european' => ['width' => 85, 'height' => 55, 'unit' => 'mm']
            ],
            'paper_types' => ['matte', 'glossy', 'silk', 'uncoated', 'recycled'],
            'finishes' => ['standard', 'rounded_corners', 'spot_uv', 'foil', 'embossed']
        ];
    }
    
    /**
     * Update print shop settings
     */
    public static function updateSettings($settings) {
        $db = Database::getInstance();
        
        if (!$db->isConnected()) {
            return ['success' => false, 'error' => 'Database not connected'];
        }
        
        try {
            $pdo = $db->getConnection();
            
            // Get existing settings to preserve API key if not provided
            $existingSettings = self::getSettings();
            if (empty($settings['api_key']) && !empty($existingSettings['api_key'])) {
                $settings['api_key'] = $existingSettings['api_key'];
            }
            
            // Remove masked key before saving
            unset($settings['api_key_masked']);
            
            $value = json_encode($settings);
            
            $stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
            $stmt->execute([self::$settingsKey, $value, $value]);
            
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("PrintShop: Error updating settings: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update settings'];
        }
    }

    /**
     * Test connection to print shop API
     */
    public static function testConnection() {
        $settings = self::getSettings();
        
        if (empty($settings['api_endpoint'])) {
            return ['success' => false, 'error' => 'API endpoint not configured'];
        }
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => rtrim($settings['api_endpoint'], '/') . '/status',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $settings['api_key'],
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return ['success' => false, 'error' => 'Connection failed: ' . $error];
            }
            
            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'message' => 'Connection successful', 'response' => json_decode($response, true)];
            }
            
            return ['success' => false, 'error' => "API returned status $httpCode"];
        } catch (Exception $e) {
            error_log("PrintShop: Connection test failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Connection test failed'];
        }
    }

    /**
     * Create a print order
     */
    public static function createOrder($orderData) {
        $db = Database::getInstance();
        $settings = self::getSettings();
        
        // Allow orders if there are print shops, even if global integration is disabled
        $printShopId = $orderData['print_shop_id'] ?? null;
        if (!$settings['enabled'] && !$printShopId) {
            return ['success' => false, 'error' => 'Print shop integration is disabled'];
        }
        
        try {
            $pdo = $db->getConnection();
            
            // Calculate pricing using print shop's tier pricing if available
            $quantity = (int)($orderData['quantity'] ?? $settings['default_quantity']);
            $paperType = $orderData['paper_type'] ?? $settings['default_paper'];
            $finish = $orderData['finish'] ?? $settings['default_finish'];
            
            // Use PrintShop::calculatePrice if a print shop is selected
            if ($printShopId) {
                require_once INCLUDES_DIR . '/PrintShop.php';
                $priceData = PrintShop::calculatePrice($printShopId, $quantity, [
                    'paper_type' => $paperType,
                    'finish' => $finish
                ]);
                
                if ($priceData) {
                    $subtotal = $priceData['subtotal'];
                    $setupFee = $priceData['setup_fee'];
                    $shippingFee = $priceData['shipping'];
                    $total = $priceData['total'];
                    $currency = $priceData['currency'] ?? 'USD';
                } else {
                    // Fallback to global settings
                    $pricing = $settings['pricing'];
                    $subtotal = $quantity * $pricing['per_card'];
                    $setupFee = $pricing['setup_fee'];
                    $shippingFee = $pricing['shipping'];
                    $total = $subtotal + $setupFee + $shippingFee;
                    $currency = 'OMR';
                }
            } else {
                // Use global settings pricing
                $pricing = $settings['pricing'];
                $subtotal = $quantity * $pricing['per_card'];
                $setupFee = $pricing['setup_fee'];
                $shippingFee = $pricing['shipping'];
                $total = $subtotal + $setupFee + $shippingFee;
                $currency = 'OMR';
            }

            // Generate order number (ORD-YYYYMMDD-XXXXX)
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));

            // Calculate Cardify commission (30%) and shop payout (70%)
            // Derive payout from total minus commission to avoid dual-rounding mismatch
            $commissionPct = defined('PRINT_ORDER_COMMISSION_PCT') ? (float)PRINT_ORDER_COMMISSION_PCT : 30.00;
            $cardifyCommission = round($total * ($commissionPct / 100), 3);
            $shopPayout = round($total - $cardifyCommission, 3);

            // Insert order using definitive schema (INT AUTO_INCREMENT id)
            $stmt = $pdo->prepare("
                INSERT INTO print_orders
                (order_number, company_id, employee_id, user_id, print_shop_id,
                 quantity, paper_type, finish, card_size,
                 card_front_url, card_back_url, card_template_id,
                 subtotal, setup_fee, shipping_fee, total, cardify_commission, shop_payout, commission_pct, currency,
                 shipping_name, shipping_address, shipping_city, shipping_state,
                 shipping_country, shipping_postal, shipping_phone,
                 status, payment_status, express_delivery, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $orderNumber,
                $orderData['company_id'] ?? null,
                $orderData['employee_id'] ?? null,
                $orderData['user_id'] ?? null,
                $printShopId,
                $quantity,
                $paperType,
                $finish,
                'standard',
                $orderData['card_front_url'] ?? null,
                $orderData['card_back_url'] ?? null,
                $orderData['card_template_id'] ?? null,
                $subtotal,
                $setupFee,
                $shippingFee,
                $total,
                $cardifyCommission,
                $shopPayout,
                $commissionPct,
                $currency ?? 'OMR',
                $orderData['shipping_name'] ?? '',
                $orderData['shipping_address'] ?? '',
                $orderData['shipping_city'] ?? '',
                $orderData['shipping_state'] ?? '',
                $orderData['shipping_country'] ?? 'OM',
                $orderData['shipping_postal'] ?? '',
                $orderData['shipping_phone'] ?? '',
                'pending',
                'pending',
                !empty($orderData['express_delivery']) ? 1 : 0,
                $orderData['notes'] ?? ''
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // If API is configured, send to print shop
            if (!empty($settings['api_endpoint'])) {
                $apiResult = self::sendToAPI($orderId, $orderData, $settings);
                if (!$apiResult['success']) {
                    // Update order with API error but don't fail the order
                    $stmt = $pdo->prepare("UPDATE print_orders SET api_error = ? WHERE id = ?");
                    $stmt->execute([$apiResult['error'], $orderId]);
                }
            }
            
            // Send notification email to selected print shop
            if ($printShopId) {
                require_once INCLUDES_DIR . '/PrintShop.php';
                $printShop = PrintShop::getById($printShopId);
                if ($printShop && !empty($printShop['email'])) {
                    self::sendOrderNotification($orderId, [
                        'shop_email' => $printShop['email'],
                        'shop_name' => $printShop['name']
                    ]);
                }
            } elseif (!empty($settings['shop_email'])) {
                // Fallback to global shop email if no specific print shop
                self::sendOrderNotification($orderId, $settings);
            }
            
            // Send confirmation email to customer
            self::sendOrderConfirmationEmail($orderId, $orderData, $total, $currency ?? 'OMR');

            // Send WhatsApp notifications (customer + print shop)
            if (class_exists('WhatsApp') && WhatsApp::isEnabled()) {
                try {
                    $order = self::getOrder($orderId);
                    $company = null;
                    if (!empty($orderData['company_id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
                        $stmt->execute([$orderData['company_id']]);
                        $company = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    // Notify customer
                    WhatsApp::sendPrintOrderConfirmation($order, $company ?? []);
                    // Notify print shop
                    if ($printShopId) {
                        require_once INCLUDES_DIR . '/PrintShop.php';
                        $ps = PrintShop::getById($printShopId);
                        if ($ps) {
                            $orderWithCompany = array_merge($order ?? [], ['company_name' => $company['name'] ?? '']);
                            WhatsApp::sendPrintShopOrderAlert($orderWithCompany, $ps);
                        }
                    }
                } catch (Throwable $e) {
                    error_log("WhatsApp notification failed: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'total' => $total,
                'message' => 'Print order created successfully'
            ];
            
        } catch (PDOException $e) {
            error_log("PrintShop: Error creating order: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create order'];
        }
    }
    
    /**
     * Send order to print shop API
     */
    private static function sendToAPI($orderId, $orderData, $settings) {
        try {
            $payload = [
                'order_id' => $orderId,
                'quantity' => $orderData['quantity'] ?? $settings['default_quantity'],
                'paper_type' => $orderData['paper_type'] ?? $settings['default_paper'],
                'finish' => $orderData['finish'] ?? $settings['default_finish'],
                'card_front' => $orderData['card_front_url'] ?? null,
                'card_back' => $orderData['card_back_url'] ?? null,
                'shipping' => [
                    'name' => $orderData['shipping_name'] ?? '',
                    'address' => $orderData['shipping_address'] ?? '',
                    'city' => $orderData['shipping_city'] ?? '',
                    'country' => $orderData['shipping_country'] ?? '',
                    'postal' => $orderData['shipping_postal'] ?? ''
                ],
                'callback_url' => $settings['webhook_url'] ?? null
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => rtrim($settings['api_endpoint'], '/') . '/orders',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $settings['api_key'],
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return ['success' => false, 'error' => 'API request failed: ' . $error];
            }
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $responseData = json_decode($response, true);
                
                // Update order with external reference
                $db = Database::getInstance();
                $pdo = $db->getConnection();
                $stmt = $pdo->prepare("UPDATE print_orders SET external_order_id = ?, status = 'submitted' WHERE id = ?");
                $stmt->execute([$responseData['external_id'] ?? null, $orderId]);
                
                return ['success' => true, 'response' => $responseData];
            }
            
            error_log("PrintShop API returned status $httpCode: $response");
            return ['success' => false, 'error' => "API returned status $httpCode"];

        } catch (Exception $e) {
            error_log("PrintShop: API send failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'API request failed'];
        }
    }

    /**
     * Send order notification email to print shop
     */
    private static function sendOrderNotification($orderId, $settings) {
        try {
            require_once INCLUDES_DIR . '/Mailer.php';
            
            $db = Database::getInstance();
            $pdo = $db->getConnection();
            
            // Get order details
            $stmt = $pdo->prepare("
                SELECT po.*, 
                       COALESCE(c.name, 'Unknown') as company_name,
                       c.admin_email as company_email,
                       COALESCE(e.name_en, e.name_ar, '') as employee_name
                FROM print_orders po
                LEFT JOIN companies c ON po.company_id = c.id
                LEFT JOIN employees e ON po.employee_id = e.id
                WHERE po.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) return;
            
            $to = $settings['shop_email'];
            if (empty($to)) return;
            
            require_once INCLUDES_DIR . '/Currency.php';
            $formattedTotal = Currency::format($order['total'] ?? 0, $order['currency'] ?? 'OMR');
            
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
            
            Mailer::sendTemplate($to, 'print_order_received', [
                'site_name' => $siteName,
                'shop_name' => $settings['shop_name'] ?? 'Print Shop',
                'order_id' => $orderId,
                'order_number' => $order['order_number'] ?? "ORD-$orderId",
                'company_name' => $order['company_name'],
                'employee_name' => $order['employee_name'] ?: 'N/A',
                'quantity' => $order['quantity'] ?? 0,
                'paper_type' => ucfirst($order['paper_type'] ?? 'Standard'),
                'finish' => ucfirst(str_replace('_', ' ', $order['finish'] ?? 'Standard')),
                'total' => $formattedTotal,
                'shipping_name' => $order['shipping_name'] ?? '',
                'shipping_address' => $order['shipping_address'] ?? '',
                'shipping_city' => $order['shipping_city'] ?? '',
                'shipping_country' => $order['shipping_country'] ?? '',
                'express' => !empty($order['express_delivery']) ? 'Yes (Express)' : 'Standard',
                'notes' => $order['notes'] ?? 'None'
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to send print shop notification for order #$orderId: " . $e->getMessage());
            // Do not fallback to raw mail(), it's insecure (header injection via HTTP_HOST)
        }
    }
    
    /**
     * Get order by ID
     */
    public static function getOrder($orderId) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_orders WHERE id = ?");
            $stmt->execute([$orderId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Get orders for a company
     */
    public static function getCompanyOrders($companyId, $limit = 50) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("
                SELECT po.*, 
                       COALESCE(e.name_en, e.name_ar, '') as employee_name,
                       e.email as employee_email
                FROM print_orders po
                LEFT JOIN employees e ON po.employee_id = e.id
                WHERE po.company_id = ?
                ORDER BY po.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$companyId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get all orders (super admin)
     */
    public static function getAllOrders($limit = 100, $status = null) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $sql = "
                SELECT po.*, 
                       COALESCE(c.name, 'Unknown') as company_name, 
                       COALESCE(e.name_en, e.name_ar, '') as employee_name
                FROM print_orders po
                LEFT JOIN companies c ON po.company_id = c.id
                LEFT JOIN employees e ON po.employee_id = e.id
            ";
            
            $params = [];
            if ($status) {
                $sql .= " WHERE po.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY po.created_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Update order status
     */
    public static function updateOrderStatus($orderId, $status, $trackingNumber = null) {
        $db = Database::getInstance();
        
        $validStatuses = ['pending', 'confirmed', 'submitted', 'processing', 'printing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        
        try {
            $pdo = $db->getConnection();
            
            $sql = "UPDATE print_orders SET status = ?, updated_at = NOW()";
            $params = [$status];
            
            if ($trackingNumber) {
                $sql .= ", tracking_number = ?";
                $params[] = $trackingNumber;
            }
            
            if ($status === 'shipped') {
                $sql .= ", shipped_at = NOW()";
            } elseif ($status === 'delivered') {
                $sql .= ", delivered_at = NOW()";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $orderId;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Send email notification for status update
            self::sendStatusUpdateEmail($orderId, $status, $trackingNumber);

            // Send WhatsApp status notification to customer
            if (class_exists('WhatsApp') && WhatsApp::isEnabled() && !in_array($status, ['pending', 'confirmed', 'submitted'])) {
                try {
                    $order = self::getOrder($orderId);
                    if ($order) {
                        WhatsApp::sendPrintOrderStatusUpdate($order, $status, $trackingNumber);
                    }
                } catch (Throwable $e) {
                    error_log("WhatsApp status update failed: " . $e->getMessage());
                }
            }

            // Fire print_order_in_production / shipped / delivered via Notifier
            try {
                $newStatus = $status;
                $eventMap = [
                    'in_production' => 'print_order_in_production',
                    'processing'    => 'print_order_in_production',
                    'printing'      => 'print_order_in_production',
                    'shipped'       => 'print_order_shipped',
                    'delivered'     => 'print_order_delivered',
                ];
                if (isset($eventMap[$newStatus])) {
                    require_once INCLUDES_DIR . '/Notifier.php';

                    $order = isset($order) && $order ? $order : self::getOrder($orderId);
                    $company = null;
                    if ($order && !empty($order['company_id'])) {
                        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $order['company_id']]);
                    }

                    $context = [
                        'name'        => $company['name_en'] ?? $company['name'] ?? 'Customer',
                        'orderNumber' => $order['order_number'] ?? ($order['id'] ?? $orderId),
                    ];
                    if ($newStatus === 'shipped') {
                        $context['trackingUrl'] = $_POST['tracking_url'] ?? ($order['tracking_url'] ?? '');
                        $context['carrier']     = $_POST['carrier'] ?? ($order['carrier'] ?? 'the carrier');
                    }
                    if ($newStatus === 'delivered') {
                        // Order creation lives at admin/print.php, company/new-order.php does not exist.
                        $context['reorderUrl'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                            . ($_SERVER['HTTP_HOST'] ?? 'cardify.om') . getBasePath() . 'admin/print.php';
                    }

                    Notifier::send($eventMap[$newStatus], [
                        'name'       => $company['name_en'] ?? $company['name'] ?? 'Customer',
                        'email'      => $company['admin_email'] ?? $company['email'] ?? null,
                        'phone'      => $company['phone'] ?? null,
                        'company_id' => $company['id'] ?? ($order['company_id'] ?? null),
                    ], $context);
                }
            } catch (Throwable $e) {
                error_log('[print_order status] Notifier failed: ' . $e->getMessage());
            }

            return ['success' => true];
        } catch (PDOException $e) {
            error_log("PrintShop: Status update failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update order status'];
        }
    }

    /**
     * Send email notification for order status update
     */
    private static function sendStatusUpdateEmail($orderId, $status, $trackingNumber = null) {
        // Skip notification for pending status
        if ($status === 'pending') {
            return;
        }
        
        try {
            require_once INCLUDES_DIR . '/Mailer.php';
            
            $db = Database::getInstance();
            $pdo = $db->getConnection();
            
            // Get order details with company and print shop info
            $stmt = $pdo->prepare("
                SELECT po.*, 
                       COALESCE(c.name, 'Unknown') as company_name, 
                       c.admin_email,
                       ps.name as print_shop_name,
                       COALESCE(e.name_en, e.name_ar, '') as employee_name, 
                       e.email as employee_email
                FROM print_orders po
                LEFT JOIN companies c ON po.company_id = c.id
                LEFT JOIN print_shops ps ON po.print_shop_id = ps.id
                LEFT JOIN employees e ON po.employee_id = e.id
                WHERE po.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return;
            }
            
            // Determine notification recipient - try shipping email first, then admin email
            $recipientEmail = null;
            if (!empty($order['shipping_email']) && filter_var($order['shipping_email'], FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = $order['shipping_email'];
            } elseif (!empty($order['admin_email']) && filter_var($order['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = $order['admin_email'];
            }
            
            $recipientName = $order['shipping_name'] ?: $order['company_name'] ?: 'Customer';
            
            if (empty($recipientEmail)) {
                error_log("No valid email to send order status update for order #$orderId");
                return;
            }
            
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
            
            // Use specific templates for shipped and delivered statuses
            if ($status === 'shipped') {
                Mailer::sendTemplate($recipientEmail, 'print_order_shipped', [
                    'site_name' => $siteName,
                    'contact_name' => $recipientName,
                    'order_id' => $orderId,
                    'tracking_number' => $trackingNumber ?: 'Not available',
                    'carrier' => 'Standard Shipping',
                    'estimated_delivery' => date('M j, Y', strtotime('+5 days')),
                    'quantity' => $order['quantity'] ?? 0,
                    'print_shop_name' => $order['print_shop_name'] ?? 'Print Shop'
                ]);
            } elseif ($status === 'delivered') {
                Mailer::sendTemplate($recipientEmail, 'print_order_delivered', [
                    'site_name' => $siteName,
                    'contact_name' => $recipientName,
                    'order_id' => $orderId,
                    'quantity' => $order['quantity'] ?? 0,
                    'print_shop_name' => $order['print_shop_name'] ?? 'Print Shop'
                ]);
            } else {
                // Generic status update for other statuses
                $statusFormatted = ucfirst(str_replace('_', ' ', $status));
                
                // Determine status class for email template
                $statusClass = 'info-box';
                if ($status === 'cancelled') {
                    $statusClass = 'warning-box';
                } elseif ($status === 'processing' || $status === 'printing') {
                    $statusClass = 'info-box';
                }
                
                // Tracking info
                $trackingInfo = '';
                if ($trackingNumber) {
                    $trackingInfo = '<div class="info-box"><strong>Tracking Number:</strong> ' . htmlspecialchars($trackingNumber) . '</div>';
                }
                
                Mailer::sendTemplate($recipientEmail, 'print_order_status', [
                    'site_name' => $siteName,
                    'contact_name' => $recipientName,
                    'order_id' => $orderId,
                    'status' => $statusFormatted,
                    'status_class' => $statusClass,
                    'quantity' => $order['quantity'] ?? 0,
                    'print_shop_name' => $order['print_shop_name'] ?? 'Print Shop',
                    'tracking_info' => $trackingInfo
                ]);
            }
            
            // Also send WhatsApp notification if enabled
            try {
                require_once INCLUDES_DIR . '/WhatsApp.php';
                if (WhatsApp::isEnabled() && !empty($order['shipping_phone'])) {
                    WhatsApp::sendPrintOrderStatusUpdate($order, $status, $trackingNumber);
                }
            } catch (Exception $e) {
                // WhatsApp is optional, just log the error
                error_log("WhatsApp notification failed: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            error_log("Failed to send order status email: " . $e->getMessage());
        }
    }
    
    /**
     * Send order confirmation email to customer
     */
    private static function sendOrderConfirmationEmail($orderId, $orderData, $total, $currency = 'USD') {
        try {
            require_once INCLUDES_DIR . '/Mailer.php';
            require_once INCLUDES_DIR . '/Currency.php';
            
            $db = Database::getInstance();
            $pdo = $db->getConnection();
            
            // Get company info
            $companyId = $orderData['company_id'] ?? null;
            $company = null;
            if ($companyId) {
                $stmt = $pdo->prepare("SELECT name_en, admin_email FROM companies WHERE id = ?");
                $stmt->execute([$companyId]);
                $company = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Get print shop info
            $printShopId = $orderData['print_shop_id'] ?? null;
            $printShop = null;
            if ($printShopId) {
                $stmt = $pdo->prepare("SELECT name, city, country, currency FROM print_shops WHERE id = ?");
                $stmt->execute([$printShopId]);
                $printShop = $stmt->fetch(PDO::FETCH_ASSOC);
                // Use print shop's currency if available
                if (!empty($printShop['currency'])) {
                    $currency = $printShop['currency'];
                }
            }
            
            $recipientEmail = $orderData['shipping_email'] ?? ($company['admin_email'] ?? null);
            if (empty($recipientEmail)) {
                return;
            }
            
            $recipientName = $orderData['shipping_name'] ?? ($company['name_en'] ?? 'Customer');
            $companyName = $company['name_en'] ?? 'Your Company';
            $shopName = $printShop['name'] ?? 'Print Shop';
            $shopLocation = $printShop ? implode(', ', array_filter([$printShop['city'], $printShop['country']])) : '';
            
            $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
            
            // Format total with proper currency
            $formattedTotal = Currency::format($total, $currency);
            
            Mailer::sendTemplate($recipientEmail, 'print_order_submitted', [
                'site_name' => $siteName,
                'contact_name' => $recipientName,
                'company_name' => $companyName,
                'order_id' => $orderId,
                'quantity' => $orderData['quantity'] ?? 100,
                'paper_type' => ucfirst($orderData['paper_type'] ?? 'Standard'),
                'finish' => ucfirst(str_replace('_', ' ', $orderData['finish'] ?? 'Standard')),
                'total' => $formattedTotal,
                'print_shop_name' => $shopName,
                'print_shop_location' => $shopLocation
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to send order confirmation email: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate order pricing
     */
    public static function calculatePricing($quantity, $paperType = null, $finish = null) {
        $settings = self::getSettings();
        $pricing = $settings['pricing'];
        
        $perCard = $pricing['per_card'];
        
        // Adjust for premium options
        if ($paperType === 'silk' || $paperType === 'recycled') {
            $perCard *= 1.2;
        }
        
        if ($finish === 'rounded_corners') {
            $perCard *= 1.1;
        } elseif ($finish === 'spot_uv' || $finish === 'foil') {
            $perCard *= 1.5;
        } elseif ($finish === 'embossed') {
            $perCard *= 1.8;
        }
        
        $subtotal = $quantity * $perCard;
        
        return [
            'quantity' => $quantity,
            'per_card' => round($perCard, 3),
            'subtotal' => round($subtotal, 2),
            'setup_fee' => $pricing['setup_fee'],
            'shipping' => $pricing['shipping'],
            'total' => round($subtotal + $pricing['setup_fee'] + $pricing['shipping'], 2)
        ];
    }
    
    /**
     * Check if integration is enabled
     */
    public static function isEnabled() {
        $settings = self::getSettings();
        return $settings['enabled'] ?? false;
    }
    
    /**
     * Get high-quality card URLs for print shop
     * Ensures print shops always get full quality cards regardless of company's plan
     * 
     * @param int $orderId Order ID
     * @param string $employeeId Employee ID
     * @param string $companyId Company ID
     * @return array URLs to high-quality front and back cards
     */
    public static function getHighQualityCards($orderId, $employeeId, $companyId) {
        // Check if high-quality versions exist
        $db = Database::getInstance();
        
        try {
            // Get order to check existing URLs
            $order = self::getOrder($orderId);
            if (!$order) {
                return ['front' => null, 'back' => null];
            }
            
            // Check if high-quality versions already exist
            $frontUrl = $order['card_front_url'] ?? null;
            $backUrl = $order['card_back_url'] ?? null;
            
            // Check for _hq versions
            if ($frontUrl) {
                $hqFront = str_replace('.png', '_hq.png', $frontUrl);
                $hqFrontPath = BASE_DIR . '/' . ltrim($hqFront, '/');
                if (file_exists($hqFrontPath)) {
                    $frontUrl = $hqFront;
                }
            }
            
            if ($backUrl) {
                $hqBack = str_replace('.png', '_hq.png', $backUrl);
                $hqBackPath = BASE_DIR . '/' . ltrim($hqBack, '/');
                if (file_exists($hqBackPath)) {
                    $backUrl = $hqBack;
                }
            }
            
            return [
                'front' => $frontUrl,
                'back' => $backUrl
            ];
        } catch (Exception $e) {
            error_log("PrintShopIntegration::getHighQualityCards error: " . $e->getMessage());
            return ['front' => null, 'back' => null];
        }
    }
    
    /**
     * Generate high-quality card files for print shop order
     * This creates full DPI versions even if the company is on a free plan
     * 
     * @param int $orderId Order ID
     * @param string $employeeId Employee ID
     * @param string $companyId Company ID
     * @return array Result with updated URLs
     */
    public static function ensureHighQualityForOrder($orderId, $employeeId, $companyId) {
        // This method is called when an order is submitted to a print shop
        // It ensures that high-quality versions of the cards are available
        
        // For now, we'll just mark the order for regeneration if needed
        // The actual regeneration happens via the generate_card_html.php endpoint with print_shop=true
        
        $db = Database::getInstance();
        
        try {
            // Update order to indicate print shop quality is required
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("UPDATE print_orders SET require_hq = 1 WHERE id = ?");
            $stmt->execute([$orderId]);
            
            return [
                'success' => true,
                'message' => 'Order marked for high-quality generation'
            ];
        } catch (Exception $e) {
            error_log("PrintShop: ensureHighQualityForOrder failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to mark order for high-quality generation'
            ];
        }
    }

    /**
     * Download endpoint for print shop - always returns full quality
     * This can be used to create a dedicated download link that bypasses plan restrictions
     * 
     * @param int $orderId Order ID
     * @param string $printShopToken Print shop authentication token
     * @return array URLs to full quality files
     */
    public static function getPrintFilesForOrder($orderId, $printShopToken = null) {
        // Validate print shop access
        $order = self::getOrder($orderId);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        // Get employee and company data
        $employeeId = $order['employee_id'];
        $companyId = $order['company_id'];
        
        // Get card files - always return highest quality available
        $frontUrl = $order['card_front_url'];
        $backUrl = $order['card_back_url'];
        
        // Check for PDF versions (higher quality)
        $frontPdf = str_replace('.png', '.pdf', $frontUrl);
        $backPdf = str_replace('.png', '.pdf', $backUrl);
        
        $frontPdfPath = BASE_DIR . '/' . ltrim($frontPdf, '/');
        $backPdfPath = BASE_DIR . '/' . ltrim($backPdf, '/');
        
        $result = [
            'success' => true,
            'order_id' => $orderId,
            'files' => []
        ];
        
        // Prefer PDF over PNG for print shops
        if (file_exists($frontPdfPath)) {
            $result['files']['front_pdf'] = $frontPdf;
        }
        if ($frontUrl && file_exists(BASE_DIR . '/' . ltrim($frontUrl, '/'))) {
            $result['files']['front_png'] = $frontUrl;
        }
        
        if (file_exists($backPdfPath)) {
            $result['files']['back_pdf'] = $backPdf;
        }
        if ($backUrl && file_exists(BASE_DIR . '/' . ltrim($backUrl, '/'))) {
            $result['files']['back_png'] = $backUrl;
        }
        
        // Add order details
        $result['quantity'] = $order['quantity'];
        $result['paper_type'] = $order['paper_type'];
        $result['finish'] = $order['finish'];
        
        return $result;
    }
}
