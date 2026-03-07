<?php
/**
 * Audit Log Helper
 * Tracks all sensitive changes for compliance and transparency
 */
class AuditLog {
    private static $db = null;

    public static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }

    /**
     * Log an action
     * 
     * @param string $action Action performed (e.g., 'create', 'update', 'delete', 'login')
     * @param string $entityType Entity type (e.g., 'company', 'user', 'order', 'template', 'plan')
     * @param string|null $entityId Entity ID
     * @param array|null $beforeData Data before change
     * @param array|null $afterData Data after change
     * @param string|null $companyId Company ID (if applicable)
     * @return bool Success
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $beforeData = null,
        ?array $afterData = null,
        ?string $companyId = null
    ): bool {
        self::init();

        if (!self::$db || !self::$db->isConnected()) {
            return false;
        }

        // Get current user info
        $actorId = null;
        $actorEmail = null;
        $actorRole = null;

        if (class_exists('Auth')) {
            $currentUser = Auth::getCurrentUser();
            if ($currentUser) {
                $actorId = $currentUser['id'] ?? null;
                $actorEmail = $currentUser['email'] ?? null;
                $actorRole = $currentUser['role'] ?? null;
            }
        }

        // Fallback to session
        if (!$actorId && isset($_SESSION['user_id'])) {
            $actorId = $_SESSION['user_id'];
            $actorEmail = $_SESSION['user_email'] ?? null;
            $actorRole = $_SESSION['user_role'] ?? null;
        }

        // Get IP and user agent
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Sanitize data (remove sensitive fields)
        $sensitiveFields = ['password', 'password_hash', 'token', 'secret', 'api_key'];
        
        if ($beforeData) {
            $beforeData = self::sanitizeData($beforeData, $sensitiveFields);
        }
        if ($afterData) {
            $afterData = self::sanitizeData($afterData, $sensitiveFields);
        }

        try {
            self::$db->insert('audit_logs', [
                'id' => self::generateUUID(),
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_role' => $actorRole,
                'company_id' => $companyId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_data' => $beforeData ? json_encode($beforeData) : null,
                'after_data' => $afterData ? json_encode($afterData) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (Exception $e) {
            error_log("AuditLog error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log company-related action
     */
    public static function logCompany(string $action, string $companyId, ?array $before = null, ?array $after = null): bool {
        return self::log($action, 'company', $companyId, $before, $after, $companyId);
    }

    /**
     * Log user-related action
     */
    public static function logUser(string $action, string $userId, ?array $before = null, ?array $after = null, ?string $companyId = null): bool {
        return self::log($action, 'user', $userId, $before, $after, $companyId);
    }

    /**
     * Log order-related action
     */
    public static function logOrder(string $action, string $orderId, ?array $before = null, ?array $after = null, ?string $companyId = null): bool {
        return self::log($action, 'order', $orderId, $before, $after, $companyId);
    }

    /**
     * Log template-related action
     */
    public static function logTemplate(string $action, string $templateId, ?array $before = null, ?array $after = null, ?string $companyId = null): bool {
        return self::log($action, 'template', $templateId, $before, $after, $companyId);
    }

    /**
     * Log plan/billing-related action
     */
    public static function logPlan(string $action, string $planId, ?array $before = null, ?array $after = null, ?string $companyId = null): bool {
        return self::log($action, 'plan', $planId, $before, $after, $companyId);
    }

    /**
     * Log payment-related action
     */
    public static function logPayment(string $action, string $paymentId, ?array $before = null, ?array $after = null, ?string $companyId = null): bool {
        return self::log($action, 'payment', $paymentId, $before, $after, $companyId);
    }

    /**
     * Get audit logs with filters
     * Supports: company_id, actor_id, actor (email search), action (single or comma-separated),
     *           entity_type, entity_id, date_from, date_to
     */
    public static function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array {
        self::init();

        if (!self::$db || !self::$db->isConnected()) {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['company_id'])) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = $filters['company_id'];
        }

        if (!empty($filters['actor_id'])) {
            $where[] = 'actor_id = :actor_id';
            $params['actor_id'] = $filters['actor_id'];
        }

        // Actor search (by email)
        if (!empty($filters['actor'])) {
            $where[] = 'actor_email LIKE :actor';
            $params['actor'] = '%' . $filters['actor'] . '%';
        }

        // Support comma-separated actions (e.g., "login,logout")
        if (!empty($filters['action'])) {
            $actions = array_map('trim', explode(',', $filters['action']));
            if (count($actions) === 1) {
                $where[] = 'action = :action';
                $params['action'] = $actions[0];
            } else {
                $placeholders = [];
                foreach ($actions as $i => $action) {
                    $key = 'action_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $action;
                }
                $where[] = 'action IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = :entity_id';
            $params['entity_id'] = $filters['entity_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $sql = "SELECT * FROM audit_logs WHERE " . implode(' AND ', $where) . 
               " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        try {
            return self::$db->fetchAll($sql, $params);
        } catch (Exception $e) {
            error_log("AuditLog query error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count logs with filters
     * Supports same filters as getLogs
     */
    public static function countLogs(array $filters = []): int {
        self::init();

        if (!self::$db || !self::$db->isConnected()) {
            return 0;
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['company_id'])) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = $filters['company_id'];
        }

        // Actor search (by email)
        if (!empty($filters['actor'])) {
            $where[] = 'actor_email LIKE :actor';
            $params['actor'] = '%' . $filters['actor'] . '%';
        }

        // Support comma-separated actions
        if (!empty($filters['action'])) {
            $actions = array_map('trim', explode(',', $filters['action']));
            if (count($actions) === 1) {
                $where[] = 'action = :action';
                $params['action'] = $actions[0];
            } else {
                $placeholders = [];
                foreach ($actions as $i => $action) {
                    $key = 'action_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $action;
                }
                $where[] = 'action IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $sql = "SELECT COUNT(*) as count FROM audit_logs WHERE " . implode(' AND ', $where);

        try {
            $result = self::$db->fetchOne($sql, $params);
            return (int)($result['count'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Remove sensitive fields from data
     */
    private static function sanitizeData(array $data, array $sensitiveFields): array {
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[REDACTED]';
            }
        }
        return $data;
    }

    /**
     * Generate UUID
     */
    private static function generateUUID(): string {
        // Delegate to the global helper which uses cryptographically secure random_bytes()
        return \generateUUID();
    }
}
