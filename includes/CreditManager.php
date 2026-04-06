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
     * Print shop adjusts credit limit and/or payment terms on an active account
     */
    public static function adjustLimit(
        string $creditAccountId,
        float $newLimit,
        ?string $paymentTerms = null,
        ?float $exposureLimit = null
    ): array {
        $db = Database::getInstance();
        $account = self::getAccountById($creditAccountId);
        if (!$account || $account['status'] !== 'approved') {
            return ['error' => 'Account not active'];
        }
        if ($newLimit < (float)$account['balance_used']) {
            return ['error' => 'New limit cannot be less than current outstanding balance (' . number_format($account['balance_used'], 3) . ')'];
        }
        $fields = ['credit_limit' => $newLimit];
        if ($paymentTerms !== null) {
            $fields['payment_terms'] = $paymentTerms;
        }
        if ($exposureLimit !== null) {
            $fields['exposure_limit'] = $exposureLimit > 0 ? $exposureLimit : null;
        }
        $db->update('credit_accounts', $fields, 'id = :id', ['id' => $creditAccountId]);
        return ['success' => true];
    }

    /**
     * Upload a PO document against a credit account (at request time)
     */
    public static function uploadPO(string $creditAccountId, array $file, ?string $poNumber = null): array {
        $account = self::getAccountById($creditAccountId);
        if (!$account) {
            return ['error' => 'Account not found'];
        }

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($file['type'], $allowedTypes)) {
            return ['error' => 'Invalid file type. Allowed: PDF, JPG, PNG'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['error' => 'File too large. Maximum 5MB'];
        }

        $uploadDir = dirname(__DIR__) . '/uploads/credit_pos/' . $account['company_id'];
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = 'ca_' . $creditAccountId . '_' . time() . '.' . $ext;
        $relativePath = 'uploads/credit_pos/' . $account['company_id'] . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/' . $relativePath)) {
            return ['error' => 'Failed to save file'];
        }

        $db = Database::getInstance();
        $db->update('credit_accounts', [
            'po_file_path' => $relativePath,
            'po_number' => $poNumber,
            'po_received_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $creditAccountId]);

        return ['po_file_path' => $relativePath];
    }

    /**
     * Record payment with optional proof document upload
     */
    public static function recordPaymentWithProof(
        string $creditAccountId,
        float $amount,
        ?string $notes = null,
        ?string $recordedBy = null,
        ?array $proofFile = null
    ): array {
        $proofPath = null;
        if ($proofFile && $proofFile['error'] === UPLOAD_ERR_OK) {
            $account = self::getAccountById($creditAccountId);
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($proofFile['type'], $allowedTypes)) {
                return ['error' => 'Invalid proof file type. Allowed: PDF, JPG, PNG'];
            }
            if ($proofFile['size'] > 5 * 1024 * 1024) {
                return ['error' => 'Proof file too large. Maximum 5MB'];
            }
            $uploadDir = dirname(__DIR__) . '/uploads/payment_proofs/' . ($account['company_id'] ?? 'unknown');
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($proofFile['name'], PATHINFO_EXTENSION));
            $safeName = 'proof_' . $creditAccountId . '_' . time() . '.' . $ext;
            $proofPath = 'uploads/payment_proofs/' . ($account['company_id'] ?? 'unknown') . '/' . $safeName;
            if (!move_uploaded_file($proofFile['tmp_name'], dirname(__DIR__) . '/' . $proofPath)) {
                return ['error' => 'Failed to save proof file'];
            }
        }

        $db = Database::getInstance();
        $account = self::getAccountById($creditAccountId);
        if (!$account) return ['error' => 'Credit account not found'];

        $newBalance = max(0, (float)$account['balance_used'] - $amount);
        $txId = generateUUID();

        $db->update('credit_accounts', ['balance_used' => $newBalance], 'id = :id', ['id' => $creditAccountId]);

        $txData = [
            'id' => $txId,
            'credit_account_id' => $creditAccountId,
            'type' => 'payment',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'notes' => $notes,
            'recorded_by' => $recordedBy
        ];
        if ($proofPath) $txData['po_file_path'] = $proofPath;
        $db->insert('credit_transactions', $txData);

        return ['transaction_id' => $txId, 'balance_after' => $newBalance, 'proof_path' => $proofPath];
    }

    /**
     * Charge an order to credit account
     * Uses DB transaction with SELECT ... FOR UPDATE
     * Respects exposure_limit if set (max outstanding at any time)
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

            // Respect exposure_limit if set; otherwise fall back to credit_limit
            $effectiveLimit = ($account['exposure_limit'] !== null && (float)$account['exposure_limit'] > 0)
                ? min((float)$account['credit_limit'], (float)$account['exposure_limit'])
                : (float)$account['credit_limit'];

            $available = $effectiveLimit - (float)$account['balance_used'];
            if ($amount > $available) {
                $db->rollback();
                $label = $account['exposure_limit'] !== null ? 'exposure limit' : 'credit limit';
                return ['error' => 'Insufficient credit (' . $label . '). Available: ' . number_format($available, 3)];
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
