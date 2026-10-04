<?php
declare(strict_types=1);

require_once __DIR__ . '/IqStore.php';
require_once dirname(__DIR__) . '/Payment.php';

/*
 * Paid IQ products, through Cardify's Paymob merchant.
 *
 *   report     one attempt's full report: every question reviewed, time per question (one-off)
 *   certificate one attempt's verified IQ certificate: A4 PDF with the confirmed name, a number,
 *              a QR code and a public verification page (one-off, OMR 4.900)
 *   pro_month  IQ Pro for 30 days: retakes without the 30-day wait, every report and
 *              certificate, progress over time, practice mode, no ads (one-off, renewed by hand;
 *              nothing is charged again without the person paying again)
 *
 * Prices live in iq_settings (price_report, price_pro_month, OMR to 3 decimals). A product without
 * a price is not offered, so nothing can be sold before Ali sets one.
 *
 * Callbacks reach paymob/callback.php, which hands any reference starting IQ_ to handleCallback()
 * here. The same rules as Payment::handleCallback apply: HMAC required, the Paymob order is bound
 * on first sight, the amount must match, and a paid row is never processed twice.
 */
final class IqPay
{
    public const PRODUCTS = ['report', 'pro_month', 'certificate'];

    public static function price(string $product): ?float
    {
        $v = IqStore::setting('price_' . $product);
        if ($v === null || !is_numeric($v) || (float)$v <= 0) return null;
        return round((float)$v, 3);
    }

    public static function prices(): array
    {
        return ['report' => self::price('report'), 'pro_month' => self::price('pro_month'), 'certificate' => self::price('certificate')];
    }

    /** Starts a Paymob checkout. Returns the hosted checkout URL. */
    public static function checkout(array $user, string $product, ?array $attempt, array $meta = []): string
    {
        if (!in_array($product, self::PRODUCTS, true)) throw new IqError('unknown_product');
        $amount = self::price($product);
        if ($amount === null) throw new IqError('not_on_sale', 409);
        if (in_array($product, ['report', 'certificate'], true) && (!$attempt || $attempt['status'] !== 'done')) throw new IqError('no_result', 409);
        if ($product === 'certificate') {
            if (!empty($attempt['cert_no'])) throw new IqError('already_issued', 409);
            $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\p{C}<>]+/u', ' ', (string)($meta['name'] ?? '')) ?? '') ?? '');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 80) throw new IqError('cert_name', 422);
            $meta = ['name' => $name];
        }

        $secret = defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '';
        $public = defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '';
        $methods = array_map('intval', array_filter(explode(',', defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : '')));
        if ($secret === '' || $public === '' || !$methods) throw new IqError('payments_unavailable', 503);

