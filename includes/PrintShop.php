<?php
/**
 * PrintShop Class - Manages print shop marketplace
 */

class PrintShop {
    
    /**
     * Get all active print shops
     */
    public static function getAll($status = 'active', $limit = 50) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $sql = "SELECT * FROM print_shops WHERE status = ? ORDER BY featured DESC, name ASC LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get featured print shops
     */
    public static function getFeatured($limit = 6) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_shops WHERE status = 'active' AND featured = 1 ORDER BY name ASC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get print shop by ID
     */
    public static function getById($id) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_shops WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Get print shop by slug
     */
    public static function getBySlug($slug) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_shops WHERE slug = ?");
            $stmt->execute([$slug]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Get print shop by user ID
     */
    public static function getByUserId($userId) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_shops WHERE user_id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Create a new print shop
     */
    public static function create($data) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            
            // Generate slug if not provided
            if (empty($data['slug'])) {
                $data['slug'] = self::generateSlug($data['name']);
            }
            
            // Default services
            $defaultServices = ['business_cards'];
            $defaultPaperTypes = ['matte', 'glossy', 'silk', 'uncoated'];
            $defaultFinishes = ['standard', 'rounded_corners'];
            $defaultCardSizes = ['standard' => ['width' => 3.5, 'height' => 2, 'unit' => 'in']];
            $defaultPricing = [
                'per_card' => 0.10,
                'setup_fee' => 5.00,
                'shipping_base' => 10.00
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO print_shops 
                (name, slug, description, logo_path, email, phone, website, 
                 address, city, state, country, postal_code, user_id,
                 paper_types, finishes, pricing_tiers,
                 currency, min_quantity, turnaround_days, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['slug'],
                $data['description'] ?? null,
                $data['logo_path'] ?? $data['logo_url'] ?? null,
                $data['email'],
                $data['phone'] ?? null,
                $data['website'] ?? null,
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['country'] ?? null,
                $data['postal_code'] ?? null,
                $data['user_id'] ?? null,
                json_encode($data['paper_types'] ?? $defaultPaperTypes),
                json_encode($data['finishes'] ?? $defaultFinishes),
                json_encode($data['pricing_tiers'] ?? $data['pricing'] ?? $defaultPricing),
                $data['currency'] ?? 'OMR',
                $data['min_quantity'] ?? $data['min_order_quantity'] ?? 50,
                $data['turnaround_days'] ?? 5,
                $data['status'] ?? 'pending'
            ]);
            
            return [
                'success' => true,
                'id' => $pdo->lastInsertId()
            ];
            
        } catch (PDOException $e) {
            error_log("PrintShop::create error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to create print shop'];
        }
    }

    /**
     * Update print shop
     */
    public static function update($id, $data) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            
            $fields = [];
            $values = [];
            
