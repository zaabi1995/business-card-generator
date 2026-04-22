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

        $db->update(
            'otp_codes',
            ['consumed_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $row['id']]
        );
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
        $locale = function_exists('currentLocale') ? currentLocale() : 'en';
        $msg = $locale === 'ar'
            ? "رمز التحقق الخاص بك في كارديفاي: {$code}\nينتهي خلال 10 دقائق."
            : "Your Cardify verification code: {$code}\nExpires in 10 minutes.";
        return (bool) WhatsApp::sendMessage($phone, $msg);
    }

    private static function deliverEmail(string $email, string $code): bool
    {
        if (!class_exists('Mailer')) return false;
        $locale = function_exists('currentLocale') ? currentLocale() : 'en';
        $subject = $locale === 'ar' ? "رمز التحقق الخاص بك" : 'Your Cardify verification code';
        $dir  = $locale === 'ar' ? 'rtl' : 'ltr';
        $font = $locale === 'ar' ? "'IBM Plex Sans Arabic',system-ui,sans-serif" : 'system-ui,sans-serif';
        $body = $locale === 'ar'
            ? "<div dir=\"{$dir}\" style=\"font-family:{$font};max-width:560px;margin:0 auto;padding:24px;\">"
              . "<h1 style=\"color:#1e40af\">رمز التحقق</h1>"
              . "<p>رمزك لمرة واحدة:</p>"
              . "<div style=\"font-size:32px;font-weight:700;letter-spacing:4px;background:#f1f5f9;padding:16px;text-align:center;border-radius:8px;\" dir=\"ltr\">{$code}</div>"
              . "<p style=\"color:#6b7280;font-size:14px;\">ينتهي خلال 10 دقائق.</p>"
              . "</div>"
            : "<div dir=\"{$dir}\" style=\"font-family:{$font};max-width:560px;margin:0 auto;padding:24px;\">"
              . "<h1 style=\"color:#1e40af\">Your verification code</h1>"
              . "<p>Your one-time code:</p>"
              . "<div style=\"font-size:32px;font-weight:700;letter-spacing:4px;background:#f1f5f9;padding:16px;text-align:center;border-radius:8px;\">{$code}</div>"
              . "<p style=\"color:#6b7280;font-size:14px;\">Expires in 10 minutes.</p>"
              . "</div>";
        return (bool) Mailer::send($email, $subject, $body);
    }
}
