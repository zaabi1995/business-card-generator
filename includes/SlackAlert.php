<?php
/**
 * Lightweight Slack Incoming Webhook poster. Fail-open, silent on error.
 * Config: define SLACK_WEBHOOK_URL in config.php. If unset, every call is a no-op.
 *
 * Use for ops alerts (new tenant signup, failed payment, etc), NOT user-facing
 * notifications.
 */
class SlackAlert
{
    public static function isEnabled(): bool
    {
        return defined('SLACK_WEBHOOK_URL') && SLACK_WEBHOOK_URL !== '';
    }

    /**
     * Post a short plain-text alert to the configured webhook. Optional
     * $fields are rendered as a bullet list under the text for extra
     * context (key: value format).
     */
    public static function post(string $text, array $fields = []): bool
    {
        if (!self::isEnabled()) return false;

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
        ];
        if ($fields) {
            $lines = [];
            foreach ($fields as $k => $v) {
                if ($v === null || $v === '') continue;
                $lines[] = '*' . $k . ':* ' . $v;
            }
            if ($lines) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]];
            }
        }

        $body = json_encode(['text' => strip_tags($text), 'blocks' => $blocks]);

        $ch = curl_init(SLACK_WEBHOOK_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            error_log('[slack-alert] curl error: ' . $err);
            return false;
        }
        return trim((string) $out) === 'ok';
    }

    /**
     * Shortcut for new-tenant signup alerts.
     */
    public static function tenantSignup(string $companyName, string $adminEmail, ?string $adminPhone = null, ?string $source = null): void
    {
        if (!self::isEnabled()) return;
        self::post(':tada: New Cardify tenant', [
            'Company' => $companyName,
            'Admin'   => $adminEmail,
            'Phone'   => $adminPhone ?: '—',
            'Source'  => $source ?: 'web',
        ]);
    }
}
