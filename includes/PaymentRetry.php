<?php
/**
 * Paymob payment retry queue (Cat S action 455).
 *
 * When a Paymob payment lands in `failed` status, enqueueFromFailed()
 * adds a row to payment_retries. runQueue() is called by cron every
 * hour; it picks due rows, creates a fresh Paymob intent and sends
 * the new checkout link by WhatsApp + email. After 3 attempts (2d /
 * 3d / 2d apart, totalling ~7 days) the row is marked 'exhausted'
 * and no further retries fire.
 *
 * Behaviour matches real-world dunning, not automated card charges,
 * which Paymob does not permit without a stored-PAN vault on our side.
 */
class PaymentRetry
{
    /** Hours to wait before attempt N (1-indexed). Sums to ~168h = 7 days. */
    private const LADDER_HOURS = [48, 72, 48];

    /**
     * Enqueue a retry from a freshly-failed Paymob payment row. Safe
     * to call multiple times for the same payment (UNIQUE on
     * original_payment_id).
     */
    public static function enqueueFromFailed(array $payment, string $error = ''): void
    {
        if (!in_array($payment['type'] ?? '', ['subscription', 'print_order', 'card_order'], true)) return;

        $db = Database::getInstance();
        $next = (new DateTime('now'))->modify('+' . self::LADDER_HOURS[0] . ' hours')->format('Y-m-d H:i:s');

        try {
            $db->exec(
                "INSERT INTO payment_retries
                    (original_payment_id, target_type, company_id, reference_id, amount,
                     currency, billing_cycle, attempts, last_error, next_retry_at, status)
                 VALUES (:oid, :tt, :cid, :ref, :amt, :cur, :bc, 0, :err, :next, 'pending')
                 ON DUPLICATE KEY UPDATE
                    last_error = VALUES(last_error), next_retry_at = VALUES(next_retry_at),
                    status = IF(status = 'succeeded', 'succeeded', 'pending')",
                [
                    'oid' => $payment['id'],
                    'tt'  => $payment['type'],
                    'cid' => $payment['company_id'],
                    'ref' => $payment['reference_id'],
                    'amt' => (float) $payment['amount'],
                    'cur' => $payment['currency'] ?? 'OMR',
                    'bc'  => $payment['billing_cycle'] ?? null,
                    'err' => mb_substr($error, 0, 2000),
                    'next'=> $next,
                ]
            );
        } catch (Throwable $e) {
            error_log('PaymentRetry::enqueueFromFailed: ' . $e->getMessage());
        }
    }

    /** Mark any pending retry for this original payment as succeeded. */
    public static function markSucceeded(string $originalPaymentId): void
    {
        try {
            Database::getInstance()->exec(
                "UPDATE payment_retries SET status = 'succeeded', updated_at = NOW()
                  WHERE original_payment_id = :id AND status = 'pending'",
                ['id' => $originalPaymentId]
            );
        } catch (Throwable $_) {}
    }

    /** Runner: called by cron every hour. Returns per-run counters. */
    public static function runQueue(int $limit = 20): array
    {
        require_once __DIR__ . '/Payment.php';
        require_once __DIR__ . '/WhatsApp.php';
        require_once __DIR__ . '/Mailer.php';

        $out = ['processed' => 0, 'succeeded_send' => 0, 'exhausted' => 0, 'errors' => 0];
        $db  = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT pr.*, c.name AS company_name, c.admin_email, c.phone
               FROM payment_retries pr
          LEFT JOIN companies c ON c.id = pr.company_id
              WHERE pr.status = 'pending' AND pr.next_retry_at <= NOW()
           ORDER BY pr.next_retry_at ASC
              LIMIT " . (int) $limit
        );
        foreach ($rows as $r) {
            $out['processed']++;
            try {
                // Create a fresh Paymob intent for the same reference.
                $res = Payment::createIntent(
                    $r['target_type'],
                    $r['reference_id'],
                    (float) $r['amount'],
                    $r['company_id'],
                    [],
                    $r['currency'] ?? 'OMR',
                    $r['billing_cycle'] ?? 'monthly'
                );
                if (empty($res['checkout_url'])) {
                    throw new Exception($res['error'] ?? 'createIntent returned no checkout_url');
                }

                $newAttempts = (int) $r['attempts'] + 1;
                $laddermax   = count(self::LADDER_HOURS);
                $exhausted   = $newAttempts >= $laddermax;
                $nextHours   = $exhausted ? 0 : self::LADDER_HOURS[$newAttempts];
                $next        = $exhausted
                    ? null
                    : (new DateTime('now'))->modify("+{$nextHours} hours")->format('Y-m-d H:i:s');

                $db->exec(
                    "UPDATE payment_retries SET
                        attempts = :a, last_attempt_at = NOW(),
                        last_checkout_url = :url,
                        next_retry_at = COALESCE(:next, next_retry_at),
                        status = :s
                     WHERE id = :id",
                    [
                        'a'   => $newAttempts,
                        'url' => $res['checkout_url'],
                        'next'=> $next,
                        's'   => $exhausted ? 'exhausted' : 'pending',
                        'id'  => $r['id'],
                    ]
                );

                // Notify the customer with the fresh link.
                $host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
                $amt  = number_format((float) $r['amount'], 3);
                $msg  = "Cardify: your {$r['target_type']} payment of {$amt} {$r['currency']} is pending."
                      . "\nThis is attempt {$newAttempts}/{$laddermax} to collect it."
                      . "\nPay here: {$res['checkout_url']}"
                      . "\nIf you've already paid, ignore this message.";
                if (!empty($r['phone']) && class_exists('WhatsApp')) {
                    try { WhatsApp::sendMessage($r['phone'], $msg); } catch (Throwable $_) {}
                }
                if (!empty($r['admin_email'])) {
                    $subj = "Retry {$newAttempts}/{$laddermax}: your Cardify payment is pending";
                    $html = '<p>Hello ' . htmlspecialchars($r['company_name'] ?? '') . ',</p>'
                          . '<p>Your pending ' . htmlspecialchars($r['target_type']) . ' payment of <strong>'
                          . $amt . ' ' . htmlspecialchars($r['currency'] ?? 'OMR') . '</strong> is still outstanding.</p>'
                          . '<p><a href="' . htmlspecialchars($res['checkout_url'])
                          . '" style="display:inline-block;padding:10px 16px;background:#009bc1;color:#fff;border-radius:6px;text-decoration:none">Pay now</a></p>'
                          . '<p>If you have already paid, no action is needed.</p>';
                    try { Mailer::send($r['admin_email'], $subj, $html); } catch (Throwable $_) {}
                }
                $out[$exhausted ? 'exhausted' : 'succeeded_send']++;
            } catch (Throwable $e) {
                $out['errors']++;
                $db->exec(
                    "UPDATE payment_retries SET last_error = :e, last_attempt_at = NOW()
                      WHERE id = :id",
                    ['e' => mb_substr($e->getMessage(), 0, 2000), 'id' => $r['id']]
                );
                error_log('PaymentRetry::runQueue error on retry ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        return $out;
    }
}
