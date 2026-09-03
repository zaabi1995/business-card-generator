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
        // Operator session: id is `pso:<operator_id>`. The session
        // also carries `ps_print_shop_id`; resolve directly through
        // that so downstream pages get the operator's shop.
        if (is_string($userId) && strpos($userId, 'pso:') === 0 && !empty($_SESSION['ps_print_shop_id'])) {
            return self::getById((int) $_SESSION['ps_print_shop_id']);
        }
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
            // operator_name comes from print_shop_operators when an internal-provider
            // operator placed the order (placed_by_operator_id is set).
            $sql = "
                SELECT po.*,
                       COALESCE(c.name, 'Unknown Company') as company_name,
                       COALESCE(e.name_en, e.name_ar, '') as employee_name,
                       COALESCE(e.position_en, e.position_ar, '') as employee_position,
                       e.email as employee_email,
                       op.name as operator_name
                FROM print_orders po
                LEFT JOIN companies c ON po.company_id = c.id
                LEFT JOIN employees e ON po.employee_id = e.id
                LEFT JOIN print_shop_operators op ON op.id = po.placed_by_operator_id
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
     * Aggregator: one call, all dashboard data.
     *
     * Returns a structured payload covering KPIs, the action queue,
     * internal-provider widgets, credit-risk panel, operator activity
     * and a recent-activity feed. Each section is null/empty when not
     * applicable (no internal-provider flag, no credit accounts, etc.)
     * so the template can branch with isset()/!empty() checks.
     */
    public static function getDashboardData($printShopId, $operatorId = null) {
        $printShopId = (int) $printShopId;
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        $shop = self::getById($printShopId);
        $isInternalProvider = !empty($shop['is_internal_provider']);

        // ---- KPIs -------------------------------------------------
        $kpis = [
            'today_orders'         => 0,
            'today_revenue'        => 0.0,
            'awaiting_action'      => 0,
            'in_production'        => 0,
            'shipped_this_week'    => 0,
            'revenue_30d'          => 0.0,
            'revenue_30d_prev'     => 0.0,
            'outstanding_credit'   => 0.0,
            'credit_limit_total'   => 0.0,
            'erp_sync_failures'    => 0,
        ];
        try {
            $stmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() AND status != 'cancelled'
                             THEN total ELSE 0 END) AS today_revenue,
                    SUM(CASE WHEN status IN ('submitted','processing') THEN 1 ELSE 0 END) AS awaiting_action,
                    SUM(CASE WHEN status = 'printing' THEN 1 ELSE 0 END) AS in_production,
                    SUM(CASE WHEN status = 'shipped' AND shipped_at >= (CURDATE() - INTERVAL 7 DAY)
                             THEN 1 ELSE 0 END) AS shipped_this_week,
                    SUM(CASE WHEN status != 'cancelled'
                             AND created_at >= (NOW() - INTERVAL 30 DAY)
                             THEN total ELSE 0 END) AS revenue_30d,
                    SUM(CASE WHEN status != 'cancelled'
                             AND created_at >= (NOW() - INTERVAL 60 DAY)
                             AND created_at <  (NOW() - INTERVAL 30 DAY)
                             THEN total ELSE 0 END) AS revenue_30d_prev,
                    SUM(CASE WHEN erp_sync_status = 'error' THEN 1 ELSE 0 END) AS erp_sync_failures
                 FROM print_orders WHERE print_shop_id = ?"
            );
            $stmt->execute([$printShopId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $kpis['today_orders']      = (int)   ($row['today_orders']      ?? 0);
            $kpis['today_revenue']     = (float) ($row['today_revenue']     ?? 0);
            $kpis['awaiting_action']   = (int)   ($row['awaiting_action']   ?? 0);
            $kpis['in_production']     = (int)   ($row['in_production']     ?? 0);
            $kpis['shipped_this_week'] = (int)   ($row['shipped_this_week'] ?? 0);
            $kpis['revenue_30d']       = (float) ($row['revenue_30d']       ?? 0);
            $kpis['revenue_30d_prev']  = (float) ($row['revenue_30d_prev']  ?? 0);
            $kpis['erp_sync_failures'] = (int)   ($row['erp_sync_failures'] ?? 0);

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(balance_used),0) AS used,
                        COALESCE(SUM(credit_limit),0) AS approved
                 FROM credit_accounts
                 WHERE print_shop_id = ? AND status = 'approved'"
            );
            $stmt->execute([$printShopId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $kpis['outstanding_credit'] = (float) ($row['used']     ?? 0);
            $kpis['credit_limit_total'] = (float) ($row['approved'] ?? 0);
        } catch (PDOException $e) {
            error_log("PrintShop::getDashboardData kpis: " . $e->getMessage());
        }

        // ---- Revenue 7d sparkline (one row per day, oldest first) -
        $revenueSparkline = [];
        try {
            // Pre-fill 7 days so the chart always shows 7 bars.
            for ($i = 6; $i >= 0; $i--) {
                $revenueSparkline[date('Y-m-d', strtotime("-{$i} days"))] = 0.0;
            }
            $stmt = $pdo->prepare(
                "SELECT DATE(created_at) AS d, COALESCE(SUM(total),0) AS rev
                 FROM print_orders
                 WHERE print_shop_id = ?
                   AND status != 'cancelled'
                   AND created_at >= (CURDATE() - INTERVAL 6 DAY)
                 GROUP BY DATE(created_at)"
            );
            $stmt->execute([$printShopId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($revenueSparkline[$row['d']])) {
                    $revenueSparkline[$row['d']] = (float) $row['rev'];
                }
            }
        } catch (PDOException $e) {
            error_log("PrintShop::getDashboardData sparkline: " . $e->getMessage());
        }

        // ---- Action queue ----------------------------------------
        // 4 stage buckets. Reuse self::getOrders so the company /
        // employee / operator joins stay in one place.
        $actionQueue = [
            'submitted'  => self::getOrders($printShopId, 'submitted',  6),
            'processing' => self::getOrders($printShopId, 'processing', 6),
            'printing'   => self::getOrders($printShopId, 'printing',   6),
            'shipped'    => self::getOrders($printShopId, 'shipped',    6),
        ];

        // ---- Internal provider panel -----------------------------
        $internalProvider = null;
        if ($isInternalProvider) {
            $internalProvider = ['top_clients_30d' => [], 'dormant_count' => 0, 'active_clients_30d' => 0];
            try {
                $stmt = $pdo->prepare(
                    "SELECT po.company_id,
                            COALESCE(c.name, 'Unknown') AS company_name,
                            c.slug AS company_slug,
                            COUNT(*) AS order_count,
                            COALESCE(SUM(po.total),0) AS revenue
                     FROM print_orders po
                     LEFT JOIN companies c ON c.id = po.company_id
                     WHERE po.print_shop_id = ?
                       AND po.status != 'cancelled'
                       AND po.created_at >= (NOW() - INTERVAL 30 DAY)
                       AND po.company_id IS NOT NULL
                     GROUP BY po.company_id, c.name, c.slug
                     ORDER BY order_count DESC, revenue DESC
                     LIMIT 5"
                );
                $stmt->execute([$printShopId]);
                $internalProvider['top_clients_30d'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $internalProvider['active_clients_30d'] = count($internalProvider['top_clients_30d']);

                // Dormant: companies who ordered before but not in 60d.
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM (
                        SELECT po.company_id, MAX(po.created_at) AS last_order
                        FROM print_orders po
                        WHERE po.print_shop_id = ? AND po.company_id IS NOT NULL
                        GROUP BY po.company_id
                        HAVING last_order < (NOW() - INTERVAL 60 DAY)
                     ) AS dormant"
                );
                $stmt->execute([$printShopId]);
                $internalProvider['dormant_count'] = (int) $stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("PrintShop::getDashboardData internal: " . $e->getMessage());
            }
        }

        // ---- Credit risk -----------------------------------------
        $creditRisk = ['top_exposed' => [], 'account_count' => 0];
        try {
            $stmt = $pdo->prepare(
                "SELECT ca.id, ca.company_id, COALESCE(c.name,'Unknown') AS company_name,
                        ca.credit_limit, ca.balance_used, ca.payment_terms
                 FROM credit_accounts ca
                 LEFT JOIN companies c ON c.id = ca.company_id
                 WHERE ca.print_shop_id = ? AND ca.status = 'approved' AND ca.balance_used > 0
                 ORDER BY ca.balance_used DESC LIMIT 3"
            );
            $stmt->execute([$printShopId]);
            $creditRisk['top_exposed'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM credit_accounts WHERE print_shop_id = ? AND status = 'approved'"
            );
            $stmt->execute([$printShopId]);
            $creditRisk['account_count'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("PrintShop::getDashboardData credit: " . $e->getMessage());
        }

        // ---- Operator activity (only when 2+ active operators) ---
        $operatorActivity = null;
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM print_shop_operators WHERE print_shop_id = ? AND status = 'active'"
            );
            $stmt->execute([$printShopId]);
            if ((int) $stmt->fetchColumn() >= 2) {
                $stmt = $pdo->prepare(
                    "SELECT op.id, op.name,
                            COUNT(po.id) AS orders_week,
                            COALESCE(SUM(po.total),0) AS revenue_week
                     FROM print_shop_operators op
                     LEFT JOIN print_orders po
                            ON po.placed_by_operator_id = op.id
                           AND po.print_shop_id = ?
                           AND po.created_at >= (CURDATE() - INTERVAL 7 DAY)
                           AND po.status != 'cancelled'
                     WHERE op.print_shop_id = ? AND op.status = 'active'
                     GROUP BY op.id, op.name
                     ORDER BY orders_week DESC, op.name ASC"
                );
                $stmt->execute([$printShopId, $printShopId]);
                $operatorActivity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (PDOException $e) {
            error_log("PrintShop::getDashboardData operators: " . $e->getMessage());
        }

        // ---- Recent activity feed --------------------------------
        // Union of: ERP sync failures, new credit requests, new orders,
        // new template requests. All scoped to this shop. Latest 10.
        $recentActivity = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT 'erp_failed'    AS type, po.id AS ref_id, po.order_number AS ref_label,
                        COALESCE(c.name,'') AS context, po.erp_last_sync AS at
                 FROM print_orders po
                 LEFT JOIN companies c ON c.id = po.company_id
                 WHERE po.print_shop_id = ? AND po.erp_sync_status = 'error'
                 ORDER BY po.erp_last_sync DESC LIMIT 4"
            );
            $stmt->execute([$printShopId]);
            $recentActivity = array_merge($recentActivity, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $stmt = $pdo->prepare(
                "SELECT 'credit_requested' AS type, ca.id AS ref_id,
                        COALESCE(c.name,'') AS ref_label,
                        CONCAT(CAST(ca.requested_limit AS CHAR),' OMR') AS context,
                        ca.created_at AS at
                 FROM credit_accounts ca
                 LEFT JOIN companies c ON c.id = ca.company_id
                 WHERE ca.print_shop_id = ? AND ca.status = 'pending'
                 ORDER BY ca.created_at DESC LIMIT 4"
            );
            $stmt->execute([$printShopId]);
            $recentActivity = array_merge($recentActivity, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $stmt = $pdo->prepare(
                "SELECT 'new_order' AS type, po.id AS ref_id, po.order_number AS ref_label,
                        COALESCE(c.name,'') AS context, po.created_at AS at
                 FROM print_orders po
                 LEFT JOIN companies c ON c.id = po.company_id
                 WHERE po.print_shop_id = ? AND po.status IN ('pending','submitted')
                 ORDER BY po.created_at DESC LIMIT 4"
            );
            $stmt->execute([$printShopId]);
            $recentActivity = array_merge($recentActivity, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $tmplExists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'shop_template_requests'"
            )->fetchColumn();
            if ($tmplExists) {
                $stmt = $pdo->prepare(
                    "SELECT 'template_requested' AS type, str.id AS ref_id,
                            COALESCE(str.customer_name,'') AS ref_label,
                            COALESCE(str.customer_email,'') AS context,
                            str.created_at AS at
                     FROM shop_template_requests str
                     WHERE str.print_shop_id = ? AND str.status = 'pending'
                     ORDER BY str.created_at DESC LIMIT 4"
                );
                $stmt->execute([$printShopId]);
                $recentActivity = array_merge($recentActivity, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }

            // Sort merged feed DESC by timestamp, cap at 8.
            usort($recentActivity, function ($a, $b) {
                return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? ''));
            });
            $recentActivity = array_slice($recentActivity, 0, 8);
        } catch (PDOException $e) {
            error_log("PrintShop::getDashboardData activity: " . $e->getMessage());
        }

        // ---- Tenant console (internal-provider operations) ------
        // Every Cardify tenant + employee/template/card counts and
        // this-shop print history. Only meaningful in internal-provider
        // mode but cheap enough to compute always (~30 tenants today).
        $tenants = [];
        if ($isInternalProvider) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT
                        c.id, c.name, c.slug, c.country,
                        ct.logo_path, ct.primary_color,
                        COALESCE(emp.cnt, 0)        AS employee_count,
                        COALESCE(tpl.cnt, 0)        AS template_count,
                        COALESCE(gc.cnt, 0)         AS generated_count,
                        COALESCE(po.cnt, 0)         AS order_count,
                        po.last_at                  AS last_order_at,
                        po.last_status              AS last_order_status
                     FROM companies c
                     LEFT JOIN company_themes ct ON ct.company_id = c.id
                     LEFT JOIN (
                         SELECT company_id, COUNT(*) AS cnt FROM employees GROUP BY company_id
                     ) emp ON emp.company_id = c.id
                     LEFT JOIN (
                         SELECT company_id, COUNT(*) AS cnt FROM templates
                         WHERE deleted_at IS NULL GROUP BY company_id
                     ) tpl ON tpl.company_id = c.id
                     LEFT JOIN (
                         SELECT e.company_id, COUNT(*) AS cnt
                         FROM generated_cards gc
                         JOIN employees e ON e.id = gc.employee_id
                         GROUP BY e.company_id
                     ) gc ON gc.company_id = c.id
                     LEFT JOIN (
                         SELECT company_id,
                                COUNT(*)         AS cnt,
                                MAX(created_at)  AS last_at,
                                SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY created_at DESC), ',', 1) AS last_status
                         FROM print_orders WHERE print_shop_id = ?
                         GROUP BY company_id
                     ) po ON po.company_id = c.id
                     WHERE c.status = 'active'
                     ORDER BY
                        (po.last_at IS NULL) ASC,
                        po.last_at DESC,
                        emp.cnt DESC,
                        c.name ASC
                     LIMIT 80"
                );
                $stmt->execute([$printShopId]);
                $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                error_log("PrintShop::getDashboardData tenants: " . $e->getMessage());
            }
        }

        return [
            'kpis'              => $kpis,
            'revenue_sparkline' => $revenueSparkline,
            'action_queue'      => $actionQueue,
            'internal_provider' => $internalProvider,
            'credit_risk'       => $creditRisk,
            'operator_activity' => $operatorActivity,
            'recent_activity'   => $recentActivity,
            'tenant_console'    => $tenants,
        ];
    }

    /**
     * Look up a per-client pricing override row.
     * Returns the decoded pricing JSON or null when none exists.
     */
    public static function getClientPricing($printShopId, $companyId) {
        if (empty($printShopId) || empty($companyId)) {
            return null;
        }
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT pricing FROM print_shop_client_pricing
                 WHERE print_shop_id = ? AND company_id = ? LIMIT 1"
            );
            $stmt->execute([$printShopId, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $decoded = json_decode($row['pricing'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (PDOException $e) {
            error_log("PrintShop::getClientPricing error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve effective pricing JSON for a shop in the context of a
     * specific company. Returns the override when one exists, else
     * the shop default. When the override omits currency / setup_fee
     * / shipping_base, those carry over from the shop default so a
     * tier-only override still works.
     */
    public static function getEffectivePricing($shop, $companyId = null) {
        $base = json_decode($shop['pricing'] ?? '{}', true) ?? [];
        if (empty($companyId)) return $base;
        $override = self::getClientPricing($shop['id'], $companyId);
        if (!$override) return $base;
        foreach (['currency', 'setup_fee', 'shipping_base'] as $k) {
            if (!isset($override[$k]) && isset($base[$k])) {
                $override[$k] = $base[$k];
            }
        }
        return $override;
    }

    /**
     * List all client-specific pricing overrides for a shop, joined
     * with company name + slug for display.
     */
    public static function listClientPricing($printShopId) {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("
                SELECT p.*, c.name AS company_name, c.slug AS company_slug
                FROM print_shop_client_pricing p
                LEFT JOIN companies c ON c.id = p.company_id
                WHERE p.print_shop_id = ?
                ORDER BY c.name ASC
            ");
            $stmt->execute([$printShopId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PrintShop::listClientPricing error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Companies that have placed orders with this shop, used as the
     * candidate list when adding a new override row.
     */
    public static function getClientCompanies($printShopId) {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.slug
                FROM companies c
                WHERE c.id IN (
                    SELECT po.company_id FROM print_orders po
                    WHERE po.print_shop_id = :shop_orders
                    UNION
                    SELECT psc.company_id FROM print_shop_companies psc
                    WHERE psc.print_shop_id = :shop_attached
                )
                ORDER BY c.name ASC
            ");
            $stmt->execute([
                'shop_orders' => $printShopId,
                'shop_attached' => $printShopId,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PrintShop::getClientCompanies error: " . $e->getMessage());
            try {
                $pdo = $db->getConnection();
                $stmt = $pdo->prepare("
                    SELECT c.id, c.name, c.slug
                    FROM companies c
                    INNER JOIN print_orders po ON po.company_id = c.id
                    WHERE po.print_shop_id = ?
                    GROUP BY c.id, c.name, c.slug
                    ORDER BY c.name ASC
                ");
                $stmt->execute([$printShopId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                error_log("PrintShop::getClientCompanies fallback: " . $e2->getMessage());
                return [];
            }
        }
    }

    /**
     * Upsert a per-client pricing override row.
     */
    public static function setClientPricing($printShopId, $companyId, $pricing, $notes = null, $userId = null) {
        if (empty($printShopId) || empty($companyId) || !is_array($pricing)) {
            return ['success' => false, 'error' => 'Missing required fields'];
        }
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $existing = $pdo->prepare(
                "SELECT id FROM print_shop_client_pricing
                 WHERE print_shop_id = ? AND company_id = ? LIMIT 1"
            );
            $existing->execute([$printShopId, $companyId]);
            $id = $existing->fetchColumn();

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE print_shop_client_pricing
                     SET pricing = ?, notes = ?
                     WHERE id = ?"
                );
                $stmt->execute([json_encode($pricing), $notes, $id]);
            } else {
                $id = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
                $stmt = $pdo->prepare(
                    "INSERT INTO print_shop_client_pricing
                     (id, print_shop_id, company_id, pricing, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$id, $printShopId, $companyId, json_encode($pricing), $notes, $userId]);
            }
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            error_log("PrintShop::setClientPricing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save pricing'];
        }
    }

    /**
     * Remove a per-client pricing override (revert to shop defaults).
     */
    public static function deleteClientPricing($printShopId, $companyId) {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "DELETE FROM print_shop_client_pricing
                 WHERE print_shop_id = ? AND company_id = ?"
            );
            $stmt->execute([$printShopId, $companyId]);
            return ['success' => true];
        } catch (PDOException $e) {
            error_log("PrintShop::deleteClientPricing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete pricing'];
        }
    }

    /**
     * Calculate price for an order with quantity tiers.
     *
     * @param string|null $companyId When set, applies any per-client
     *        override row for (shop, company). Falls through to shop
     *        default pricing when no override exists.
     */
    public static function calculatePrice($printShopId, $quantity, $options = [], $companyId = null) {
        $shop = self::getById($printShopId);
        if (!$shop) {
            return null;
        }

        $pricing      = self::getEffectivePricing($shop, $companyId);
        $currency     = $shop['currency'] ?? ($pricing['currency'] ?? 'OMR');
        $basePerCard  = $pricing['per_card'] ?? 0.10;
        $setupFee     = $pricing['setup_fee'] ?? 0;
        $shippingBase = $pricing['shipping_base'] ?? 2.00;
        $paperType    = $options['paper_type'] ?? 'matte';
        $finish       = $options['finish'] ?? 'standard';

        // Min quantity gate (per-client overrides may set a floor)
        $minQty = isset($pricing['min_quantity']) ? (int) $pricing['min_quantity'] : 0;
        if ($minQty > 0 && (int) $quantity < $minQty) {
            return [
                'error'        => 'min_quantity',
                'min_quantity' => $minQty,
                'quantity'     => (int) $quantity,
                'currency'     => $currency,
            ];
        }

        // Pick the tier table. Per-paper-type override beats global tiers.
        // When the override carries `paper_type_pricing`, we DO NOT apply
        // the silk multiplier, since the variant price is already encoded.
        $tierTable             = null;
        $usingPaperTypePricing = false;
        if (!empty($pricing['paper_type_pricing']) && is_array($pricing['paper_type_pricing'])) {
            $ppt = $pricing['paper_type_pricing'][$paperType] ?? null;
            if (is_array($ppt) && !empty($ppt['quantity_tiers'])) {
                $tierTable             = $ppt['quantity_tiers'];
                $usingPaperTypePricing = true;
            }
        }
        if ($tierTable === null) {
            $tierTable = $pricing['quantity_tiers'] ?? null;
        }

        $perCard = self::resolvePerCardFromTiers($tierTable, $quantity, $basePerCard);

        if (!$usingPaperTypePricing && $paperType === 'silk') {
            $perCard *= 1.15;
        }

        $finishMultipliers = [
            'rounded_corners' => 1.10,
            'spot_uv'         => 1.50,
            'foil'            => 1.50,
            'embossed'        => 1.80,
        ];
        if (isset($finishMultipliers[$finish])) {
            $perCard *= $finishMultipliers[$finish];
        }

        $subtotal = $quantity * $perCard;

        $expressShipping = 0;
        if (!empty($options['express']) && !empty($shop['express_available'])) {
            $expressShipping = (float) ($shop['express_fee'] ?? 0);
        }

        $total = $subtotal + $setupFee + $shippingBase + $expressShipping;

        return [
            'quantity'         => $quantity,
            'per_card'         => round($perCard, 3),
            'subtotal'         => round($subtotal, 2),
            'setup_fee'        => $setupFee,
            'shipping'         => $shippingBase,
            'express_shipping' => $expressShipping,
            'total'            => round($total, 2),
            'currency'         => $currency,
            'turnaround_days'  => !empty($options['express']) ? $shop['express_days'] : $shop['turnaround_days'],
        ];
    }

    /**
     * Resolve per-card price from a tier table at a given quantity.
     * Accepts both stored shapes:
     *   { "100": 0.06 }                          (legacy: per-card float)
     *   { "100": {"price": 6.000, "per_card": 0.06} } (new: total-per-design)
     */
    public static function resolvePerCardFromTiers($tiers, $quantity, $basePerCard) {
        if (!is_array($tiers) || empty($tiers)) {
            return $basePerCard;
        }
        $applicable = [];
        foreach ($tiers as $tierQty => $tierVal) {
            $tq = (int) $tierQty;
            if ((int) $quantity < $tq) continue;
            if (is_array($tierVal)) {
                if (isset($tierVal['per_card'])) {
                    $applicable[$tq] = (float) $tierVal['per_card'];
                } elseif (isset($tierVal['price']) && $tq > 0) {
                    $applicable[$tq] = (float) $tierVal['price'] / $tq;
                }
            } else {
                $applicable[$tq] = (float) $tierVal;
            }
        }
        if (empty($applicable)) {
            return $basePerCard;
        }
        krsort($applicable);
        return reset($applicable);
    }

    /**
     * Legacy wrapper kept for callers that pass the full $pricing array.
     * @deprecated Use resolvePerCardFromTiers() with the tier table directly.
     */
    public static function getQuantityTierPrice($pricing, $quantity, $basePrice) {
        return self::resolvePerCardFromTiers($pricing['quantity_tiers'] ?? null, $quantity, $basePrice);
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
