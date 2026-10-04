<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/LogoLibrary.php';

final class LogoAccess
{
    public const GUEST_LIMIT = 5;
    public const MEMBER_LIMIT = 10;
    public const FORMATS = ['svg','png_512','png_1024','png_2048','webp','zip',
        'svg_dark','png_dark','webp_dark','svg_white','png_white','webp_white',
        'pdf','pdf_dark','pdf_white','ar_svg','ar_pdf','ar_webp','ar_png_2048',
        'ar_svg_dark','ar_pdf_dark','ar_png_dark','ar_webp_dark',
        'ar_svg_white','ar_pdf_white','ar_png_white','ar_webp_white',
        'int_svg','int_pdf','int_webp','int_png_2048','int_svg_dark','int_pdf_dark','int_png_dark','int_webp_dark',
        'int_svg_white','int_pdf_white','int_png_white','int_webp_white'];
    private static ?array $cachedMember = null;
    private static bool $memberResolved = false;

    public static function price(): float
    {
        return defined('LOGO_PASS_PRICE_OMR') ? (float) LOGO_PASS_PRICE_OMR : 2.000;
    }

    public static function days(): int
    {
        return defined('LOGO_PASS_DAYS') ? max(1, (int) LOGO_PASS_DAYS) : 30;
    }

    public static function period(): string
    {
        if (defined('LOGO_ACCESS_FREE_PERIOD') && LOGO_ACCESS_FREE_PERIOD === 'lifetime') return '1970-01-01';
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Muscat')))->format('Y-m-d');
    }

    public static function rewardedUnit(): string
    {
        $unit = defined('LOGO_REWARDED_AD_UNIT') ? (string) LOGO_REWARDED_AD_UNIT : '';
        return preg_match('~^/\d+/[a-zA-Z0-9_/-]+$~D', $unit) ? $unit : '';
    }

