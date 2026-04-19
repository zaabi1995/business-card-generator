<?php
/**
 * Referral loop (BHD-234).
 *
 * Every Cardify user gets a unique `referral_code`. Share link:
 *   https://<host>/r/<code>  (sets cookie + session, redirects to signup)
 * On signup the new user is attributed to the referrer. When the new user's
 * company upgrades to a paid plan (amount > 0), the referrer's company
 * subscription is extended by 3 months and the referrer is WhatsApp-pinged
 * via Dardasha.
 *
 * Rewards are one-shot per referred_company — we never double-grant.
 */
class Referral {
    const REWARD_MONTHS = 3;
    const COOKIE_NAME = 'cardify_ref';
    const COOKIE_TTL_DAYS = 30;

    /** @var PDO|null */
    private static $pdo = null;

    private static function pdo() {
        if (self::$pdo === null) {
            self::$pdo = Database::getInstance()->getConnection();
        }
        return self::$pdo;
    }

    /**
     * Generate an 8-char unambiguous code (no 0/O/1/I).
     */
    public static function generateCode(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Ensure a user has a referral_code; return it. Idempotent.
     */
    public static function ensureCodeForUser(string $userId): ?string {
        try {
            $row = self::pdo()->prepare("SELECT referral_code FROM users WHERE id = :id");
            $row->execute([':id' => $userId]);
            $code = $row->fetchColumn();
            if ($code) return (string)$code;
        } catch (Throwable $e) {
            return null;
        }

        // Generate + retry on UNIQUE collision.
        $upd = self::pdo()->prepare("UPDATE users SET referral_code = :code WHERE id = :id AND (referral_code IS NULL OR referral_code = '')");
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $code = self::generateCode();
            try {
                $upd->execute([':code' => $code, ':id' => $userId]);
                if ($upd->rowCount() > 0) return $code;
                // Row already had a code written by a parallel request.
                $r = self::pdo()->prepare("SELECT referral_code FROM users WHERE id = :id");
                $r->execute([':id' => $userId]);
                $existing = $r->fetchColumn();
                if ($existing) return (string)$existing;
            } catch (Throwable $e) {
                // Unique collision — loop.
            }
        }
        return null;
    }