        $db = Database::getInstance();
        $id = generateUUID();
        $ref = 'IQ_' . strtoupper(substr($product, 0, 3)) . '_' . substr(str_replace('-', '', $id), 0, 20) . '_' . time();
        $db->insert('iq_payments', [
            'id' => $id, 'user_id' => $user['id'], 'attempt_id' => $attempt['id'] ?? null,
            'product' => $product, 'amount' => $amount, 'special_reference' => $ref, 'status' => 'pending',
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $cents = Payment::toSmallestUnit($amount, 'OMR');
        $name = trim((string)($user['display_name'] ?: 'IQ Customer'));
        $parts = explode(' ', $name, 2);
        $host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
        $callback = 'https://' . $host . '/paymob/callback.php';
        $label = ['report' => 'IQ full report', 'certificate' => 'Verified IQ certificate', 'pro_month' => 'IQ Pro, 30 days'][$product];
        $payload = [
            'amount' => $cents,
            'currency' => 'OMR',
            'payment_methods' => $methods,
            'billing_data' => [
                'first_name' => $parts[0] ?: 'IQ',
                'last_name' => $parts[1] ?? 'Customer',
                'email' => $user['email'] ?: ('iq-' . substr($user['id'], 0, 8) . '@cardify.om'),
                'phone_number' => $user['phone'] ?: '+96800000000',
                'apartment' => 'N/A', 'floor' => 'N/A', 'street' => 'N/A', 'building' => 'N/A',
                'shipping_method' => 'N/A', 'postal_code' => 'N/A', 'city' => 'Muscat',
                'country' => $user['country'] ?: 'OM', 'state' => 'N/A',
            ],
            'special_reference' => $ref,
            'expiration' => 3600,
            'notification_url' => $callback,
            'redirection_url' => $callback,
            'items' => [['name' => $label, 'amount' => $cents, 'description' => $label, 'quantity' => 1]],
        ];
        $ch = curl_init('https://oman.paymob.com/v1/intention/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Token ' . $secret, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = is_string($raw) ? json_decode($raw, true) : null;
        if (($code !== 200 && $code !== 201) || empty($res['client_secret'])) {
            error_log('[iq] paymob intention failed ' . $code . ': ' . substr((string)$raw, 0, 400));
            $db->update('iq_payments', ['status' => 'failed', 'callback_data' => json_encode(['http' => $code])], 'id = :id', ['id' => $id]);
            throw new IqError('payments_unavailable', 502);
        }
        return 'https://oman.paymob.com/unifiedcheckout/?publicKey=' . urlencode($public) . '&clientSecret=' . urlencode($res['client_secret']);
    }

    public static function isIqReference(?string $ref): bool
    {
        return is_string($ref) && strncmp($ref, 'IQ_', 3) === 0;
    }

    /** Paymob callback for an IQ_ reference. Returns ['success'=>bool, 'product'=>..., 'attempt'=>public_id]. */
    public static function handleCallback(array $data, ?string $hmac): array
    {
        $data = Payment::flattenCallback($data, $_GET ?? []);
        $received = $hmac ?? ($data['hmac'] ?? null);
        if (empty($received) || !Payment::verifyHmac($data, (string)$received)) {
            error_log('[iq] callback rejected: bad or missing HMAC');
            return ['success' => false, 'error' => 'Invalid signature'];
        }
        $ref = $data['merchant_order_id'] ?? ($data['special_reference'] ?? null);
        $db = Database::getInstance();
        $pay = $db->fetchOne('SELECT * FROM iq_payments WHERE special_reference = :r', ['r' => (string)$ref]);
        if (!$pay) return ['success' => false, 'error' => 'Payment not found'];
        $out = ['product' => $pay['product'], 'attempt' => null];
        if ($pay['attempt_id']) {
            $a = $db->fetchOne('SELECT public_id FROM iq_attempts WHERE id = :id', ['id' => $pay['attempt_id']]);
            $out['attempt'] = $a['public_id'] ?? null;
        }
        if ($pay['status'] === 'paid') return ['success' => true, 'idempotent' => true] + $out;

        $orderId = isset($data['order']) ? (string)$data['order'] : null;
        if (!empty($pay['paymob_order_id']) && $orderId !== null && $pay['paymob_order_id'] !== $orderId) {
            return ['success' => false, 'error' => 'Order mismatch'] + $out;
        }
        if (isset($data['amount_cents']) && (int)$data['amount_cents'] !== Payment::toSmallestUnit((float)$pay['amount'], 'OMR')) {
            error_log('[iq] callback amount mismatch for ' . $ref);
            return ['success' => false, 'error' => 'Amount mismatch'] + $out;
        }
        $ok = ($data['success'] ?? null) === 'true' || ($data['success'] ?? null) === true;

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            // Re-read under lock so two callbacks (redirect + webhook) cannot both grant.
            $st = $conn->prepare('SELECT status FROM iq_payments WHERE id = :id FOR UPDATE');
            $st->execute([':id' => $pay['id']]);
            if ($st->fetchColumn() === 'paid') { $conn->commit(); return ['success' => true, 'idempotent' => true] + $out; }
            $conn->prepare('UPDATE iq_payments SET status = :s, paymob_order_id = :o, transaction_id = :t, callback_data = :d, paid_at = :p WHERE id = :id')
                ->execute([':s' => $ok ? 'paid' : 'failed', ':o' => $orderId, ':t' => isset($data['id']) ? (string)$data['id'] : null,
                    ':d' => json_encode($data), ':p' => $ok ? date('Y-m-d H:i:s') : null, ':id' => $pay['id']]);
            if ($ok) {
                if ($pay['product'] === 'report' && $pay['attempt_id']) {
                    $conn->prepare("UPDATE iq_attempts SET report_paid = 1, unlocked_by = COALESCE(unlocked_by, 'paid') WHERE id = :id")->execute([':id' => $pay['attempt_id']]);
                } elseif ($pay['product'] === 'certificate' && $pay['attempt_id']) {
                    $meta = json_decode((string)$pay['meta'], true) ?: [];
                    $conn->prepare('UPDATE iq_attempts SET cert_no = :n, cert_name = :m, cert_issued_at = :t WHERE id = :id AND cert_no IS NULL')
                        ->execute([':n' => self::newCertNo(), ':m' => mb_substr((string)($meta['name'] ?? ''), 0, 80), ':t' => date('Y-m-d H:i:s'), ':id' => $pay['attempt_id']]);
                } elseif ($pay['product'] === 'pro_month') {
                    // 30 days from today, or from the end of a pass that is still running.
                    $conn->prepare('UPDATE iq_users SET pro_until = DATE_ADD(GREATEST(COALESCE(pro_until, NOW()), NOW()), INTERVAL 30 DAY) WHERE id = :u')
                        ->execute([':u' => $pay['user_id']]);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            error_log('[iq] callback failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Server error'] + $out;
        }
        return ['success' => $ok, 'error' => $ok ? null : 'Payment was not completed'] + $out;
    }

    /** A certificate number: CIQ-<year>-<8 characters>, no look-alike letters. */
    public static function newCertNo(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 8; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        return 'CIQ-' . date('Y') . '-' . $s;
    }

    public static function byCertNo(string $no): ?array
    {
        if (!preg_match('/^CIQ-\d{4}-[A-Z0-9]{8}$/', $no)) return null;
        $a = Database::getInstance()->fetchOne('SELECT * FROM iq_attempts WHERE cert_no = :n', ['n' => $no]);
        return $a ?: null;
    }

    /** Has this person paid for this attempt's full report (or is Pro)? */
    public static function canSeeReport(?array $user, array $attempt): bool
    {
        if ((int)$attempt['report_paid'] === 1) return IqStore::owns($attempt);
        return $user !== null && $attempt['user_id'] === $user['id'] && IqStore::isPro($user);
    }
}
