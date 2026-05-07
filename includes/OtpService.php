<?php
/**
 * OtpService, 6-digit one-time-code issue + verify.
 *
 * Storage: otp_codes table (migration 078). Codes are hashed SHA-256
 * at rest; plain 6 digits only live in the WhatsApp/email message.
 *
 * Delivery:
 *   - whatsapp: includes/WhatsApp.php sendMessage()
 *   - email:    includes/Mailer.php send()
 *
 * Throttling:
 *   - 3 OTP sends per hour per identifier (phone/email) via RateLimiter
 *   - 10 OTP sends per day per IP via RateLimiter
 *
 * TTL: 10 minutes.
 * Max attempts: 5 per code before it's invalidated.
 *
 * Usage:
 *   $res = OtpService::send('+96871234567', 'whatsapp', 'signup');
 *   if (!$res['ok']) show error $res['error'];
 *   // ... user enters the code
 *   $ok  = OtpService::verify('+96871234567', $enteredCode, 'signup');
 */
class OtpService
{
    public const TTL_SECONDS = 600; // 10 minutes
    public const MAX_ATTEMPTS = 5;
    public const CODE_LENGTH = 6;

    public const RATE_PER_IDENTIFIER = 3;
    public const RATE_PER_IDENTIFIER_WINDOW = 3600; // 1 hour
    public const RATE_PER_IP = 10;
    public const RATE_PER_IP_WINDOW = 86400; // 1 day

    /**
     * Generate + persist + deliver an OTP. Returns ['ok'=>bool, 'error'=>string].
     */
    public static function send(string $identifier, string $channel, string $purpose = 'signup'): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') return ['ok' => false, 'error' => 'missing identifier'];
        if (!in_array($channel, ['whatsapp','email'], true)) return ['ok' => false, 'error' => 'invalid channel'];

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit per identifier
        if (class_exists('RateLimiter')) {
            if (!RateLimiter::check('otp_send_ident:' . $identifier, $ip, self::RATE_PER_IDENTIFIER, self::RATE_PER_IDENTIFIER_WINDOW)) {
                return ['ok' => false, 'error' => 'rate_limited_identifier'];
            }
            if (!RateLimiter::check('otp_send_ip', $ip, self::RATE_PER_IP, self::RATE_PER_IP_WINDOW)) {
                return ['ok' => false, 'error' => 'rate_limited_ip'];
            }
        }

        $code = self::generateCode();
        $hash = hash('sha256', $code);

        $db = Database::getInstance();
        $db->insert('otp_codes', [
            'identifier' => $identifier,
            'channel'    => $channel,
            'code_hash'  => $hash,
            'purpose'    => $purpose,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            'ip'         => substr($ip, 0, 45),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        $ok = $channel === 'whatsapp'
            ? self::deliverWhatsApp($identifier, $code)
            : self::deliverEmail($identifier, $code);

        if (!$ok) return ['ok' => false, 'error' => 'delivery_failed'];
        return ['ok' => true];
    }

    /**
     * Verify a code for the given identifier + purpose. Burns the code
     * on success. Increments `attempts` on mismatch and invalidates the
     * code after MAX_ATTEMPTS.
     */
    public static function verify(string $identifier, string $code, string $purpose = 'signup'): array
    {
        $identifier = trim($identifier);
        $code = trim($code);
        if (!preg_match('/^\d{' . self::CODE_LENGTH . '}$/', $code)) {
            return ['ok' => false, 'error' => 'invalid_code_format'];
        }

        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM otp_codes
             WHERE identifier = :id AND purpose = :p AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY sent_at DESC LIMIT 1",
            ['id' => $identifier, 'p' => $purpose]
        );

        if (!$row) return ['ok' => false, 'error' => 'expired_or_missing'];
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        $expected = $row['code_hash'];
        $supplied = hash('sha256', $code);
        if (!hash_equals($expected, $supplied)) {
            $db->update(
                'otp_codes',
                ['attempts' => ((int)$row['attempts']) + 1],
                'id = :id',
                ['id' => $row['id']]
            );
            return ['ok' => false, 'error' => 'wrong_code'];
        }

        // Atomically consume: if a concurrent verify beat us to it, the WHERE
        // consumed_at IS NULL clause matches 0 rows and we reject. Without
        // this guard, two parallel verify calls (e.g. attacker + legitimate
        // user racing the same observed code) could both succeed.
        $conn = $db->getConnection();
        $stmt = $conn->prepare(
            "UPDATE otp_codes SET consumed_at = NOW()
             WHERE id = :id AND consumed_at IS NULL"
        );
        $stmt->execute([':id' => $row['id']]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'already_consumed'];
        }
        return ['ok' => true];
    }

    private static function generateCode(): string
    {
        // Cryptographic RNG to avoid predictable sequences.
        $max = (int) str_repeat('9', self::CODE_LENGTH);
        $n = random_int(0, $max);
        return str_pad((string)$n, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private static function deliverWhatsApp(string $phone, string $code): bool
    {
        if (!class_exists('WhatsApp') || !WhatsApp::isEnabled()) return false;
        $body = self::renderTemplate('otp.whatsapp', $code);
        // OTPs are transactional, bypass Dardasha's anti-ban throttle so the
        // user gets the code in ~500ms instead of 5-10s. EN auto-bypasses
        // via keyword detection on Dardasha; AR text doesn't, hence explicit.
        $res = WhatsApp::sendMessage($phone, $body, [
            'bypassAntiBan' => true,
            'transactional' => true,
            'priority'      => 'high',
        ]);
        return is_array($res) ? !empty($res['success']) : (bool) $res;
    }

    private static function deliverEmail(string $email, string $code): bool
    {
        if (!class_exists('Mailer')) return false;
        [$subject, $body] = self::renderEmailTemplate('otp.email', $code);
        return (bool) Mailer::send($email, $subject, $body);
    }

    /**
     * Render a WhatsApp template (templates/{name}.{locale}.php) and return its
     * $body. Falls back to English if the Arabic template is missing.
     */
    private static function renderTemplate(string $name, string $code): string
    {
        $locale = function_exists('currentLocale') ? currentLocale() : 'en';
        $expiresInMinutes = (int) (self::TTL_SECONDS / 60);
        $path = __DIR__ . "/notifications/templates/{$name}.{$locale}.php";
        if (!is_file($path)) {
            $path = __DIR__ . "/notifications/templates/{$name}.en.php";
        }
        $body = '';
        require $path;
        return $body;
    }

    /**
     * Render an email template, returning [$subject, $body].
     */
    private static function renderEmailTemplate(string $name, string $code): array
    {
        $locale = function_exists('currentLocale') ? currentLocale() : 'en';
        $expiresInMinutes = (int) (self::TTL_SECONDS / 60);
        $path = __DIR__ . "/notifications/templates/{$name}.{$locale}.php";
        if (!is_file($path)) {
            $path = __DIR__ . "/notifications/templates/{$name}.en.php";
        }
        $subject = '';
        $body = '';
        require $path;
        return [$subject, $body];
    }
}
