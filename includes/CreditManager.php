<?php
/**
 * Credit Account Manager
 * Manages credit accounts per company-per-printshop
 * Print shops control limits, terms, approvals
 */

class CreditManager {

    /**
     * Company requests credit from a print shop
     */
    public static function requestCredit(
        string $companyId,
        int $printShopId,
        float $requestedLimit,
        ?string $notes = null
    ): array {
        $db = Database::getInstance();

        $existing = self::getAccount($companyId, $printShopId);
        if ($existing) {
            if ($existing['status'] === 'pending') {
                return ['error' => 'Credit request already pending'];
            }
            if ($existing['status'] === 'approved') {
                return ['error' => 'Credit account already active'];
            }
            // Rejected/suspended — allow re-request
            $db->update('credit_accounts',
                [
                    'status' => 'pending',
                    'requested_limit' => $requestedLimit,
                    'request_notes' => $notes,
                    'rejected_reason' => null
                ],
                'id = :id', ['id' => $existing['id']]
            );
            return ['credit_account_id' => $existing['id'], 'status' => 'pending'];
        }

        $id = generateUUID();
        $db->insert('credit_accounts', [
            'id' => $id,
            'company_id' => $companyId,
            'print_shop_id' => $printShopId,
            'requested_limit' => $requestedLimit,
            'request_notes' => $notes,
            'status' => 'pending'
        ]);

        return ['credit_account_id' => $id, 'status' => 'pending'];
    }

    /**
     * Print shop approves credit request
     */
    public static function approve(
        string $creditAccountId,
        float $approvedLimit,
        string $paymentTerms,
        string $approvedBy
    ): bool {
        $db = Database::getInstance();
        $count = $db->update('credit_accounts',
            [
                'status' => 'approved',
                'credit_limit' => $approvedLimit,
                'payment_terms' => $paymentTerms,
                'approved_by' => $approvedBy,
                'approved_at' => date('Y-m-d H:i:s')
            ],
            'id = :id AND status = :status',
            ['id' => $creditAccountId, 'status' => 'pending']
        );
        return $count > 0;
    }

    /**
     * Print shop rejects credit request
     */
    public static function reject(
        string $creditAccountId,
        string $reason,
        string $rejectedBy
    ): bool {
        $db = Database::getInstance();
        $count = $db->update('credit_accounts',
            [
                'status' => 'rejected',
                'rejected_reason' => $reason,
                'approved_by' => $rejectedBy
            ],
            'id = :id AND status = :status',
            ['id' => $creditAccountId, 'status' => 'pending']
        );
        return $count > 0;
    }

    /**
     * Print shop suspends credit account
     */
    public static function suspend(string $creditAccountId): bool {
        $db = Database::getInstance();
        $count = $db->update('credit_accounts',
            ['status' => 'suspended'],
            'id = :id AND status = :status',
            ['id' => $creditAccountId, 'status' => 'approved']
        );
        return $count > 0;
    }

    /**
     * Reactivate a suspended credit account
     */
    public static function reactivate(string $creditAccountId): bool {
        $db = Database::getInstance();
        $count = $db->update('credit_accounts',
            ['status' => 'approved'],
            'id = :id AND status = :status',
            ['id' => $creditAccountId, 'status' => 'suspended']
        );
        return $count > 0;
    }

