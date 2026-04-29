<?php
/**
 * Invisible reCAPTCHA v3 helper.
 *
 * Configuration via constants in config.php (both optional):
 *   RECAPTCHA_SITE_KEY  , public site key rendered in <script>
 *   RECAPTCHA_SECRET    , server-side secret for siteverify
 *
 * Behaviour:
 *   - Fails OPEN when either constant is missing/empty so local dev
 *     and first-deploy environments keep working.
 *   - Writes every verify attempt to AuditLog with the returned score
 *     (action `recaptcha_score`). Audit query can then flag sub-0.5
 *     sign-ups even though we do not hard-block them yet, giving ops
 *     a dial they can tighten after a week of baseline data.
 */
class Recaptcha
{
    public const DEFAULT_THRESHOLD = 0.5;
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public static function isConfigured(): bool
    {
        return defined('RECAPTCHA_SITE_KEY') && defined('RECAPTCHA_SECRET')
            && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SECRET !== '';
    }

    public static function siteKey(): ?string
    {
        return self::isConfigured() ? RECAPTCHA_SITE_KEY : null;
    }

    /**
     * Verify a token submitted from the frontend. Returns:
     *   ['ok'=>true, 'score'=>float, 'fail_open'=>false]   on pass
     *   ['ok'=>false, 'score'=>float, 'error'=>string]     on reject
     *   ['ok'=>true, 'fail_open'=>true]                    when no secret configured
     *
     * @param string      $token     g-recaptcha-response token
     * @param string      $action    Expected action name (e.g. 'signup')
     * @param float       $threshold Score must be >= this to pass
     * @param string|null $companyId For audit log context
     */
    public static function verify(string $token, string $action, float $threshold = self::DEFAULT_THRESHOLD, ?string $companyId = null): array
    {
        if (!self::isConfigured()) {
            return ['ok' => true, 'fail_open' => true];
        }
        if ($token === '') {
            self::audit($action, 0.0, 'missing_token', $companyId);
            return ['ok' => false, 'score' => 0.0, 'error' => 'missing_token'];
        }

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => RECAPTCHA_SECRET,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            error_log('[recaptcha] curl error: ' . $err);
            self::audit($action, 0.0, 'network_error', $companyId);
            // Network-error → fail open so we do not lock out legit signups.
            return ['ok' => true, 'fail_open' => true];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            self::audit($action, 0.0, 'bad_response', $companyId);
            return ['ok' => true, 'fail_open' => true];
        }

        $score   = (float) ($data['score']   ?? 0.0);
        $remote  = (string) ($data['action'] ?? '');
        $success = !empty($data['success']);

        if (!$success) {
            self::audit($action, $score, 'not_success', $companyId);
            return ['ok' => false, 'score' => $score, 'error' => 'not_success'];
        }
        if ($remote !== '' && $remote !== $action) {
            self::audit($action, $score, 'action_mismatch:' . $remote, $companyId);
            return ['ok' => false, 'score' => $score, 'error' => 'action_mismatch'];
        }
        if ($score < $threshold) {
            self::audit($action, $score, 'below_threshold', $companyId);
            return ['ok' => false, 'score' => $score, 'error' => 'below_threshold'];
        }

        self::audit($action, $score, 'pass', $companyId);
        return ['ok' => true, 'score' => $score, 'fail_open' => false];
    }

    private static function audit(string $action, float $score, string $outcome, ?string $companyId): void
    {
        if (!class_exists('AuditLog')) return;
        try {
            AuditLog::log(
                'recaptcha_score',
                'recaptcha',
                null,
                null,
                ['action' => $action, 'score' => $score, 'outcome' => $outcome],
                $companyId
            );
        } catch (Throwable $e) { /* non-fatal */ }
    }
}