            $allowedFields = [
                'name', 'slug', 'description', 'logo_path', 'email', 'phone', 'website',
                'address', 'city', 'state', 'country', 'postal_code',
                'paper_types', 'finishes', 'pricing_tiers', 'base_price_per_card',
                'setup_fee', 'shipping_fee',
                'currency', 'min_quantity', 'turnaround_days',
                'express_available', 'express_days', 'express_fee',
                'tagline', 'facebook', 'instagram', 'twitter', 'whatsapp',
                'erp_enabled', 'erp_system', 'erp_url', 'erp_api_key',
                'status', 'featured', 'verified'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $value = $data[$field];
                    // JSON encode arrays
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    $values[] = $value;
                }
            }
            
            if (empty($fields)) {
                return ['success' => false, 'error' => 'No fields to update'];
            }
            
            $values[] = $id;
            $sql = "UPDATE print_shops SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            error_log("PrintShop::update error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to update print shop'];
        }
    }

    /**
     * Approve a print shop
     */
    public static function approve($id, $approvedBy = null) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("UPDATE print_shops SET status = 'active', verified = 1 WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("PrintShop::approve error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to approve print shop'];
        }
    }

    /**
     * Suspend a print shop
     */
    public static function suspend($id) {
        return self::update($id, ['status' => 'suspended']);
    }
    
    /**
     * Get orders for a print shop
     */
    public static function getOrders($printShopId, $status = null, $limit = 50) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            
            // Use COALESCE for column names - companies table uses 'name', employees use 'name_en'
            $sql = "
                SELECT po.*, 
                       COALESCE(c.name, 'Unknown Company') as company_name, 
                       COALESCE(e.name_en, e.name_ar, '') as employee_name,
                       COALESCE(e.position_en, e.position_ar, '') as employee_position,
                       e.email as employee_email
                FROM print_orders po
                LEFT JOIN companies c ON po.company_id = c.id
                LEFT JOIN employees e ON po.employee_id = e.id
                WHERE po.print_shop_id = ?
            ";
            $params = [$printShopId];
            
            if ($status) {
                $sql .= " AND po.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY po.created_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PrintShop::getOrders error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get order statistics for a print shop
     */
    public static function getStats($printShopId) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            
            $stats = [
                'total_orders' => 0,
                'pending_orders' => 0,
                'completed_orders' => 0,
                'total_revenue' => 0,
                'this_month_orders' => 0,
                'this_month_revenue' => 0
            ];
            
            // Total orders
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM print_orders WHERE print_shop_id = ?");
            $stmt->execute([$printShopId]);
            $stats['total_orders'] = (int)$stmt->fetchColumn();
            
            // Pending orders
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM print_orders WHERE print_shop_id = ? AND status IN ('pending', 'submitted', 'processing', 'printing')");
            $stmt->execute([$printShopId]);
            $stats['pending_orders'] = (int)$stmt->fetchColumn();
            
            // Completed orders
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM print_orders WHERE print_shop_id = ? AND status = 'delivered'");
            $stmt->execute([$printShopId]);
            $stats['completed_orders'] = (int)$stmt->fetchColumn();
            
            // Total revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM print_orders WHERE print_shop_id = ? AND status != 'cancelled'");
            $stmt->execute([$printShopId]);
            $stats['total_revenue'] = (float)$stmt->fetchColumn();
            
            // This month
            $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM print_orders WHERE print_shop_id = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
            $stmt->execute([$printShopId]);
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stats['this_month_orders'] = (int)$row[0];
            $stats['this_month_revenue'] = (float)$row[1];
            
            return $stats;
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Calculate price for an order with quantity tiers
     */
    public static function calculatePrice($printShopId, $quantity, $options = []) {
        $shop = self::getById($printShopId);
        if (!$shop) {
            return null;
        }
        
        $pricing = json_decode($shop['pricing'], true) ?? [];
        $basePerCard = $pricing['per_card'] ?? 0.10;
        $setupFee = $pricing['setup_fee'] ?? 0;
        $shippingBase = $pricing['shipping_base'] ?? 2.00;
        
        // Apply quantity tier pricing if available
        $perCard = self::getQuantityTierPrice($pricing, $quantity, $basePerCard);
        
        // Apply paper type multiplier
        $paperType = $options['paper_type'] ?? 'matte';
        if ($paperType === 'silk') {
            $perCard *= 1.15;
        }
        
        // Apply finish multiplier
        $finish = $options['finish'] ?? 'standard';
        $finishMultipliers = [
            'rounded_corners' => 1.10,
            'spot_uv' => 1.50,
            'foil' => 1.50,
            'embossed' => 1.80
        ];
        if (isset($finishMultipliers[$finish])) {
            $perCard *= $finishMultipliers[$finish];
        }
        
        $subtotal = $quantity * $perCard;
        
        // Express shipping
        $expressShipping = 0;
        if (!empty($options['express']) && !empty($shop['express_available'])) {
            $expressShipping = (float)($shop['express_fee'] ?? 0);
        }
        
        $total = $subtotal + $setupFee + $shippingBase + $expressShipping;
        
        return [
            'quantity' => $quantity,
            'per_card' => round($perCard, 3),
            'subtotal' => round($subtotal, 2),
            'setup_fee' => $setupFee,
            'shipping' => $shippingBase,
            'express_shipping' => $expressShipping,
            'total' => round($total, 2),
            'currency' => $shop['currency'] ?? 'USD',
            'turnaround_days' => !empty($options['express']) ? $shop['express_days'] : $shop['turnaround_days']
        ];
    }
    
    /**
     * Get price per card based on quantity tiers
     */
    public static function getQuantityTierPrice($pricing, $quantity, $basePrice) {
        $tiers = $pricing['quantity_tiers'] ?? null;
        
        if (!$tiers || !is_array($tiers)) {
            return $basePrice;
        }
        
        // Sort tiers by quantity (descending) to find the best applicable tier
        $applicableTiers = [];
        foreach ($tiers as $tierQty => $tierPrice) {
            if ($quantity >= (int)$tierQty) {
                $applicableTiers[(int)$tierQty] = (float)$tierPrice;
            }
        }
        
        if (empty($applicableTiers)) {
            return $basePrice;
        }
        
        // Return the price for the highest applicable tier
        krsort($applicableTiers);
        return reset($applicableTiers);
    }
    
    /**
     * Get all quantity tiers for a shop (for display)
     */
    public static function getQuantityTiers($printShopId) {
        $shop = self::getById($printShopId);
        if (!$shop) {
            return [];
        }
        
        $pricingTiers = json_decode($shop['pricing_tiers'] ?? '{}', true) ?? [];
        $tiers = $pricingTiers['quantity_tiers'] ?? $pricingTiers;
        
        // Default tiers if none set
        if (empty($tiers)) {
            $basePrice = (float)($shop['base_price_per_card'] ?? 0.10);
            $tiers = [
                '50' => $basePrice,
                '100' => $basePrice * 0.95,
                '200' => $basePrice * 0.90,
                '250' => $basePrice * 0.88,
                '500' => $basePrice * 0.85,
                '1000' => $basePrice * 0.80,
                '2000' => $basePrice * 0.75
            ];
        }
        
        ksort($tiers, SORT_NUMERIC);
        return $tiers;
    }
    
    /**
     * Generate URL-safe slug
     */
    public static function generateSlug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Check if slug exists
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $baseSlug = $slug;
            $counter = 1;
            
            while (true) {
                $stmt = $pdo->prepare("SELECT id FROM print_shops WHERE slug = ?");
                $stmt->execute([$slug]);
                if ($stmt->rowCount() == 0) {
                    break;
                }
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        return $slug;
    }
    
    /**
     * Upload logo for print shop
     */
    public static function uploadLogo($shopId, $file) {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP'];
        }
        
        // Validate file size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'error' => 'File too large. Maximum 2MB'];
        }
        
        // Create upload directory
        $uploadDir = BASE_DIR . '/uploads/print_shops/' . $shopId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Get extension
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $ext = $extensions[$mimeType] ?? 'png';
        
        // Delete old logo files
        foreach (glob($uploadDir . '/logo.*') as $oldFile) {
            unlink($oldFile);
        }
        
        // Save file
        $filename = 'logo.' . $ext;
        $filePath = $uploadDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Failed to save file'];
        }
        @chmod($filePath, 0644);

        // Update database
        $logoPath = '/uploads/print_shops/' . $shopId . '/' . $filename;
        $result = self::update($shopId, ['logo_path' => $logoPath]);
        
        if ($result['success']) {
            return ['success' => true, 'logo_path' => $logoPath];
        }
        
        return $result;
    }
    
    /**
     * Delete logo for print shop
     */
    public static function deleteLogo($shopId) {
        $uploadDir = BASE_DIR . '/uploads/print_shops/' . $shopId;
        
        // Delete logo files
        foreach (glob($uploadDir . '/logo.*') as $file) {
            unlink($file);
        }
        
        // Update database
        return self::update($shopId, ['logo_path' => null]);
    }
    
    /**
     * Search print shops
     */
    public static function search($query, $filters = []) {
        $db = Database::getInstance();
        
        try {
            $pdo = $db->getConnection();
            
            $sql = "SELECT * FROM print_shops WHERE status = 'active'";
            $params = [];
            
            if (!empty($query)) {
                $sql .= " AND (name LIKE ? OR description LIKE ? OR city LIKE ? OR country LIKE ?)";
                $escapedQuery = str_replace(['%', '_'], ['\\%', '\\_'], $query);
                $searchTerm = "%$escapedQuery%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            if (!empty($filters['country'])) {
                $sql .= " AND country = ?";
                $params[] = $filters['country'];
            }
            
            if (!empty($filters['express'])) {
                $sql .= " AND express_available = 1";
            }
            
            if (!empty($filters['verified'])) {
                $sql .= " AND verified = 1";
            }
            
            $sql .= " ORDER BY featured DESC, name ASC LIMIT 50";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
}