    /**
     * Charge an order to credit account
     * Uses DB transaction with SELECT ... FOR UPDATE
     */
    public static function charge(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array {
        $db = Database::getInstance();

        $db->beginTransaction();
        try {
            // Lock the credit account row
            $conn = $db->getConnection();
            $stmt = $conn->prepare("SELECT * FROM credit_accounts WHERE id = ? AND status = 'approved' FOR UPDATE");
            $stmt->execute([$creditAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                $db->rollback();
                return ['error' => 'Credit account not active'];
            }

            $available = (float)$account['credit_limit'] - (float)$account['balance_used'];
            if ($amount > $available) {
                $db->rollback();
                return ['error' => 'Insufficient credit. Available: ' . number_format($available, 3)];
            }

            $newBalance = (float)$account['balance_used'] + $amount;

            // Update balance
            $stmt = $conn->prepare("UPDATE credit_accounts SET balance_used = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $creditAccountId]);

            // Create transaction record
            $txId = generateUUID();
            $stmt = $conn->prepare(
                "INSERT INTO credit_transactions (id, credit_account_id, order_id, type, amount, balance_after, created_at)
                 VALUES (?, ?, ?, 'charge', ?, ?, NOW())"
            );
            $stmt->execute([$txId, $creditAccountId, $orderId, $amount, $newBalance]);

            $db->commit();
            return ['transaction_id' => $txId, 'balance_after' => $newBalance];
        } catch (Exception $e) {
            $db->rollback();
            error_log("CreditManager::charge failed: " . $e->getMessage());
            return ['error' => 'Transaction failed'];
        }
    }

    /**
     * Record payment received (print shop marks company paid)
     */
    public static function recordPayment(
        string $creditAccountId,
        float $amount,
        ?string $notes = null,
        ?string $recordedBy = null
    ): array {
        $db = Database::getInstance();
        $account = self::getAccountById($creditAccountId);

        if (!$account) {
            return ['error' => 'Credit account not found'];
        }

        $newBalance = max(0, (float)$account['balance_used'] - $amount);
        $txId = generateUUID();

        $db->update('credit_accounts',
            ['balance_used' => $newBalance],
            'id = :id', ['id' => $creditAccountId]
        );

        $db->insert('credit_transactions', [
            'id' => $txId,
            'credit_account_id' => $creditAccountId,
            'type' => 'payment',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'notes' => $notes,
            'recorded_by' => $recordedBy
        ]);

        return ['transaction_id' => $txId, 'balance_after' => $newBalance];
    }

    /**
     * Refund a credit charge (order cancelled)
     */
    public static function refund(
        string $creditAccountId,
        int $orderId,
        float $amount
    ): array {
        $db = Database::getInstance();
        $account = self::getAccountById($creditAccountId);

        if (!$account) {
            return ['error' => 'Credit account not found'];
        }

        $newBalance = max(0, (float)$account['balance_used'] - $amount);
        $txId = generateUUID();

        $db->update('credit_accounts',
            ['balance_used' => $newBalance],
            'id = :id', ['id' => $creditAccountId]
        );

        $db->insert('credit_transactions', [
            'id' => $txId,
            'credit_account_id' => $creditAccountId,
            'order_id' => $orderId,
            'type' => 'refund',
            'amount' => $amount,
            'balance_after' => $newBalance
        ]);

        return ['transaction_id' => $txId, 'balance_after' => $newBalance];
    }

    // --- Query methods ---

    public static function getAccount(string $companyId, int $printShopId): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM credit_accounts WHERE company_id = :cid AND print_shop_id = :psid",
            ['cid' => $companyId, 'psid' => $printShopId]
        );
        return $row ?: null;
    }

    public static function getAccountById(string $id): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM credit_accounts WHERE id = :id", ['id' => $id]);
        return $row ?: null;
    }

    public static function getShopAccounts(int $printShopId, ?string $status = null): array {
        $db = Database::getInstance();
        $sql = "SELECT ca.*, c.name as company_name, c.admin_email as company_email
                FROM credit_accounts ca
                JOIN companies c ON c.id = ca.company_id
                WHERE ca.print_shop_id = :psid";
        $params = ['psid' => $printShopId];
        if ($status) {
            $sql .= " AND ca.status = :status";
            $params['status'] = $status;
        }
        $sql .= " ORDER BY ca.created_at DESC";
        return $db->fetchAll($sql, $params);
    }

    public static function getCompanyAccounts(string $companyId): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT ca.*, ps.name as shop_name
             FROM credit_accounts ca
             JOIN print_shops ps ON ps.id = ca.print_shop_id
             WHERE ca.company_id = :cid ORDER BY ca.created_at DESC",
            ['cid' => $companyId]
        );
    }

    public static function getLedger(string $creditAccountId, int $limit = 50): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT ct.*, po.order_number
             FROM credit_transactions ct
             LEFT JOIN print_orders po ON po.id = ct.order_id
             WHERE ct.credit_account_id = :caid
             ORDER BY ct.created_at DESC LIMIT " . (int)$limit,
            ['caid' => $creditAccountId]
        );
    }

    public static function getAvailable(string $creditAccountId): float {
        $account = self::getAccountById($creditAccountId);
        if (!$account || $account['status'] !== 'approved') {
            return 0.0;
        }
        return max(0, (float)$account['credit_limit'] - (float)$account['balance_used']);
    }

    public static function getOutstandingSummary(int $printShopId): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT ca.id, ca.company_id, c.name as company_name,
                    ca.credit_limit, ca.balance_used, ca.payment_terms,
                    (ca.credit_limit - ca.balance_used) as available
             FROM credit_accounts ca
             JOIN companies c ON c.id = ca.company_id
             WHERE ca.print_shop_id = :psid AND ca.status = 'approved' AND ca.balance_used > 0
             ORDER BY ca.balance_used DESC",
            ['psid' => $printShopId]
        );
    }
}
