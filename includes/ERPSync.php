<?php
/**
 * ERPSync — Pushes Cardify print order payments into BHD-ERP.
 *
 * Calls POST /api/admin/cardify/record-payment on BHD-ERP, which:
 *   1. Creates a Quote for the client
 *   2. Converts the quote with a payment (Quote → Invoice + Payment)
 *   3. Returns the invoice number + ERP IDs
 *
 * After a successful sync, print_orders is updated with:
 *   erp_invoice_id, erp_quote_id, erp_payment_id, erp_invoice_number,
 *   erp_sync_status='synced', erp_last_sync=NOW()
 */
class ERPSync {

    /**
     * Load ERP settings from erp_settings table.
     */
    public static function getSettings(): array {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM erp_settings");
        $s = [];
        foreach ($rows as $r) {
            $s[$r['setting_key']] = $r['setting_value'];
        }
        return $s;
    }

    /**
     * Check if ERP sync is enabled and configured.
     */
    public static function isEnabled(): bool {
        $s = self::getSettings();
        return !empty($s['erp_sync_enabled']) && $s['erp_sync_enabled'] === '1'
            && !empty($s['erp_api_url'])
            && !empty($s['erp_api_token']);
    }

    /**
     * Record a direct payment for a print order in BHD-ERP.
     *
     * @param int    $orderId         print_orders.id
     * @param string $paymentMethod   cash|bank_transfer|cheque|po|online|credit
     * @param string $paymentRef      PO number, cheque number, bank ref, etc.
     * @param string $paymentNotes    Free-form notes
     * @param string $recordedByUserId  User ID (for audit)
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array|null]
     */
    public static function recordPayment(
        int $orderId,
        string $paymentMethod,
        string $paymentRef = '',
        string $paymentNotes = '',
        string $recordedByUserId = ''
    ): array {
        $settings = self::getSettings();

        // Fetch the order
        $db = Database::getInstance();
        $order = $db->fetchOne("
            SELECT po.*, c.name AS company_name, c.erp_client_name
            FROM print_orders po
            LEFT JOIN companies c ON po.company_id = c.id
            WHERE po.id = :id
        ", ['id' => $orderId]);

        if (!$order) {
            return ['success' => false, 'message' => "Order $orderId not found"];
        }

        // Build description
        $qty = (int)($order['quantity'] ?? 0);
        $paper = ucfirst($order['paper_type'] ?? 'standard');
        $finish = ucfirst(str_replace('_', ' ', $order['finish'] ?? 'standard'));
        $description = "Business Cards × {$qty} ({$paper}, {$finish}) — Order {$order['order_number']}";

        // Determine ERP client name: company override → settings default → company name
        $clientName = !empty($order['erp_client_name'])
            ? $order['erp_client_name']
            : (!empty($settings['erp_client_name']) ? $settings['erp_client_name'] : $order['company_name']);

        if (empty($clientName)) {
            return ['success' => false, 'message' => 'Cannot sync: no ERP client name configured'];
        }

        $payload = [
            'clientName'    => $clientName,
            'orderNumber'   => $order['order_number'],
            'amount'        => (float)$order['total'],
            'description'   => $description,
            'paymentMethod' => $paymentMethod,
            'paymentRef'    => $paymentRef ?: $order['order_number'],
            'paymentDate'   => date('c'),
            'poNumber'      => $order['po_number'] ?? '',
            'notes'         => $paymentNotes,
        ];

        // Call BHD-ERP
        $apiUrl  = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/record-payment';
        $token   = $settings['erp_api_token'];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            self::markSyncError($orderId, "cURL error: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }

        $data = json_decode($body, true);

        if ($httpCode === 409) {
            // Already recorded — treat as success, store existing IDs if returned
            $erpData = $data ?? [];
            $db->exec("UPDATE print_orders SET
                erp_sync_status = 'synced',
                erp_last_sync   = NOW(),
                erp_sync_error  = NULL
                " . (isset($erpData['invoiceId']) ? ", erp_invoice_id = " . $db->getConnection()->quote($erpData['invoiceId']) : '') . "
                " . (isset($erpData['invoiceNumber']) ? ", erp_invoice_number = " . $db->getConnection()->quote($erpData['invoiceNumber']) : '') . "
                WHERE id = $orderId"
            );
            return ['success' => true, 'message' => 'Already recorded in ERP', 'data' => $erpData];
        }

        if ($httpCode !== 200 || empty($data['success'])) {
            $errMsg = $data['message'] ?? "HTTP $httpCode";
            self::markSyncError($orderId, $errMsg);
            return ['success' => false, 'message' => "ERP sync failed: $errMsg"];
        }

        // Success — persist ERP IDs back into Cardify
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("UPDATE print_orders SET
            erp_invoice_id            = :inv_id,
            erp_quote_id              = :quo_id,
            erp_payment_id            = :pay_id,
            erp_invoice_number        = :inv_num,
            direct_payment_ref        = :ref,
            direct_payment_notes      = :notes,
            direct_payment_recorded_by = :by,
            direct_payment_recorded_at = NOW(),
            erp_sync_status           = 'synced',
            erp_last_sync             = NOW(),
            erp_sync_error            = NULL,
            payment_status            = 'paid',
            payment_method            = :method
            WHERE id = :id
        ");
        $stmt->execute([
            'inv_id'  => $data['invoiceId'] ?? null,
            'quo_id'  => $data['quoteId']   ?? null,
            'pay_id'  => $data['paymentId'] ?? null,
            'inv_num' => $data['invoiceNumber'] ?? null,
            'ref'     => $paymentRef,
            'notes'   => $paymentNotes,
            'by'      => $recordedByUserId,
            'method'  => $paymentMethod,
            'id'      => $orderId,
        ]);

        return ['success' => true, 'message' => 'Payment synced to ERP', 'data' => $data];
    }

    /**
     * Mark an order's ERP sync as failed with an error message.
     * Also fires a WhatsApp alert to Ali (throttled, see alertFailure()).
     */
    private static function markSyncError(int $orderId, string $error): void {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $pdo->prepare("UPDATE print_orders SET
            erp_sync_status = 'error',
            erp_sync_error  = :err,
            erp_last_sync   = NOW()
            WHERE id = :id
        ")->execute(['err' => $error, 'id' => $orderId]);

        try { self::alertFailure($orderId, $error); } catch (Throwable $_) { /* alerts are never allowed to crash the order flow */ }
    }

    /**
     * WhatsApp the failure to Ali (ERP owner), throttled (Cat S action
     * 442). At most one alert per order per 30 minutes, and at most 5
     * alerts globally per hour, so a broken token cannot flood.
     *
     * Storage is file-backed under /data/cache/erp-alerts/ to stay
     * independent of MySQL health (the exact outage we are alerting on
     * could be DB-side). File is ignored if the dir is not writable.
     */
    private static function alertFailure(int $orderId, string $error): void {
        // Recipient: Ali personal (+96871616161, per memory feedback_fencing_otp_tests_ali_only
        // — same rule applies to error alerts; only Ali gets pinged until we wire
        // opt-in per-admin notifications).
        $phone = '96871616161';

        $cacheDir = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__)) . '/data/cache/erp-alerts';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        $perOrderKey  = $cacheDir . '/order-' . $orderId . '.ts';
        $globalLogKey = $cacheDir . '/global.log';
        $now          = time();

        // Per-order throttle: 30 min between alerts for the same order.
        if (is_file($perOrderKey) && ($now - (int) @file_get_contents($perOrderKey)) < 1800) return;

        // Global hourly cap: 5 alerts in the last 3600 seconds.
        $recent = [];
        if (is_file($globalLogKey)) {
            foreach (array_filter(explode("\n", (string) @file_get_contents($globalLogKey))) as $line) {
                $t = (int) $line;
                if ($now - $t < 3600) $recent[] = $t;
            }
            if (count($recent) >= 5) return;
        }
        $recent[] = $now;
        @file_put_contents($globalLogKey, implode("\n", $recent));
        @file_put_contents($perOrderKey, (string) $now);

        $host    = defined('APP_HOST') ? APP_HOST : 'cardify.om';
        $short   = mb_substr($error, 0, 280);
        $msg     = "*Cardify ERP sync failed*\n"
                 . "Order: CRDFY-{$orderId}\n"
                 . "Error: {$short}\n"
                 . "Time: " . date('Y-m-d H:i') . " (GMT+4)\n"
                 . "Order URL: https://{$host}/admin/print-order.php?id={$orderId}\n"
                 . "Health: https://{$host}/api/erp-health";

        if (class_exists('WhatsApp')) {
            try { WhatsApp::sendMessage($phone, $msg); } catch (Throwable $_) { /* swallow */ }
        }

        // Also record to audit_logs for the internal ops timeline.
        if (class_exists('AuditLog')) {
            try { AuditLog::log('erp_sync_failed', 'print_order', (string) $orderId, ['error' => $short]); } catch (Throwable $_) {}
        }
    }
}
