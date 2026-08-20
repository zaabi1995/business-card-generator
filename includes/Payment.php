<?php
/**
 * Unified Paymob Payment Handler
 * Handles both subscription and print order payments via Paymob Unified Checkout
 * Reads config from PHP constants (PAYMOB_SECRET_KEY, PAYMOB_PUBLIC_KEY, etc.)
 */

class Payment {

    /**
     * Convert amount to smallest currency unit for Paymob
     * OMR/BHD/KWD = 3 decimals (×1000), others = 2 decimals (×100)
     */
    public static function toSmallestUnit(float $amount, string $currency = 'OMR'): int {
        $threeDecimal = ['OMR', 'BHD', 'KWD'];
        $multiplier = in_array(strtoupper($currency), $threeDecimal) ? 1000 : 100;
        return (int) round($amount * $multiplier);
    }

    /**
     * Compute Paymob HMAC-SHA512 signature
     */
    public static function computeHmac(array $data, string $secret): string {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order', 'owner', 'pending',
            'source_data_pan', 'source_data_sub_type', 'source_data_type', 'success'
        ];

        $concatenated = '';
        foreach ($fields as $field) {
            $value = $data[$field] ?? '';
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $concatenated .= (string)$value;
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    /**
     * Verify HMAC signature from Paymob callback
     */
    public static function verifyHmac(array $data, string $hmac): bool {
        $secret = defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '';
        if (empty($secret)) return false;
        $computed = self::computeHmac($data, $secret);
        return hash_equals($computed, $hmac);
    }

    /**
     * Create Paymob payment intent for subscription, print order, or card order
     *
     * @param string $type 'subscription', 'print_order', or 'card_order'
     * @param string $referenceId plan_id, print order id, or generated_card id
     * @param float $amount in full currency units (e.g. 5.000 OMR)
     * @param string $companyId
     * @param array $billingData Override billing data ['first_name','last_name','email','phone_number']
     * @param string $currency
     * @param string $billingCycle 'monthly', 'yearly', or 'one_time'
     * @return array ['payment_id'=>...,'checkout_url'=>...] or ['error'=>...]
     */
    public static function createIntent(
        string $type,
        string $referenceId,
        float $amount,
        string $companyId,
        array $billingData = [],
        string $currency = 'OMR',
        string $billingCycle = 'monthly'
    ): array {
        $secretKey = defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '';
        $publicKey = defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '';
        $integrationIds = defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : '';

        if (empty($secretKey) || empty($publicKey)) {
            return ['error' => 'Paymob credentials not configured'];
        }

        $paymentMethods = array_map('intval', array_filter(explode(',', $integrationIds)));
        if (empty($paymentMethods)) {
            return ['error' => 'Paymob integration IDs not configured'];
        }

        $db = Database::getInstance();

        // Get company info for real billing data
        $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $companyId]);
        if (!$company) {
            return ['error' => 'Company not found'];
        }

        // Paymob Oman merchant only accepts OMR. All amounts entering this
        // method are OMR-canonical (print_order.total_amount, card_order.total,
        // free-forever means no subscription path reaches this code). Coerce
        // any non-OMR input to OMR with a warning, this is a bug in the caller.
        if (strtoupper($currency) !== 'OMR') {
            error_log("[Payment] createIntent called with non-OMR currency '{$currency}' for {$type}/{$referenceId}, coercing to OMR. Caller should pass OMR.");
            $currency = 'OMR';
        }
        $currency = 'OMR';

        $amountCents = self::toSmallestUnit($amount, $currency);
        $prefix = ($type === 'subscription') ? 'SUB' : 'PO';
        $specialReference = "{$prefix}_{$companyId}_{$referenceId}_" . time();

