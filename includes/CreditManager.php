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
        if ($count <= 0) return false;

        // Dispatch approval email. Non-fatal on delivery failure.
        try {
            self::sendApprovalEmail($creditAccountId, $approvedLimit, $paymentTerms);
        } catch (Throwable $e) {
            error_log('[CreditManager::approve] email dispatch failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Dispatch credit_approved email to the customer company admin.
     * Template: includes/notifications/templates/credit_approved.email.{locale}.php
     */
    private static function sendApprovalEmail(
        string $creditAccountId,
        float $approvedLimit,
        string $paymentTerms
    ): void {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT ca.exposure_limit,
                    c.name AS company_name, c.admin_email, c.locale,
                    c.brand_color, c.logo_url,
                    ps.name AS shop_name, ps.currency
             FROM credit_accounts ca
             JOIN companies c    ON c.id = ca.company_id
             JOIN print_shops ps ON ps.id = ca.print_shop_id
             WHERE ca.id = :id",
            ['id' => $creditAccountId]
        );
        if (!$row || empty($row['admin_email']) || !class_exists('Mailer')) return;

        $locale = ($row['locale'] === 'ar') ? 'ar' : 'en';
        $currency = $row['currency'] ?? 'OMR';
        $limitFormatted = number_format($approvedLimit, 3) . ' ' . $currency;
        $exposureFormatted = null;
        if (!empty($row['exposure_limit']) && (float) $row['exposure_limit'] > 0) {
            $exposureFormatted = number_format((float) $row['exposure_limit'], 3) . ' ' . $currency;
        }

        $ctx = [
            'contactName'       => $row['company_name'],
            'companyName'       => $row['company_name'],
            'shopName'          => $row['shop_name'],
            'limitFormatted'    => $limitFormatted,
            'currency'          => $currency,
            'paymentTerms'      => strtoupper($paymentTerms),
            'exposureFormatted' => $exposureFormatted,
            'dashboardUrl'      => 'https://cardify.om/admin/credit-accounts',
            'brandColor'        => $row['brand_color'] ?? null,
            'logoUrl'           => $row['logo_url'] ?? null,
        ];

        $path = __DIR__ . "/notifications/templates/credit_approved.email.{$locale}.php";
        if (!is_file($path)) $path = __DIR__ . "/notifications/templates/credit_approved.email.en.php";

        extract($ctx, EXTR_SKIP);
        $subject = ''; $body = '';
        require $path;

        Mailer::send($row['admin_email'], $subject, $body);
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

        if ($file['size'] > 5 * 1024 * 1024) {
            return ['error' => 'File too large. Maximum 5MB'];
        }
        // Use real MIME detection from file content, not client-supplied type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($realMime, $allowedMimes)) {
            return ['error' => 'Invalid file type. Allowed: PDF, JPG, PNG'];
        }
        $mimeToExt = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

        $uploadDir = dirname(__DIR__) . '/uploads/credit_pos/' . $account['company_id'];
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = $mimeToExt[$realMime];
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
            if ($proofFile['size'] > 5 * 1024 * 1024) {
                return ['error' => 'Proof file too large. Maximum 5MB'];
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($proofFile['tmp_name']);
            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($realMime, $allowedMimes)) {
                return ['error' => 'Invalid proof file type. Allowed: PDF, JPG, PNG'];
            }
            $mimeToExt = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
            $ext = $mimeToExt[$realMime];

            $accountForUpload = self::getAccountById($creditAccountId);
            $uploadDir = dirname(__DIR__) . '/uploads/payment_proofs/' . ($accountForUpload['company_id'] ?? 'unknown');
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName = 'proof_' . $creditAccountId . '_' . time() . '.' . $ext;
            $proofPath = 'uploads/payment_proofs/' . ($accountForUpload['company_id'] ?? 'unknown') . '/' . $safeName;
            if (!move_uploaded_file($proofFile['tmp_name'], dirname(__DIR__) . '/' . $proofPath)) {
                return ['error' => 'Failed to save proof file'];
            }
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Atomic balance update using a locked transaction
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM credit_accounts WHERE id = ? FOR UPDATE");
            $stmt->execute([$creditAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$account) {
                $conn->rollBack();
                return ['error' => 'Credit account not found'];
            }

            $newBalance = max(0, (float)$account['balance_used'] - $amount);
            $txId = generateUUID();

            $stmt = $conn->prepare("UPDATE credit_accounts SET balance_used = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $creditAccountId]);

            $stmt = $conn->prepare(
                "INSERT INTO credit_transactions (id, credit_account_id, type, amount, balance_after, notes, recorded_by, po_file_path, created_at)
                 VALUES (?, ?, 'payment', ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$txId, $creditAccountId, $amount, $newBalance, $notes, $recordedBy, $proofPath]);

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            error_log("CreditManager::recordPaymentWithProof failed: " . $e->getMessage());
            return ['error' => 'Transaction failed'];
        }

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
        if (!is_finite($amount) || $amount <= 0) {
            return ['error' => 'Amount must be a positive number'];
        }

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
        if (!is_finite($amount) || $amount <= 0) {
            return ['error' => 'Amount must be a positive number'];
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM credit_accounts WHERE id = ? FOR UPDATE");
            $stmt->execute([$creditAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$account) {
                $conn->rollBack();
                return ['error' => 'Credit account not found'];
            }

            // Never credit beyond what was actually owed.
            $amount = min($amount, (float)$account['balance_used']);
            if ($amount <= 0) {
                $conn->rollBack();
                return ['error' => 'No outstanding balance to pay'];
            }

            $newBalance = max(0, (float)$account['balance_used'] - $amount);
            $txId = generateUUID();

            $stmt = $conn->prepare("UPDATE credit_accounts SET balance_used = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $creditAccountId]);

            $stmt = $conn->prepare(
                "INSERT INTO credit_transactions (id, credit_account_id, type, amount, balance_after, notes, recorded_by, created_at)
                 VALUES (?, ?, 'payment', ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$txId, $creditAccountId, $amount, $newBalance, $notes, $recordedBy]);

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            error_log("CreditManager::recordPayment failed: " . $e->getMessage());
            return ['error' => 'Transaction failed'];
        }

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
        if (!is_finite($amount) || $amount <= 0) {
            return ['error' => 'Amount must be a positive number'];
        }

        $db = Database::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM credit_accounts WHERE id = ? FOR UPDATE");
            $stmt->execute([$creditAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$account) {
                $conn->rollBack();
                return ['error' => 'Credit account not found'];
            }

            // Cap refund at the net charge recorded for this order, so we
            // cannot hand back more credit than was charged.
            $charged = $conn->prepare(
                "SELECT COALESCE(SUM(CASE WHEN type='charge' THEN amount WHEN type='refund' THEN -amount ELSE 0 END),0) AS net
                 FROM credit_transactions WHERE credit_account_id = ? AND order_id = ?"
            );
            $charged->execute([$creditAccountId, $orderId]);
            $netCharged = (float)($charged->fetchColumn() ?: 0);
            if ($netCharged <= 0) {
                $conn->rollBack();
                return ['error' => 'No refundable charge for this order'];
            }
            if ($amount > $netCharged) {
                $amount = $netCharged;
            }

            $newBalance = max(0, (float)$account['balance_used'] - $amount);
            $txId = generateUUID();

            $stmt = $conn->prepare("UPDATE credit_accounts SET balance_used = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $creditAccountId]);

            $stmt = $conn->prepare(
                "INSERT INTO credit_transactions (id, credit_account_id, order_id, type, amount, balance_after, created_at)
                 VALUES (?, ?, ?, 'refund', ?, ?, NOW())"
            );
            $stmt->execute([$txId, $creditAccountId, $orderId, $amount, $newBalance]);

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            error_log("CreditManager::refund failed: " . $e->getMessage());
            return ['error' => 'Transaction failed'];
        }

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
        // Respect exposure_limit if set (same logic as charge())
        $effectiveLimit = ($account['exposure_limit'] !== null && (float)$account['exposure_limit'] > 0)
            ? min((float)$account['credit_limit'], (float)$account['exposure_limit'])
            : (float)$account['credit_limit'];
        return max(0, $effectiveLimit - (float)$account['balance_used']);
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