    public static function member(): ?array
    {
        if (self::$memberResolved) return self::$cachedMember;
        self::$memberResolved = true;
        $db = Database::getInstance();
        $token = $_COOKIE['cardify_logo_member'] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
            self::$cachedMember = $db->fetchOne(
                'SELECT m.* FROM logo_members m JOIN logo_member_sessions s ON s.member_id = m.id WHERE s.token_hash = :token AND s.expires_at > UTC_TIMESTAMP()',
                ['token' => hash('sha256', $token)]
            ) ?: null;
        }
        if (!self::$cachedMember && Auth::isLoggedIn()) {
            $user = Auth::getCurrentUser();
            $email = strtolower(trim((string) ($user['email'] ?? '')));
            // Only a proven email (code sign-in): a Cardify account's email is
            // unverified, and a paid pass belongs to the email (5 Oct 2026).
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 120
                && function_exists('cardifyIsVerified') && cardifyIsVerified($email)) {
                self::$cachedMember = $db->fetchOne('SELECT * FROM logo_members WHERE email = :email', ['email' => $email])
                    ?: ['id' => null, 'email' => $email, 'name' => $user['name'] ?? '', 'paid_until' => null];
            }
        }
        // One sign-in for cardify.om: someone signed in by code with no Cardify
        // account (an IQ test account with an email) is a member here too.
        if (!self::$cachedMember && !empty($_SESSION['iq_user_id'])) {
            $iq = $db->fetchOne('SELECT email, display_name FROM iq_users WHERE id = :id', ['id' => $_SESSION['iq_user_id']]);
            $email = strtolower(trim((string) ($iq['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 120) {
                self::$cachedMember = $db->fetchOne('SELECT * FROM logo_members WHERE email = :email', ['email' => $email])
                    ?: ['id' => null, 'email' => $email, 'name' => (string) ($iq['display_name'] ?? ''), 'paid_until' => null];
            }
        }
        return self::$cachedMember;
    }

    public static function ensureMember(string $email, string $name = ''): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) throw new InvalidArgumentException('invalid_email');
        $db = Database::getInstance();
        $db->getConnection()->prepare('INSERT IGNORE INTO logo_members (id, email, name) VALUES (:id, :email, :name)')
            ->execute(['id' => generateUUID(), 'email' => $email, 'name' => mb_substr(trim($name), 0, 120)]);
        return $db->fetchOne('SELECT * FROM logo_members WHERE email = :email', ['email' => $email]);
    }

    public static function signIn(array $member): void
    {
        session_regenerate_id(true);
        $token = bin2hex(random_bytes(32));
        Database::getInstance()->getConnection()->prepare('INSERT INTO logo_member_sessions (token_hash, member_id, expires_at) VALUES (:hash, :id, :expiry)')
            ->execute(['hash' => hash('sha256', $token), 'id' => $member['id'], 'expiry' => gmdate('Y-m-d H:i:s', time() + 30 * 86400)]);
        setcookie('cardify_logo_member', $token, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['cardify_logo_member'] = $token;
        self::$cachedMember = $member;
        self::$memberResolved = true;
        // Carry guest selections into the account, so signup increases the allowance to ten.
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $subject = self::subject(); $guest = self::guestSubject(); $period = self::period();
            $pdo->prepare('INSERT INTO logo_access_days (subject, period) VALUES (?, ?) ON DUPLICATE KEY UPDATE used = used')->execute([$subject, $period]);
            $pdo->prepare('SELECT used FROM logo_access_days WHERE subject = ? AND period = ? FOR UPDATE')->execute([$subject, $period]);
            $stmt = $pdo->prepare("INSERT IGNORE INTO logo_access_grants (subject, period, company_id, kind) SELECT ?, period, company_id, 'free' FROM logo_access_grants WHERE subject = ? AND period = ?");
            $stmt->execute([$subject, $guest, $period]);
            $pdo->prepare('UPDATE logo_access_days SET used = used + ? WHERE subject = ? AND period = ?')->execute([$stmt->rowCount(), $subject, $period]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function signOut(): void
    {
        $token = $_COOKIE['cardify_logo_member'] ?? '';
        Database::getInstance()->getConnection()->prepare('DELETE FROM logo_member_sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        setcookie('cardify_logo_member', '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE['cardify_logo_member']);
        self::$cachedMember = null; self::$memberResolved = false;
    }

    private static function guestSubject(): string { return 'g:' . LogoLibrary::ipHash(); }
    public static function subject(): string
    {
        $member = self::member();
        return $member ? 'm:' . hash('sha256', $member['email']) : self::guestSubject();
    }

    public static function paid(): bool
    {
        $expiry = self::member()['paid_until'] ?? null;
        return $expiry && strtotime($expiry . ' UTC') > time();
    }

    public static function state(): array
    {
        $member = self::member();
        $row = Database::getInstance()->fetchOne('SELECT used, bonus FROM logo_access_days WHERE subject = :s AND period = :p', ['s' => self::subject(), 'p' => self::period()]);
        $limit = $member ? self::MEMBER_LIMIT : self::GUEST_LIMIT;
        $used = (int) ($row['used'] ?? 0); $bonus = (int) ($row['bonus'] ?? 0);
        return ['registered' => (bool) $member, 'email' => $member['email'] ?? '', 'name' => $member['name'] ?? '', 'phone' => $member['phone'] ?? '',
            'canSignOut' => (bool)$member, 'paid' => self::paid(),
            'paidUntil' => $member['paid_until'] ?? null, 'used' => $used, 'limit' => $limit,
            'remaining' => max(0, $limit + $bonus - $used), 'period' => self::period(),
            'daily' => self::period() !== '1970-01-01', 'price' => number_format(self::price(), 3, '.', ''),
            'days' => self::days(), 'rewardedUnit' => $member && $bonus < 3 && !self::paid() ? self::rewardedUnit() : ''];
    }

    public static function asset(array $company, string $format): ?string
    {
        $path = LogoLibrary::downloadPaths($company)[$format] ?? null;
        $root = realpath(dirname(__DIR__) . '/storage/logos');
        if (!$root || !is_string($path) || !str_starts_with($path, '/storage/logos/')) return null;
        $file = realpath(dirname(__DIR__) . $path);
        return $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    public static function issueTicket(int $companyId, string $format): ?string
    {
        $pdo = Database::getInstance()->getConnection(); $subject = self::subject(); $period = self::period();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO logo_access_days (subject, period) VALUES (?, ?) ON DUPLICATE KEY UPDATE used = used')->execute([$subject, $period]);
            $stmt = $pdo->prepare('SELECT used, bonus FROM logo_access_days WHERE subject = ? AND period = ? FOR UPDATE');
            $stmt->execute([$subject, $period]); $counter = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('SELECT kind FROM logo_access_grants WHERE subject = ? AND period = ? AND company_id = ?');
            $stmt->execute([$subject, $period, $companyId]); $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $paid = self::paid();
            if (!$existing && !$paid && (int)$counter['used'] >= (self::member() ? self::MEMBER_LIMIT : self::GUEST_LIMIT) + (int)$counter['bonus']) {
                $pdo->rollBack(); return null;
            }
            if (!$existing) {
                $pdo->prepare('INSERT INTO logo_access_grants (subject, period, company_id, kind) VALUES (?, ?, ?, ?)')->execute([$subject, $period, $companyId, $paid ? 'paid' : 'free']);
                if (!$paid) $pdo->prepare('UPDATE logo_access_days SET used = used + 1 WHERE subject = ? AND period = ?')->execute([$subject, $period]);
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO logo_download_tickets (token_hash, subject, company_id, format, expires_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([hash('sha256', $token), $subject, $companyId, $format, gmdate('Y-m-d H:i:s', time() + 300)]);
            $pdo->commit(); return $token;
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function consumeTicket(string $token, int $companyId, string $format): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $stmt = Database::getInstance()->getConnection()->prepare('UPDATE logo_download_tickets SET consumed_at = UTC_TIMESTAMP() WHERE token_hash = ? AND subject = ? AND company_id = ? AND format = ? AND expires_at > UTC_TIMESTAMP() AND consumed_at IS NULL');
        $stmt->execute([hash('sha256', $token), self::subject(), $companyId, $format]);
        return $stmt->rowCount() === 1;
    }

    public static function reward(string $challenge): bool
    {
        $attempt = $_SESSION['logo_reward_attempt'] ?? null;
        unset($_SESSION['logo_reward_attempt']);
        if (!self::member() || self::rewardedUnit() === '' || !$attempt || !hash_equals($attempt['token'], $challenge)
            || $attempt['subject'] !== self::subject() || $attempt['expires'] < time()) return false;
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('UPDATE logo_access_days SET bonus = bonus + 1 WHERE subject = ? AND period = ? AND bonus < 3');
        $stmt->execute([self::subject(), self::period()]);
        return $stmt->rowCount() === 1;
    }

    public static function callback(array $payment, array $data): array
    {
        $base = ['type' => 'logo_pass', 'reference_id' => $payment['reference_id'], 'payment_id' => $payment['id']];
        // The gateway order ID is bound when the intention is created. References are not signed.
        if (empty($payment['paymob_order_id']) || (string)($data['order'] ?? '') !== (string)$payment['paymob_order_id']
            || (int)($data['amount_cents'] ?? -1) !== (int)round((float)$payment['amount'] * 1000)
            || ($data['currency'] ?? '') !== 'OMR' || empty($data['id'])) return $base + ['success' => false, 'error' => 'Payment mismatch'];
        $truth = static fn($value) => $value === true || $value === 'true';
        $pending = $truth($data['pending'] ?? false) || $truth($data['is_auth'] ?? false);
        $refunded = $truth($data['is_refunded'] ?? false) || $truth($data['is_voided'] ?? false);
        $success = $truth($data['success'] ?? false) && !$pending && !$refunded;
        $pdo = Database::getInstance()->getConnection(); $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM logo_pass_orders WHERE id = ? AND payment_id = ? FOR UPDATE');
            $stmt->execute([$payment['reference_id'], $payment['id']]); $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || (float)$order['amount'] !== (float)$payment['amount']) throw new RuntimeException('Order mismatch');
            if ($refunded) {
                $pdo->prepare("UPDATE logo_pass_orders SET status = 'refunded' WHERE id = ?")->execute([$order['id']]);
                $pdo->prepare("UPDATE payments SET status = 'refunded', callback_data = ? WHERE id = ?")->execute([json_encode($data), $payment['id']]);
                $pdo->prepare('UPDATE logo_members SET paid_until = UTC_TIMESTAMP() WHERE id = ? AND last_payment_id = ?')->execute([$order['member_id'], $payment['id']]);
            } elseif ($order['status'] === 'paid') {
                $pdo->commit(); return $base + ['success' => true, 'status' => 'paid', 'idempotent' => true];
            } elseif ($success && $order['status'] !== 'refunded') {
                $stmt = $pdo->prepare('SELECT paid_until FROM logo_members WHERE id = ? FOR UPDATE');
                $stmt->execute([$order['member_id']]); $member = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$member) throw new RuntimeException('Member missing');
                $expiry = max(time(), strtotime(($member['paid_until'] ?? '1970-01-01') . ' UTC')) + (int)$order['days'] * 86400;
                $pdo->prepare('UPDATE logo_members SET paid_until = ?, last_payment_id = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $expiry), $payment['id'], $order['member_id']]);
                $pdo->prepare("UPDATE logo_pass_orders SET status = 'paid', transaction_id = ? WHERE id = ?")->execute([(string)$data['id'], $order['id']]);
                $pdo->prepare("UPDATE payments SET status = 'paid', paymob_transaction_id = ?, payment_method = ?, callback_data = ? WHERE id = ?")
                    ->execute([(string)$data['id'], $data['source_data_type'] ?? null, json_encode($data), $payment['id']]);
            } else {
                $pdo->prepare('UPDATE logo_pass_orders SET status = ? WHERE id = ? AND status <> ?')->execute([$pending ? 'pending' : 'failed', $order['id'], 'refunded']);
                $pdo->prepare('UPDATE payments SET status = ?, callback_data = ? WHERE id = ? AND status <> ?')->execute([$pending ? 'pending' : 'failed', json_encode($data), $payment['id'], 'refunded']);
            }
            $pdo->commit();
            self::$memberResolved = false; self::$cachedMember = null;
            return $base + ['success' => true, 'status' => $refunded ? 'refunded' : ($pending ? 'pending' : ($success && $order['status'] !== 'refunded' ? 'paid' : 'failed'))];
        } catch (Throwable $e) { $pdo->rollBack(); error_log('Logo pass callback: ' . $e->getMessage()); return $base + ['success' => false, 'error' => 'Payment could not be confirmed']; }
    }
}