        // Build real billing data from company info, with overrides
        $companyName = $company['name'] ?? 'Customer';
        $nameParts = explode(' ', $companyName, 2);
        // Paymob requires non-empty first_name, last_name, phone_number, coalesce empty strings too
        $coalesce = static function(...$vals) {
            foreach ($vals as $v) {
                if ($v !== null && $v !== '') return $v;
            }
            return '';
        };
        $paymobBilling = [
            'first_name' => $coalesce($billingData['first_name'] ?? null, $nameParts[0], 'Customer'),
            'last_name' => $coalesce($billingData['last_name'] ?? null, $nameParts[1] ?? null, $nameParts[0], 'N/A'),
            'phone_number' => $coalesce($billingData['phone_number'] ?? null, $billingData['phone'] ?? null, $company['phone'] ?? null, '+96800000000'),
            'email' => $coalesce(
                $billingData['email'] ?? null,
                $company['billing_email'] ?? null,
                $company['admin_email'] ?? null,
                // Paymob risk rules flag repeated emails. Fall back to a unique
                // per-company pseudo-email derived from company name + id.
                (function() use ($company, $companyId) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $company['name'] ?? ''));
                    if ($slug === '') $slug = 'company' . $companyId;
                    return substr($slug, 0, 40) . '@cardify.om';
                })()
            ),
            'apartment' => 'N/A',
            'floor' => 'N/A',
            'street' => $billingData['street'] ?? ($company['address'] ?? 'N/A'),
            'building' => 'N/A',
            'shipping_method' => 'N/A',
            'postal_code' => $billingData['postal_code'] ?? ($company['postal_code'] ?? 'N/A'),
            'city' => $billingData['city'] ?? ($company['city'] ?? 'Muscat'),
            'country' => $billingData['country'] ?? ($company['country'] ?? 'OM'),
            'state' => $billingData['state'] ?? ($company['state'] ?? 'N/A')
        ];

        // Create payment record in DB
        $validCycles = ['monthly', 'yearly', 'one_time'];
        $billingCycle = in_array($billingCycle, $validCycles) ? $billingCycle : 'monthly';

        $paymentId = generateUUID();
        $db->insert('payments', [
            'id' => $paymentId,
            'company_id' => $companyId,
            'type' => $type,
            'billing_cycle' => $billingCycle,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => $currency,
            'special_reference' => $specialReference,
            'status' => 'pending'
        ]);

        // Build Paymob intention payload
        $configuredHost = defined('APP_HOST') ? APP_HOST : 'cardify.om';
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $configuredHost;
        $callbackUrl = $baseUrl . getBasePath() . 'paymob/callback.php';

        $payload = [
            'amount' => $amountCents,
            'currency' => strtoupper($currency),
            'payment_methods' => $paymentMethods,
            'billing_data' => $paymobBilling,
            'special_reference' => $specialReference,
            'expiration' => 3600,
            'notification_url' => $callbackUrl,
            'redirection_url' => $callbackUrl,
            'items' => [],
            // Ask Paymob to return a reusable card_token on success so
            // repeat customers can one-click pay and MOTO agents can run
            // phone orders. Paymob only tokenises when BOTH `save_card`
            // and `recurring_payment_data.agreement` are present (the
            // lone `save_card_token` field is not a real Paymob field).
            // PCI scope stays at Paymob, we only get a token + last4.
            'save_card' => true,
            'recurring_payment_data' => [
                'agreement' => [
                    'id' => "CARDIFY-{$type}-{$specialReference}",
                    'variable_amount' => false,
                    'recurring_payment' => true,
                    'expiry' => null,
                ],
            ],
        ];

        // Add item description based on type
        if ($type === 'subscription') {
            $cycleLabel = $billingCycle === 'yearly' ? 'Annual' : 'Monthly';
            $payload['items'][] = [
                'name' => "Cardify Subscription ({$cycleLabel})",
                'amount' => $amountCents,
                'description' => "{$cycleLabel} subscription plan for {$companyName}",
                'quantity' => 1
            ];
        } elseif ($type === 'card_order') {
            $payload['items'][] = [
                'name' => 'Business Card Generation',
                'amount' => $amountCents,
                'description' => "Card generation for {$companyName}",
                'quantity' => 1
            ];
        } elseif ($type === 'print_order') {
            $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $referenceId]);
            $payload['items'][] = [
                'name' => 'Business Card Print Order',
                'amount' => $amountCents,
                'description' => ($order ? "Order #{$order['order_number']} - {$order['quantity']} cards" : "Print Order #{$referenceId}"),
                'quantity' => 1
            ];
        }

        // Call Paymob Intention API
        $ch = curl_init('https://oman.paymob.com/v1/intention/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $secretKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Paymob API curl error: " . $curlError);
            $db->update('payments', ['status' => 'failed', 'callback_data' => json_encode(['curl_error' => $curlError])], 'id = :id', ['id' => $paymentId]);
            return ['error' => 'Payment gateway connection failed'];
        }

        $responseData = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("Paymob API error [{$httpCode}]: " . $response);
            $db->update('payments', ['status' => 'failed', 'callback_data' => $response], 'id = :id', ['id' => $paymentId]);
            $errorMsg = $responseData['message'] ?? $responseData['detail'] ?? 'Payment gateway error';
            return ['error' => $errorMsg];
        }

        $clientSecret = $responseData['client_secret'] ?? null;
        if (empty($clientSecret)) {
            error_log("Paymob API: No client_secret: " . $response);
            $db->update('payments', ['status' => 'failed', 'callback_data' => $response], 'id = :id', ['id' => $paymentId]);
            return ['error' => 'Invalid gateway response'];
        }

        // Update payment with intention ID
        $intentionId = $responseData['id'] ?? null;
        if ($intentionId) {
            $db->update('payments', ['paymob_intention_id' => $intentionId], 'id = :id', ['id' => $paymentId]);
        }

        $checkoutUrl = 'https://oman.paymob.com/unifiedcheckout/?publicKey=' . urlencode($publicKey) . '&clientSecret=' . urlencode($clientSecret);

        // Store in session for callback matching
        $_SESSION["paymob_payment_{$specialReference}"] = [
            'payment_id' => $paymentId,
            'type' => $type,
            'billing_cycle' => $billingCycle,
            'reference_id' => $referenceId,
            'company_id' => $companyId,
            'amount' => $amountCents,
            'currency' => $currency
        ];

        return [
            'success' => true,
            'payment_id' => $paymentId,
            'checkout_url' => $checkoutUrl,
            'payment_url' => $checkoutUrl, // Alias for backward compat with Billing.php
            'special_reference' => $specialReference,
            'transaction_id' => $specialReference, // Alias for backward compat
            // Exposed for the INLINE Apple Pay + Pixel card flow (admin/paymob-intent.php).
            // These are the same keypair already embedded in $checkoutUrl above, surfaced
            // so the browser can drive the Pixel/Apple Pay element itself. Additive: the
            // hosted-redirect callers ignore these keys.
            'public_key' => $publicKey,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * Handle Paymob callback (both GET redirect and POST webhook)
     */
    public static function handleCallback(array $data, ?string $hmac = null): array {
        $db = Database::getInstance();

        // Flatten nested Paymob response (POST webhook has 'obj' wrapper)
        if (isset($data['obj'])) {
            $obj = $data['obj'];
            $data = array_merge($data, $obj);
            if (isset($obj['order'])) {
                $data['order'] = is_array($obj['order']) ? ($obj['order']['id'] ?? '') : $obj['order'];
            }
            if (isset($obj['source_data'])) {
                $data['source_data_pan'] = $obj['source_data']['pan'] ?? '';
                $data['source_data_sub_type'] = $obj['source_data']['sub_type'] ?? '';
                $data['source_data_type'] = $obj['source_data']['type'] ?? '';
            }
            // Paymob returns the reusable card_token on the obj root
            // when save_card+recurring_payment_data were on the intent.
            // Shape varies a bit across Paymob flavours, pick the first
            // one present.
            $data['card_token'] = $obj['token']
                ?? ($obj['card_token'] ?? '')
                ?: ($obj['source_data']['card_token'] ?? '');

            // Card detail fields. Per Paymob Oman webhook spec the
            // holder/expiry/bin/issuer/country live on obj.data, while
            // brand + last4 + payment method live on obj.source_data.
            $objData = isset($obj['data']) && is_array($obj['data']) ? $obj['data'] : [];
            $srcData = isset($obj['source_data']) && is_array($obj['source_data']) ? $obj['source_data'] : [];

            $data['card_brand']         = $srcData['sub_type'] ?? ($srcData['brand'] ?? '');
            $data['card_last4']         = $srcData['pan'] ?? '';
            $data['card_payment_method']= $srcData['type'] ?? '';
            $data['card_masked']        = $objData['card_num'] ?? '';
            $data['card_type']          = $objData['card_type'] ?? '';
            $data['card_holder']        = $objData['card_holder_name'] ?? ($objData['name_on_card'] ?? '');
            $data['card_exp_m']         = $objData['expiry_month'] ?? null;
            $data['card_exp_y']         = $objData['expiry_year'] ?? null;
            $data['card_bin']           = $objData['bin'] ?? '';
            $data['card_issuer']        = $objData['issuer'] ?? '';
            $data['card_country']       = $objData['country'] ?? ($objData['card_country'] ?? '');
            $data['card_integration_id']= $obj['integration_id'] ?? null;
        }

        // Merge GET params if available
        if (!empty($_GET)) {
            $data = array_merge($data, $_GET);
        }

        $success = $data['success'] ?? null;
        $transactionId = $data['id'] ?? null;
        $orderId = $data['order'] ?? null;
        $merchantOrderId = $data['merchant_order_id'] ?? ($data['special_reference'] ?? null);
        $amountCents = $data['amount_cents'] ?? null;
        $currency = $data['currency'] ?? 'OMR';

        // Verify HMAC, required; reject any callback without a valid signature
        $receivedHmac = $hmac ?? ($data['hmac'] ?? null);
        if (empty($receivedHmac)) {
            error_log("Payment callback: Missing HMAC for transaction {$transactionId}");
            return ['success' => false, 'error' => 'Missing HMAC signature'];
        }
        if (!self::verifyHmac($data, $receivedHmac)) {
            error_log("Payment callback: HMAC mismatch for transaction {$transactionId}");
            return ['success' => false, 'error' => 'Invalid HMAC signature'];
        }

        if (empty($merchantOrderId)) {
            error_log("Payment callback: No merchant_order_id/special_reference");
            return ['success' => false, 'error' => 'Missing order reference'];
        }

        // Find payment in new payments table
        $payment = self::getBySpecialReference($merchantOrderId);

        // Fallback to session
        if (!$payment) {
            $sessionKey = "paymob_payment_{$merchantOrderId}";
            if (isset($_SESSION[$sessionKey])) {
                $sessionData = $_SESSION[$sessionKey];
                $payment = self::getById($sessionData['payment_id']);
            }
        }

        // If still not found, this might be an old subscription callback, delegate to legacy
        if (!$payment) {
            error_log("Payment callback: Not found in payments table for {$merchantOrderId}, checking legacy");
            return ['success' => false, 'error' => 'Payment not found', 'legacy' => true, 'merchant_order_id' => $merchantOrderId];
        }

        // Idempotency: if already processed, return the stored result without re-running side effects
        if ($payment['status'] === 'paid') {
            return [
                'success' => true,
                'payment_id' => $payment['id'],
                'type' => $payment['type'],
                'reference_id' => $payment['reference_id'],
                'status' => 'paid',
                'idempotent' => true
            ];
        }

        // Verify amount matches
        if ($amountCents !== null && $payment['amount'] !== null) {
            $expectedAmount = self::toSmallestUnit((float)$payment['amount'], $payment['currency'] ?? $currency);
            if ((int)$amountCents !== $expectedAmount) {
                error_log("Payment callback: Amount mismatch for {$merchantOrderId}. Expected: {$expectedAmount}, Got: {$amountCents}");
                return ['success' => false, 'error' => 'Amount mismatch'];
            }
        }

        // Determine status
        $isSuccess = ($success === 'true' || $success === true);
        $status = $isSuccess ? 'paid' : 'failed';
        $paymentMethod = $data['source_data_type'] ?? null;

        // Update payment record
        $db->update('payments',
            [
                'status' => $status,
                'paymob_order_id' => $orderId,
                'paymob_transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'callback_data' => json_encode($data)
            ],
            'id = :id',
            ['id' => $payment['id']]
        );

        // Clear session
        unset($_SESSION["paymob_payment_{$merchantOrderId}"]);

        // Dispatch based on type
        if ($isSuccess) {
            if ($payment['type'] === 'subscription') {
                self::activateSubscription($payment);
            } elseif ($payment['type'] === 'print_order') {
                self::confirmPrintOrder($payment);
            } elseif ($payment['type'] === 'card_order') {
                self::confirmCardOrder($payment);
            }
            // Cancel any open retry, a later successful payment closes the
            // dunning loop whether it came through the retry link or a fresh
            // checkout the user started themselves.
            try {
                require_once INCLUDES_DIR . '/PaymentRetry.php';
                PaymentRetry::markSucceeded((string) $payment['id']);
            } catch (Throwable $_) {}

            // Persist the reusable Paymob card_token so repeat charges
            // (MOTO phone orders, subscription renewals, one-click
            // top-ups) can pay without the customer present. PCI scope
            // stays at Paymob, we only store last4 + masked PAN + brand
            // for display + routing metadata (BIN, issuer, country).
            //
            // Upsert key is (company_id, last_four), same physical card
            // re-saved overwrites the row (fresh token + timestamps),
            // new card appends.
            if (!empty($data['card_token']) && !empty($payment['company_id'])) {
                $last4 = substr((string) ($data['card_last4'] ?? ''), -4);
                if ($last4 !== '') {
                    try {
                        $db->exec(
                            "INSERT INTO saved_cards
                                (company_id, paymob_token, last_four, masked_number, brand,
                                 card_type, payment_method, holder_name,
                                 expiry_month, expiry_year, bin, issuer, country,
                                 integration_id, last_used_at)
                             VALUES
                                (:c, :t, :l4, :mask, :b,
                                 :ctype, :pmethod, :h,
                                 :em, :ey, :bin, :iss, :cty,
                                 :iid, NOW())
                             ON DUPLICATE KEY UPDATE
                                paymob_token = VALUES(paymob_token),
                                masked_number = VALUES(masked_number),
                                brand = VALUES(brand),
                                card_type = VALUES(card_type),
                                payment_method = VALUES(payment_method),
                                holder_name = VALUES(holder_name),
                                expiry_month = VALUES(expiry_month),
                                expiry_year = VALUES(expiry_year),
                                bin = VALUES(bin),
                                issuer = VALUES(issuer),
                                country = VALUES(country),
                                integration_id = VALUES(integration_id),
                                last_used_at = NOW()",
                            [
                                'c'       => $payment['company_id'],
                                't'       => $data['card_token'],
                                'l4'      => $last4,
                                'mask'    => substr((string) ($data['card_masked'] ?? ''), 0, 32),
                                'b'       => substr((string) ($data['card_brand'] ?? ''), 0, 24),
                                'ctype'   => substr((string) ($data['card_type'] ?? ''), 0, 16),
                                'pmethod' => substr((string) ($data['card_payment_method'] ?? ''), 0, 32),
                                'h'       => substr((string) ($data['card_holder'] ?? ''), 0, 120),
                                'em'      => is_numeric($data['card_exp_m'] ?? null) ? (int) $data['card_exp_m'] : null,
                                'ey'      => is_numeric($data['card_exp_y'] ?? null) ? (int) $data['card_exp_y'] : null,
                                'bin'     => substr((string) ($data['card_bin'] ?? ''), 0, 8),
                                'iss'     => substr((string) ($data['card_issuer'] ?? ''), 0, 128),
                                'cty'     => substr((string) ($data['card_country'] ?? ''), 0, 8),
                                'iid'     => is_numeric($data['card_integration_id'] ?? null) ? (int) $data['card_integration_id'] : null,
                            ]
                        );
                    } catch (Throwable $e) {
                        error_log('saved_cards upsert failed: ' . $e->getMessage());
                    }
                }
            }
        } else {
            // Payment failed, enqueue a dunning retry (Cat S action 455).
            try {
                require_once INCLUDES_DIR . '/PaymentRetry.php';
                $failReason = (string) ($data['data.message'] ?? $data['message'] ?? 'declined');
                $freshPayment = array_merge($payment, ['status' => 'failed']);
                PaymentRetry::enqueueFromFailed($freshPayment, $failReason);
            } catch (Throwable $_) {}
        }

        // Fire payment_success / payment_failed notifications via Notifier
        try {
            $company = null;
            if (!empty($payment['company_id'])) {
                $company = $db->fetchOne("SELECT * FROM companies WHERE id = :id", ['id' => $payment['company_id']]);
            }
            $order = null;
            if ($payment['type'] === 'print_order' && !empty($payment['reference_id'])) {
                $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = :id", ['id' => $payment['reference_id']]);
            }

            require_once INCLUDES_DIR . '/Notifier.php';
            require_once INCLUDES_DIR . '/Currency.php';

            $omrAmount = Currency::format((float)$payment['amount'], 'OMR');
            $displayAmount = $omrAmount; // callback has no user session; charge currency is authoritative
            $orderNumber = $order['order_number'] ?? ($payment['reference_id'] ?? '');

            if ($isSuccess) {
                Notifier::send('payment_success', [
                    'name'       => $company['name_en'] ?? $company['name'] ?? 'Customer',
                    'email'      => $company['admin_email'] ?? $company['email'] ?? null,
                    'phone'      => $company['phone'] ?? null,
                    'company_id' => $company['id'] ?? ($payment['company_id'] ?? null),
                ], [
                    'name'          => $company['name_en'] ?? $company['name'] ?? 'Customer',
                    'orderNumber'   => $orderNumber,
                    'displayAmount' => $displayAmount,
                    'omrAmount'     => $omrAmount,
                ]);
            } else {
                // Retry URL points to the admin print page (company-facing print orders live there).
                // company/orders.php does not exist, the old value was broken.
                $retryUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
                          . ($_SERVER['HTTP_HOST'] ?? 'cardify.om')
                          . getBasePath() . 'admin/print.php?retry=' . urlencode($order['id'] ?? ($payment['reference_id'] ?? ''));

                Notifier::send('payment_failed', [
                    'name'       => $company['name_en'] ?? $company['name'] ?? 'Customer',
                    'email'      => $company['admin_email'] ?? $company['email'] ?? null,
                    'phone'      => $company['phone'] ?? null,
                    'company_id' => $company['id'] ?? ($payment['company_id'] ?? null),
                ], [
                    'name'        => $company['name_en'] ?? $company['name'] ?? 'Customer',
                    'orderNumber' => $orderNumber,
                    'retryUrl'    => $retryUrl,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[paymob callback] Notifier ' . ($isSuccess ? 'success' : 'failure') . ' failed: ' . $e->getMessage());
        }

        return [
            'success' => $isSuccess,
            'payment_id' => $payment['id'],
            'type' => $payment['type'],
            'reference_id' => $payment['reference_id'],
            'status' => $status
        ];
    }

    /**
     * Activate subscription after successful payment
     */
    private static function activateSubscription(array $payment): void {
        $db = Database::getInstance();
        $planId = $payment['reference_id'];
        $companyId = $payment['company_id'];

        // Use stored billing_cycle (reliable); fall back to price comparison for legacy rows
        $billingCycle = $payment['billing_cycle'] ?? null;
        if (!$billingCycle) {
            $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = :id", ['id' => $planId]);
            $billingCycle = ($plan && (float)$plan['price_yearly'] > 0 &&
                abs((float)$payment['amount'] - (float)$plan['price_yearly']) < 0.50)
                ? 'yearly' : 'monthly';
        }

        // Extend from current expiry if still active, otherwise extend from now
        $currentCompany = $db->fetchOne("SELECT subscription_expires_at, subscription_status FROM companies WHERE id = :id", ['id' => $companyId]);
        $baseTime = time();
        if ($currentCompany && $currentCompany['subscription_status'] === 'active' && !empty($currentCompany['subscription_expires_at'])) {
            $currentExpiry = dbTs($currentCompany['subscription_expires_at']);
            if ($currentExpiry > $baseTime) {
                $baseTime = $currentExpiry;
            }
        }
        $expiresAt = date('Y-m-d H:i:s', dbTs($billingCycle === 'yearly' ? '+1 year' : '+1 month', $baseTime));

        $db->update('companies',
            [
                'plan' => $planId,
                'subscription_status' => 'active',
                'subscription_expires_at' => $expiresAt,
                'subscription_id' => $payment['id']
            ],
            'id = :id',
            ['id' => $companyId]
        );

        error_log("Subscription activated: company={$companyId} plan={$planId} cycle={$billingCycle} expires={$expiresAt}");

        // BHD-234: referral reward on first paid conversion.
        try {
            require_once INCLUDES_DIR . '/Referral.php';
            Referral::onPaidConversion((string)$companyId, (float)($payment['amount'] ?? 0));
        } catch (Throwable $e) {
            error_log('[referral] paid hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Confirm print order after successful payment
     */
    private static function confirmPrintOrder(array $payment): void {
        $db = Database::getInstance();
        $orderId = $payment['reference_id'];

        $db->query(
            "UPDATE print_orders SET payment_status = 'paid', payment_method = 'online', payment_id = :pid, status = 'confirmed' WHERE id = :id",
            ['pid' => $payment['id'], 'id' => $orderId]
        );

        // Sync the paid order to BHD-ERP (Quote -> Invoice -> Payment). Online
        // Paymob payments used to skip this: only the print shop's manual
        // "mark paid" fired ERPSync, so a customer who paid online never got an
        // ERP invoice. recordPayment is idempotent on orderNumber (409 = safe),
        // so a later manual mark-paid double-firing is harmless.
        try {
            require_once INCLUDES_DIR . '/ERPSync.php';
            if (ERPSync::isEnabled()) {
                $ref  = $payment['paymob_transaction_id'] ?? $payment['id'] ?? '';
                // If the order already carries an approve-time ERP quote,
                // convert THAT quote to invoice + sales order + delivery note.
                // Do not call recordPayment here: it is idempotent on the
                // Cardify:{orderNumber} marker the quote already wrote, so it
                // would 409 and skip the invoice (or mint a second quote).
                // Closing AR / the payment JE for this converted-invoice path
                // is the ERP record-payment-reuse follow-up.
                $ord = $db->fetchOne("SELECT erp_quote_id FROM print_orders WHERE id = :id", ['id' => $orderId]);
                if (!empty($ord['erp_quote_id'])) {
                    $sync = ERPSync::convertQuoteToInvoice((int)$orderId, 'payment');
                } else {
                    $sync = ERPSync::recordPayment((int)$orderId, 'online', (string)$ref, 'Paid online via Paymob', '');
                }
                if (empty($sync['success'])) {
                    error_log("ERPSync (online) failed for order {$orderId}: " . ($sync['message'] ?? 'unknown'));
                }
            }
        } catch (Throwable $e) {
            error_log("ERPSync (online) exception for order {$orderId}: " . $e->getMessage());
        }

        // Send notifications. Catch Throwable, not just Exception: the order is
        // already marked paid + ERP-synced above, so a notification failure must
        // never bubble a fatal out of the payment callback (that would 500 the
        // Paymob webhook and trigger pointless retries).
        try {
            if (file_exists(INCLUDES_DIR . '/PrintShopIntegration.php')) {
                require_once INCLUDES_DIR . '/PrintShopIntegration.php';
                PrintShopIntegration::sendStatusUpdateEmail($orderId, 'confirmed', null);
            }
        } catch (\Throwable $e) {
            error_log("Payment notification failed for order {$orderId}: " . $e->getMessage());
        }
    }

    /**
     * Unlock card generation via per-card payment
     * Called when a Free user tries to generate a card beyond their monthly limit
     *
     * @param string $companyId
     * @param int $cardCount Number of cards to purchase (default 1)
     * @param float $pricePerCard Price per card in OMR
     * @param string $currency
     * @return array ['checkout_url'=>...] or ['error'=>...]
     */
    public static function createCardOrderIntent(
        string $companyId,
        int $cardCount = 1,
        float $pricePerCard = 0.500,
        string $currency = 'OMR'
    ): array {
        $amount = round($pricePerCard * $cardCount, 3);

        // Store card count in a temp DB record we can reference on callback
        $db = Database::getInstance();
        $refId = generateUUID();
        $db->insert('card_order_credits', [
            'id' => $refId,
            'company_id' => $companyId,
            'card_count' => $cardCount,
            'price_per_card' => $pricePerCard,
            'status' => 'pending',
            'currency' => $currency,
        ]);

        return self::createIntent('card_order', $refId, $amount, $companyId, [], $currency, 'one_time');
    }

    /**
     * Confirm card order, add credits to company after payment
     */
    private static function confirmCardOrder(array $payment): void {
        $db = Database::getInstance();
        $refId = $payment['reference_id'];

        $creditRow = $db->fetchOne("SELECT * FROM card_order_credits WHERE id = :id", ['id' => $refId]);
        if (!$creditRow) {
            error_log("confirmCardOrder: no credit row for ref {$refId}");
            return;
        }

        $cardCount = (int)$creditRow['card_count'];
        $companyId = $payment['company_id'];

        // Only process if credit row is still pending (idempotency)
        if ($creditRow['status'] !== 'pending') {
            error_log("confirmCardOrder: credit row {$refId} already processed (status={$creditRow['status']})");
            return;
        }

        // Mark credit row as paid first (prevents duplicate processing on race)
        $affected = $db->update('card_order_credits',
            ['status' => 'paid', 'payment_id' => $payment['id']],
            'id = :id AND status = :status',
            ['id' => $refId, 'status' => 'pending']
        );
        if (!$affected) {
            error_log("confirmCardOrder: credit row {$refId} was already claimed by another process");
            return;
        }

        // Atomic increment, no race condition
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE companies SET card_credits = card_credits + ? WHERE id = ?");
        $stmt->execute([$cardCount, $companyId]);

        // Mirror into card_credit_ledger so /admin/card-credits and the
        // statement page see the top-up (Cat S action 451).
        try {
            $newBalance = (int) ($db->fetchOne("SELECT card_credits FROM companies WHERE id = :id", ['id' => $companyId])['card_credits'] ?? 0);
            $db->exec(
                "INSERT INTO card_credit_ledger
                    (company_id, employee_id, delta, balance_after, reason, ref_id, notes)
                 VALUES (:c, NULL, :d, :b, 'purchase', :ref, NULL)",
                ['c' => $companyId, 'd' => $cardCount, 'b' => $newBalance, 'ref' => $refId]
            );
        } catch (Throwable $_) { /* ledger table may not exist pre-091 */ }

        error_log("Card credits added: company={$companyId} cards={$cardCount}");

        // Push the sale into BHD-ERP so the client ledger shows it
        // (Cat S action 453). Non-fatal; failures only log.
        try {
            require_once INCLUDES_DIR . '/ERPSync.php';
            $sync = ERPSync::recordCardCreditPurchase($payment['id']);
            if (empty($sync['success'])) {
                error_log("confirmCardOrder: ERP sync non-fatal failure: " . ($sync['message'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            error_log("confirmCardOrder: ERP sync threw: " . $e->getMessage());
        }
    }

    // --- Query methods ---

    public static function getById(string $paymentId): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM payments WHERE id = :id", ['id' => $paymentId]);
        return $row ?: null;
    }

    public static function getByCompany(string $companyId, ?string $type = null): array {
        $db = Database::getInstance();
        $sql = "SELECT * FROM payments WHERE company_id = :cid";
        $params = ['cid' => $companyId];
        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }
        $sql .= " ORDER BY created_at DESC";
        return $db->fetchAll($sql, $params);
    }

    public static function getBySpecialReference(string $ref): ?array {
        $db = Database::getInstance();
        $row = $db->fetchOne("SELECT * FROM payments WHERE special_reference = :ref", ['ref' => $ref]);
        return $row ?: null;
    }
}
