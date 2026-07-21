<?php
/**
 * ERPSync, Pushes Cardify print order payments into BHD-ERP.
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
    /**
     * Record a card-credit top-up against BHD-ERP (Cat S action 453).
     *
     * Card credits are digital-only; they don't flow through
     * print_orders, so they used to skip the ERP sync entirely and
     * show up only in Cardify's `payments` table. This pushes them
     * into BHD-ERP as a Quote → Invoice → Payment chain with a
     * synthetic CARDS-{paymentId-short} reference so accountants can
     * reconcile Paymob payouts against the ERP client ledger.
     *
     * Returns ['success' => bool, 'message' => string, 'data' => ?array].
     * Non-fatal on failure; caller logs + moves on. Idempotent via the
     * 409-already-recorded path in BHD-ERP.
     */
    public static function recordCardCreditPurchase(string $paymentId): array {
        if (!self::isEnabled()) return ['success' => true, 'message' => 'ERP sync disabled'];
        $settings = self::getSettings();

        $db = Database::getInstance();
        $pay = $db->fetchOne(
            "SELECT p.*, c.name AS company_name, c.erp_client_name
               FROM payments p
          LEFT JOIN companies c ON p.company_id = c.id
              WHERE p.id = :id AND p.type = 'card_order' AND p.status = 'paid'",
            ['id' => $paymentId]
        );
        if (!$pay) {
            return ['success' => false, 'message' => "Card-credit payment $paymentId not found or not paid"];
        }

        $credit = $db->fetchOne(
            "SELECT * FROM card_order_credits WHERE id = :id",
            ['id' => $pay['reference_id']]
        );
        $cardCount = (int) ($credit['card_count'] ?? 0);

        $clientName = !empty($pay['erp_client_name'])
            ? $pay['erp_client_name']
            : (!empty($settings['erp_client_name']) ? $settings['erp_client_name'] : $pay['company_name']);
        if (empty($clientName)) {
            return ['success' => false, 'message' => 'Cannot sync: no ERP client name configured'];
        }

        // CARDS-{first 8 of paymentId} is the ERP-side orderNumber and the
        // dedup key. Keeps the UUID out of user-facing invoice numbers.
        $shortRef    = 'CARDS-' . strtoupper(substr(str_replace('-', '', $paymentId), 0, 8));
        $description = "Cardify card credits × {$cardCount}, {$shortRef}";

        $payload = [
            'clientName'    => $clientName,
            'orderNumber'   => $shortRef,
            'amount'        => (float) $pay['amount'],
            'description'   => $description,
            'paymentMethod' => 'bank_transfer', // Paymob card/apple-pay settle as bank transfer on our ERP side
            'paymentRef'    => $pay['paymob_transaction_id'] ?: $shortRef,
            'paymentDate'   => date('c', strtotime($pay['updated_at'] ?? $pay['created_at'])),
            'notes'         => "Card-credit top-up via Paymob · company={$pay['company_id']}",
        ];

        $apiUrl = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/record-payment';
        $ch     = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['erp_api_token'],
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("ERPSync::recordCardCreditPurchase cURL error: $curlErr");
            return ['success' => false, 'message' => $curlErr];
        }
        $data = json_decode($body, true) ?: [];

        // 409 = idempotent re-record; 200 or 201 = fresh sync; else fail.
        if ($httpCode === 409 || $httpCode === 200 || $httpCode === 201) {
            // Persist the ERP IDs back onto payments so the admin can see them.
            try {
                $db->exec(
                    "UPDATE payments SET
                       callback_data = JSON_SET(COALESCE(callback_data, '{}'),
                           '$.erp_invoice_number', :inv, '$.erp_invoice_id', :invid, '$.erp_short_ref', :ref)
                     WHERE id = :id",
                    [
                        'inv'   => $data['invoiceNumber'] ?? null,
                        'invid' => $data['invoiceId']     ?? null,
                        'ref'   => $shortRef,
                        'id'    => $paymentId,
                    ]
                );
            } catch (Throwable $_) { /* JSON_SET may not be available on older MySQL */ }
            return ['success' => true, 'message' => 'Card-credit purchase synced to ERP', 'data' => $data];
        }

        $errMsg = $data['message'] ?? "HTTP $httpCode";
        error_log("ERPSync::recordCardCreditPurchase failed ($errMsg)");
        return ['success' => false, 'message' => $errMsg];
    }

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
        $description = "Business Cards × {$qty} ({$paper}, {$finish}), Order {$order['order_number']}";

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
            self::enqueueRetry($orderId, $paymentMethod, $paymentRef, $paymentNotes, $recordedByUserId, "cURL error: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }

        $data = json_decode($body, true);

        if ($httpCode === 409) {
            // Already recorded, treat as success, store existing IDs if returned
            $erpData = $data ?? [];
            $db->exec("UPDATE print_orders SET
                erp_sync_status = 'synced',
                erp_last_sync   = NOW(),
                erp_sync_error  = NULL
                " . (isset($erpData['invoiceId']) ? ", erp_invoice_id = " . $db->getConnection()->quote($erpData['invoiceId']) : '') . "
                " . (isset($erpData['invoiceNumber']) ? ", erp_invoice_number = " . $db->getConnection()->quote($erpData['invoiceNumber']) : '') . "
                WHERE id = $orderId"
            );
            self::markRetrySucceeded($orderId);
            return ['success' => true, 'message' => 'Already recorded in ERP', 'data' => $erpData];
        }

        if ($httpCode !== 200 || empty($data['success'])) {
            $errMsg = $data['message'] ?? "HTTP $httpCode";
            self::markSyncError($orderId, $errMsg);
            self::enqueueRetry($orderId, $paymentMethod, $paymentRef, $paymentNotes, $recordedByUserId, $errMsg);
            return ['success' => false, 'message' => "ERP sync failed: $errMsg"];
        }

        // Success, persist ERP IDs back into Cardify
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

        self::markRetrySucceeded($orderId);
        return ['success' => true, 'message' => 'Payment synced to ERP', 'data' => $data];
    }

    /**
     * Create an ERP QUOTE for a print order the moment BHD is engaged (on
     * approve). No invoice, no payment, no JE. Books against the company's
     * ERP client (Oman Housing Bank for OHB). Idempotent on the order number
     * (the ERP returns 409 with the existing quote). Non-fatal by design so
     * a quote failure never blocks the approval.
     *
     * @return array { success, message, data?:{ quoteId, quoteNumber } }
     */
    public static function createQuote(int $orderId): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'ERP sync disabled'];
        }
        $settings = self::getSettings();
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
        // Idempotent locally: never double-quote a print order.
        if (!empty($order['erp_quote_id'])) {
            return ['success' => true, 'message' => 'Quote already exists', 'data' => ['quoteId' => $order['erp_quote_id']]];
        }

        $qty = (int)($order['quantity'] ?? 0);
        $paper = ucfirst($order['paper_type'] ?? 'standard');
        $finish = ucfirst(str_replace('_', ' ', $order['finish'] ?? 'standard'));
        $description = "Business Cards x {$qty} ({$paper}, {$finish}), Order {$order['order_number']}";

        // Company override (OHB = Oman Housing Bank S.A.O.G.) then settings then name.
        $clientName = !empty($order['erp_client_name'])
            ? $order['erp_client_name']
            : (!empty($settings['erp_client_name']) ? $settings['erp_client_name'] : $order['company_name']);
        if (empty($clientName)) {
            return ['success' => false, 'message' => 'Cannot quote: no ERP client name configured'];
        }

        $amount = (float)$order['total'];
        $unit = $qty > 0 ? round($amount / $qty, 3) : $amount;
        $payload = [
            'clientName'  => $clientName,
            'orderNumber' => $order['order_number'],
            'amount'      => $amount, // gross; the ERP backs out 5% VAT
            'description' => $description,
            'currency'    => $order['currency'] ?? 'OMR',
            'items'       => [[
                'itemName' => $description,
                'quantity' => $qty ?: 1,
                'price'    => $unit,
            ]],
        ];

        $apiUrl = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/create-quote';
        $token  = $settings['erp_api_token'];

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
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("ERPSync::createQuote cURL error for order $orderId: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }

        $data = json_decode($body, true);

        // 200 (created) and 409 (already exists) both carry quoteId/quoteNumber.
        if ($httpCode === 200 || $httpCode === 409) {
            $quoteId = $data['quoteId'] ?? null;
            $quoteNumber = $data['quoteNumber'] ?? null;
            if ($quoteId) {
                $stmt = $db->getConnection()->prepare("UPDATE print_orders SET
                    erp_quote_id     = :qid,
                    quotation_number = :qnum,
                    erp_sync_status  = 'quoted',
                    erp_last_sync    = NOW(),
                    erp_sync_error   = NULL
                    WHERE id = :id");
                $stmt->execute(['qid' => $quoteId, 'qnum' => $quoteNumber, 'id' => $orderId]);
            }
            return ['success' => true, 'message' => 'Quote created in ERP', 'data' => ['quoteId' => $quoteId, 'quoteNumber' => $quoteNumber]];
        }

        $errMsg = $data['message'] ?? "HTTP $httpCode";
        error_log("ERPSync::createQuote failed for order $orderId: $errMsg");
        $db->query(
            "UPDATE print_orders SET erp_sync_status = 'quote_failed', erp_sync_error = :e WHERE id = :id",
            ['e' => $errMsg, 'id' => $orderId]
        );
        return ['success' => false, 'message' => "ERP quote failed: $errMsg"];
    }

    /**
     * Convert the print order's existing ERP quote into an Invoice + Sales
     * Order + Delivery Note. trigger 'po' invoices on credit (AR stays open,
     * margin-gated); trigger 'payment' raises the documents for an online-paid
     * order. Idempotent (the ERP returns 409 with the existing ids). Non-fatal.
     *
     * @return array { success, message, data?:{ invoiceId, invoiceNumber, salesOrderId, deliveryNoteId } }
     */
    public static function convertQuoteToInvoice(int $orderId, string $trigger = 'po'): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'ERP sync disabled'];
        }
        $settings = self::getSettings();
        $db = Database::getInstance();
        $order = $db->fetchOne(
            "SELECT id, order_number, erp_quote_id, erp_invoice_id FROM print_orders WHERE id = :id",
            ['id' => $orderId]
        );
        if (!$order) {
            return ['success' => false, 'message' => "Order $orderId not found"];
        }
        if (empty($order['erp_quote_id'])) {
            return ['success' => false, 'message' => 'No ERP quote to convert'];
        }
        // Idempotent locally: already invoiced.
        if (!empty($order['erp_invoice_id'])) {
            return ['success' => true, 'message' => 'Already invoiced', 'data' => ['invoiceId' => $order['erp_invoice_id']]];
        }

        $payload = [
            'orderNumber' => $order['order_number'],
            'trigger'     => $trigger === 'payment' ? 'payment' : 'po',
        ];

        $apiUrl = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/convert-quote';
        $token  = $settings['erp_api_token'];

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
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("ERPSync::convertQuoteToInvoice cURL error for order $orderId: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }

        $data = json_decode($body, true);

        if ($httpCode === 200 || $httpCode === 409) {
            $stmt = $db->getConnection()->prepare("UPDATE print_orders SET
                erp_invoice_id            = :inv_id,
                erp_invoice_number        = :inv_num,
                invoice_number            = :inv_num2,
                erp_order_id              = :so_id,
                delivery_note_external_id = :dn_id,
                invoice_issued_at         = NOW(),
                erp_sync_status           = 'invoiced',
                erp_last_sync             = NOW(),
                erp_sync_error            = NULL
                WHERE id = :id");
            $stmt->execute([
                'inv_id'  => $data['invoiceId'] ?? null,
                'inv_num' => $data['invoiceNumber'] ?? null,
                'inv_num2' => $data['invoiceNumber'] ?? null,
                'so_id'   => $data['salesOrderId'] ?? null,
                'dn_id'   => $data['deliveryNoteId'] ?? null,
                'id'      => $orderId,
            ]);
            return ['success' => true, 'message' => 'Quote converted in ERP', 'data' => $data];
        }

        $errMsg = $data['message'] ?? "HTTP $httpCode";
        error_log("ERPSync::convertQuoteToInvoice failed for order $orderId: $errMsg");
        $db->query(
            "UPDATE print_orders SET erp_sync_status = 'invoice_failed', erp_sync_error = :e WHERE id = :id",
            ['e' => $errMsg, 'id' => $orderId]
        );
        return ['success' => false, 'message' => "ERP convert failed: $errMsg"];
    }

    /**
     * Self-heal: re-attempt any print order stuck at erp_sync_status
     * 'quote_failed' or 'invoice_failed' (transient ERP outage, expired token,
     * timeout). createQuote / convertQuoteToInvoice are idempotent, so this is
     * safe to run on a cron. Returns counts; the caller alerts on persistent
     * failures.
     *
     * @return array { success, data:{ quotes:int, invoices:int, still_failed:int } }
     */
    public static function backfillFailedSyncs(int $limit = 50): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'ERP sync disabled'];
        }
        $db = Database::getInstance();
        $limit = max(1, min(200, $limit));
        $out = ['quotes' => 0, 'invoices' => 0, 'still_failed' => 0];

        // 1) Orders that never got an ERP quote -> retry createQuote.
        $qf = $db->fetchAll(
            "SELECT id FROM print_orders
             WHERE erp_sync_status = 'quote_failed' AND (erp_quote_id IS NULL OR erp_quote_id = '')
             ORDER BY updated_at DESC LIMIT " . (int)$limit
        );
        foreach ($qf as $o) {
            $r = self::createQuote((int)$o['id']);
            if (!empty($r['success'])) { $out['quotes']++; } else { $out['still_failed']++; }
        }

        // 2) Orders whose quote never converted -> retry with the correct
        // trigger (paid orders take the payment path, others the PO path).
        $inf = $db->fetchAll(
            "SELECT id, payment_status FROM print_orders
             WHERE erp_sync_status = 'invoice_failed'
               AND (erp_invoice_id IS NULL OR erp_invoice_id = '')
               AND erp_quote_id IS NOT NULL AND erp_quote_id != ''
             ORDER BY updated_at DESC LIMIT " . (int)$limit
        );
        foreach ($inf as $o) {
            $trigger = (($o['payment_status'] ?? '') === 'paid') ? 'payment' : 'po';
            $r = self::convertQuoteToInvoice((int)$o['id'], $trigger);
            if (!empty($r['success'])) { $out['invoices']++; } else { $out['still_failed']++; }
        }

        if ($out['still_failed'] > 0) {
            error_log("ERPSync backfill: {$out['still_failed']} order(s) still failing ERP sync");
        }
        return ['success' => true, 'data' => $out];
    }

    /**
     * "Send to Print": create a PRODUCTION-ONLY Sale Order + Manufacturing Order
     * on the BHD-ERP production Kanban (no invoice, no payment, no JE). Billed
     * later on a consolidated invoice per client PO.
     *
     * @return array { success, message, data:{ soNumber, manufacturingOrders[], quoteNumber } }
     */
    public static function createProductionOrder(array $args): array
    {
        $settings = self::getSettings();
        if (empty($settings['erp_api_url']) || empty($settings['erp_api_token'])) {
            return ['success' => false, 'message' => 'ERP not configured'];
        }

        $clientName = (string)($args['clientName'] ?? '');
        if ($clientName === '') {
            return ['success' => false, 'message' => 'No ERP client name for this company'];
        }

        $payload = [
            'clientName'    => $clientName,
            'orderRef'      => (string)($args['orderRef'] ?? ''),
            'cardLabel'     => (string)($args['cardLabel'] ?? 'Business Cards'),
            'quantity'      => (int)($args['quantity'] ?? 0),
            'unitPrice'     => (float)($args['unitPrice'] ?? 0),
            'unitCost'      => (float)($args['unitCost'] ?? 0),
            'printReadyUrl' => (string)($args['printReadyUrl'] ?? ''),
            'poNumber'      => (string)($args['poNumber'] ?? ''),
            'productName'   => (string)($args['productName'] ?? ''),
            'notes'         => (string)($args['notes'] ?? ''),
        ];

        $apiUrl = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/create-production-order';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['erp_api_token'],
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("ERPSync::createProductionOrder cURL: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }
        $data = json_decode($body, true);
        // 409 = idempotent (already created); treat as success.
        if ($httpCode === 409) {
            return ['success' => true, 'message' => 'Already on the Kanban', 'data' => $data ?? []];
        }
        if ($httpCode !== 200 || empty($data['success'])) {
            $msg = $data['message'] ?? "ERP returned HTTP $httpCode";
            error_log("ERPSync::createProductionOrder failed: $msg");
            return ['success' => false, 'message' => $msg];
        }
        return ['success' => true, 'message' => $data['message'] ?? 'Sent to production', 'data' => $data];
    }

    /**
     * "Bill this PO": raise ONE consolidated invoice in BHD-ERP for all unbilled
     * production orders on a client + PO. Idempotent (the ERP marks the source
     * orders converted so they can't be re-billed).
     *
     * @return array { success, message, data:{ invoiceNumber, invoiced, total } }
     */
    public static function createConsolidatedInvoice(string $clientName, string $poNumber): array
    {
        $settings = self::getSettings();
        if (empty($settings['erp_api_url']) || empty($settings['erp_api_token'])) {
            return ['success' => false, 'message' => 'ERP not configured'];
        }
        if ($clientName === '' || $poNumber === '') {
            return ['success' => false, 'message' => 'clientName and poNumber are required'];
        }

        $apiUrl = rtrim($settings['erp_api_url'], '/') . '/api/admin/cardify/create-consolidated-invoice';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['clientName' => $clientName, 'poNumber' => $poNumber]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['erp_api_token'],
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("ERPSync::createConsolidatedInvoice cURL: $curlErr");
            return ['success' => false, 'message' => "ERP connection failed: $curlErr"];
        }
        $data = json_decode($body, true);
        if ($httpCode !== 200 || empty($data['success'])) {
            $msg = $data['message'] ?? "ERP returned HTTP $httpCode";
            error_log("ERPSync::createConsolidatedInvoice failed: $msg");
            return ['success' => false, 'message' => $msg];
        }
        return ['success' => true, 'message' => $data['message'] ?? 'Invoiced', 'data' => $data];
    }

    /**
     * Retry backoff ladder in minutes, max attempts = count(). After
     * the final slot the retry row is marked 'exhausted' and manual
     * intervention is required.
     */
    private const RETRY_MINUTES = [2, 5, 15, 60, 180, 720, 1440];

    /**
     * Enqueue or update a retry for a failing ERP sync. Persists the
     * full recordPayment() argument set so the worker can replay it
     * offline; uses exponential backoff. Safe to call repeatedly with
     * the same order + new error.
     */
    public static function enqueueRetry(
        int $orderId,
        string $paymentMethod,
        string $paymentRef,
        string $paymentNotes,
        string $recordedByUserId,
        string $error
    ): void {
        try {
            $db  = Database::getInstance();
            $row = $db->fetchOne(
                "SELECT id, attempts FROM erp_sync_retries WHERE order_id = :id AND status = 'pending' LIMIT 1",
                ['id' => $orderId]
            );
            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            $slot     = min($attempts - 1, count(self::RETRY_MINUTES) - 1);
            $delay    = self::RETRY_MINUTES[$slot];
            $next     = (new DateTime('now'))->modify("+{$delay} minutes")->format('Y-m-d H:i:s');
            $status   = $attempts >= count(self::RETRY_MINUTES) ? 'exhausted' : 'pending';

            if ($row) {
                $db->exec(
                    "UPDATE erp_sync_retries SET
                        attempts = :a, last_error = :e, last_tried_at = NOW(),
                        next_retry_at = :n, status = :s
                     WHERE id = :id",
                    ['a' => $attempts, 'e' => mb_substr($error, 0, 2000), 'n' => $next, 's' => $status, 'id' => $row['id']]
                );
            } else {
                $db->exec(
                    "INSERT INTO erp_sync_retries
                        (order_id, payment_method, payment_ref, payment_notes, recorded_by,
                         attempts, last_error, last_tried_at, next_retry_at, status)
                     VALUES (:oid, :m, :r, :n, :by, :a, :e, NOW(), :next, :s)",
                    [
                        'oid' => $orderId, 'm' => $paymentMethod, 'r' => $paymentRef,
                        'n' => $paymentNotes, 'by' => $recordedByUserId,
                        'a' => 1, 'e' => mb_substr($error, 0, 2000),
                        'next' => $next, 's' => 'pending',
                    ]
                );
            }
        } catch (Throwable $_) { /* queue errors must never break the order flow */ }
    }

    /**
     * Mark any pending retry for this order as succeeded. Called from
     * recordPayment() on a successful sync (including the 409 idempotent
     * case).
     */
    public static function markRetrySucceeded(int $orderId): void {
        try {
            $db = Database::getInstance();
            $db->exec(
                "UPDATE erp_sync_retries SET status = 'succeeded', updated_at = NOW()
                 WHERE order_id = :id AND status = 'pending'",
                ['id' => $orderId]
            );
        } catch (Throwable $_) { /* best effort */ }
    }

    /**
     * Process due retries. Meant to be run by a cron every minute. Picks
     * rows where status = 'pending' AND next_retry_at <= NOW(), replays
     * recordPayment(), and updates the queue row based on the outcome.
     *
     * @param int $limit Max rows to process per tick (default 20).
     * @return array {processed:int, succeeded:int, failed:int}
     */
    public static function runQueue(int $limit = 20): array {
        $out = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        try {
            $db   = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT * FROM erp_sync_retries
                 WHERE status = 'pending' AND next_retry_at <= NOW()
                 ORDER BY next_retry_at ASC
                 LIMIT " . (int) $limit
            );
            foreach ($rows as $r) {
                $out['processed']++;
                $res = self::recordPayment(
                    (int) $r['order_id'],
                    (string) $r['payment_method'],
                    (string) $r['payment_ref'],
                    (string) ($r['payment_notes'] ?? ''),
                    (string) $r['recorded_by']
                );
                if (!empty($res['success'])) {
                    // recordPayment() already calls markRetrySucceeded() on
                    // success; we just tally here for the runner log.
                    $out['succeeded']++;
                } else {
                    $out['failed']++;
                }
            }
        } catch (Throwable $e) {
            // Surface to the runner stdout but don't re-throw.
            error_log('ERPSync::runQueue error: ' . $e->getMessage());
        }
        return $out;
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
        //, same rule applies to error alerts; only Ali gets pinged until we wire
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