    /**
     * Look up user id by referral_code (case-insensitive match on upper).
     */
    public static function userIdByCode(string $code): ?string {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($code === '') return null;
        try {
            $r = self::pdo()->prepare("SELECT id FROM users WHERE UPPER(referral_code) = :c LIMIT 1");
            $r->execute([':c' => $code]);
            $id = $r->fetchColumn();
            return $id ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Build the share URL for a given code.
     */
    public static function shareUrl(string $code): string {
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
        return $scheme . '://' . $host . '/r/' . $code;
    }

    /**
     * Capture the pending referral from the current request — sets cookie
     * and session. Does NOT persist to DB (that happens at signup).
     */
    public static function capturePending(string $code): void {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($clean === '') return;
        $_SESSION['pending_ref_code'] = $clean;
        // Set cookie across sessions (30 days).
        $ttl = time() + (86400 * self::COOKIE_TTL_DAYS);
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on');
        @setcookie(self::COOKIE_NAME, $clean, [
            'expires'  => $ttl,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false, // readable by JS is fine — it's not sensitive
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Read the pending referral from session / cookie if present.
     */
    public static function readPending(): ?string {
        $code = $_SESSION['pending_ref_code'] ?? $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$code) return null;
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
        return $clean !== '' ? $clean : null;
    }

    /**
     * Clear the pending referral (after successful attribution).
     */
    public static function clearPending(): void {
        unset($_SESSION['pending_ref_code']);
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on');
        @setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Attribute a new user to a referrer. No-op on self-reference or
     * when the new user is already attributed.
     */
    public static function attributeSignup(string $newUserId, string $code): bool {
        $referrerId = self::userIdByCode($code);
        if (!$referrerId || $referrerId === $newUserId) return false;
        try {
            // Only set if not already attributed.
            $upd = self::pdo()->prepare(
                "UPDATE users SET referred_by_user_id = :ref, referred_at = NOW() WHERE id = :id AND (referred_by_user_id IS NULL OR referred_by_user_id = '')"
            );
            $upd->execute([':ref' => $referrerId, ':id' => $newUserId]);
            if ($upd->rowCount() === 0) return false;

            $companyId = null;
            try {
                $r = self::pdo()->prepare("SELECT company_id FROM users WHERE id = :id");
                $r->execute([':id' => $newUserId]);
                $companyId = $r->fetchColumn() ?: null;
            } catch (Throwable $e) {}

            self::logEvent($referrerId, $newUserId, $companyId, 'signup');
            return true;
        } catch (Throwable $e) {
            error_log('[referral] attributeSignup failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Called on paid subscription activation (amount > 0). If the company's
     * admin was referred, extend the referrer's company by REWARD_MONTHS and
     * ping the referrer on WhatsApp. Idempotent per company.
     */
    public static function onPaidConversion(string $referredCompanyId, float $amount = 0.0): void {
        if ($amount <= 0) return; // Free-tier activations don't trigger rewards.
        try {
            $pdo = self::pdo();

            // Find the admin user who was attributed.
            $stmt = $pdo->prepare("
                SELECT u.id AS referred_user_id, u.referred_by_user_id, u.name AS referred_name
                FROM users u
                WHERE u.company_id = :cid
                  AND u.role = 'company'
                  AND u.referred_by_user_id IS NOT NULL
                ORDER BY u.created_at ASC
                LIMIT 1
            ");
            $stmt->execute([':cid' => $referredCompanyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['referred_by_user_id'])) return;

            $referrerId = $row['referred_by_user_id'];
            $referredUserId = $row['referred_user_id'];
            $referredName = $row['referred_name'] ?? 'Your friend';

            // Idempotency: already rewarded for this referred company?
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM referral_events
                WHERE referrer_user_id = :r AND referred_company_id = :c AND event_type = 'reward_granted'
            ");
            $chk->execute([':r' => $referrerId, ':c' => $referredCompanyId]);
            if ((int)$chk->fetchColumn() > 0) return;

            // Log paid_conversion (one per company).
            $pchk = $pdo->prepare("
                SELECT COUNT(*) FROM referral_events
                WHERE referrer_user_id = :r AND referred_company_id = :c AND event_type = 'paid_conversion'
            ");
            $pchk->execute([':r' => $referrerId, ':c' => $referredCompanyId]);
            if ((int)$pchk->fetchColumn() === 0) {
                self::logEvent($referrerId, $referredUserId, $referredCompanyId, 'paid_conversion',
                    ['amount' => $amount]);
            }

            // Find referrer company + extend its subscription.
            $refCompanyRow = $pdo->prepare("
                SELECT c.id, c.name, c.subscription_status, c.subscription_expires_at, c.plan
                FROM users u
                LEFT JOIN companies c ON c.id = u.company_id
                WHERE u.id = :id
                LIMIT 1
            ");
            $refCompanyRow->execute([':id' => $referrerId]);
            $refCompany = $refCompanyRow->fetch(PDO::FETCH_ASSOC);
            if (!$refCompany || empty($refCompany['id'])) {
                error_log("[referral] referrer $referrerId has no company — skipping reward");
                return;
            }

            // Extend from later of (now, current expiry) by 3 months.
            $now = time();
            $base = $now;
            if (!empty($refCompany['subscription_expires_at'])) {
                $cur = strtotime($refCompany['subscription_expires_at']);
                if ($cur > $now) $base = $cur;
            }
            $newExpiry = date('Y-m-d H:i:s', strtotime('+' . self::REWARD_MONTHS . ' months', $base));

            // If referrer was on free, bump them to paid tier 'starter' (or keep
            // current non-free plan). Fall back to first paid plan if none.
            $newPlan = $refCompany['plan'] ?? 'free';
            if ($newPlan === 'free' || $newPlan === '') {
                try {
                    $firstPaid = $pdo->query("
                        SELECT id FROM subscription_plans
                        WHERE (price_monthly > 0 OR price_yearly > 0) AND is_active = 1
                        ORDER BY price_monthly ASC LIMIT 1
                    ")->fetchColumn();
                    if ($firstPaid) $newPlan = $firstPaid;
                } catch (Throwable $e) {
                    // subscription_plans/is_active may not exist — leave plan as-is.
                }
            }

            $db = Database::getInstance();
            $db->update('companies', [
                'plan' => $newPlan,
                'subscription_status' => 'active',
                'subscription_expires_at' => $newExpiry,
            ], 'id = :id', ['id' => $refCompany['id']]);

            self::logEvent($referrerId, $referredUserId, $referredCompanyId, 'reward_granted', [
                'months' => self::REWARD_MONTHS,
                'new_expiry' => $newExpiry,
                'referrer_company_id' => $refCompany['id'],
            ]);

            // Best-effort WhatsApp ping to the referrer.
            self::sendReferrerReward($referrerId, $referredName, $newExpiry);
        } catch (Throwable $e) {
            error_log('[referral] onPaidConversion failed: ' . $e->getMessage());
        }
    }

    private static function sendReferrerReward(string $referrerId, string $referredName, string $newExpiry): void {
        try {
            $r = self::pdo()->prepare("SELECT phone, name, email FROM users WHERE id = :id");
            $r->execute([':id' => $referrerId]);
            $user = $r->fetch(PDO::FETCH_ASSOC);
            if (!$user) return;
            $phone = trim($user['phone'] ?? '');
            if ($phone === '') {
                // Try the company phone as fallback.
                try {
                    $c = self::pdo()->prepare("SELECT c.phone FROM companies c JOIN users u ON u.company_id = c.id WHERE u.id = :id");
                    $c->execute([':id' => $referrerId]);
                    $phone = trim($c->fetchColumn() ?: '');
                } catch (Throwable $e) {}
            }
            if ($phone === '') return;

            require_once INCLUDES_DIR . '/WhatsApp.php';
            if (!WhatsApp::isEnabled()) return;

            $name = trim($user['name'] ?? '');
            $firstName = $name !== '' ? explode(' ', $name)[0] : 'there';
            $expiryDate = date('M j, Y', strtotime($newExpiry));

            $msg  = "🎉 مرحباً " . $firstName . "!\n\n";
            $msg .= "صديقك " . $referredName . " انضم للتو إلى Cardify — لقد حصلت على 3 أشهر مجانية!\n";
            $msg .= "اشتراكك الآن ممتد حتى " . $expiryDate . "\n\n";
            $msg .= "————————————\n\n";
            $msg .= "Hi " . $firstName . "!\n\n";
            $msg .= "Your friend " . $referredName . " just joined Cardify — you got 3 months free!\n";
            $msg .= "Your subscription is now extended to " . $expiryDate . ".\n\n";
            $msg .= "Keep sharing: https://cardify.bhd.om";

            WhatsApp::sendMessage($phone, $msg);
        } catch (Throwable $e) {
            error_log('[referral] sendReferrerReward failed: ' . $e->getMessage());
        }
    }

    /**
     * Stats for a given user — signups + paid conversions they referred.
     * Used on the dashboard share card.
     */
    public static function statsForUser(string $userId): array {
        $out = ['signups' => 0, 'paid' => 0];
        try {
            $r = self::pdo()->prepare("SELECT COUNT(*) FROM users WHERE referred_by_user_id = :id");
            $r->execute([':id' => $userId]);
            $out['signups'] = (int)$r->fetchColumn();

            $r = self::pdo()->prepare("
                SELECT COUNT(DISTINCT referred_company_id) FROM referral_events
                WHERE referrer_user_id = :id AND event_type = 'reward_granted'
            ");
            $r->execute([':id' => $userId]);
            $out['paid'] = (int)$r->fetchColumn();
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Aggregated admin view — returns rows [{referrer_name, email, phone,
     * referral_code, signups, paid_conversions, last_activity}].
     */
    public static function adminStats(int $limit = 200): array {
        try {
            $sql = "
                SELECT
                    u.id AS referrer_id,
                    u.name,
                    u.email,
                    u.phone,
                    u.referral_code,
                    (SELECT COUNT(*) FROM users r WHERE r.referred_by_user_id = u.id) AS signups,
                    (SELECT COUNT(DISTINCT re.referred_company_id) FROM referral_events re
                      WHERE re.referrer_user_id = u.id AND re.event_type = 'reward_granted') AS paid_conversions,
                    (SELECT MAX(re.created_at) FROM referral_events re WHERE re.referrer_user_id = u.id) AS last_activity
                FROM users u
                WHERE u.referral_code IS NOT NULL
                HAVING signups > 0 OR paid_conversions > 0
                ORDER BY paid_conversions DESC, signups DESC, last_activity DESC
                LIMIT :lim
            ";
            $stmt = self::pdo()->prepare($sql);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[referral] adminStats failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function logEvent(string $referrerId, ?string $referredUserId, ?string $referredCompanyId, string $type, array $details = []): void {
        try {
            $id = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $stmt = self::pdo()->prepare("
                INSERT INTO referral_events (id, referrer_user_id, referred_user_id, referred_company_id, event_type, details, created_at)
                VALUES (:id, :ref, :ru, :rc, :t, :d, NOW())
            ");
            $stmt->execute([
                ':id'  => $id,
                ':ref' => $referrerId,
                ':ru'  => $referredUserId,
                ':rc'  => $referredCompanyId,
                ':t'   => $type,
                ':d'   => $details ? json_encode($details) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[referral] logEvent failed: ' . $e->getMessage());
        }
    }
}
