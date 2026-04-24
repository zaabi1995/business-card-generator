<?php
/**
 * Notifier, unified confirmation dispatcher across email + Dardasha WhatsApp.
 *
 * Every confirmation event in Cardify goes through Notifier::send(). It renders
 * templates, dispatches to Mailer (email) and WhatsApp (Dardasha), logs the
 * attempt + result to notification_log, and returns per-channel success flags.
 *
 * Failures on one channel do NOT block the other, graceful degradation is
 * the design. Caller can re-dispatch individual channels later if needed.
 *
 * Templates live in includes/notifications/templates/<event>.<channel>.<lang>.php
 * and receive the $context array in scope. The template file must set a
 * $subject (email only) and $body variable before ending.
 */
class Notifier {

    const DEFAULT_CHANNELS = ['email', 'whatsapp'];

    /**
     * Send a confirmation through one or more channels.
     *
     * @param string $event Event key (e.g. 'signup', 'print_order_placed')
     * @param array  $recipient ['name'=>, 'email'=>, 'phone'=>, 'company_id'=>]
     * @param array  $context Event-specific template variables
     * @param array  $channels Which channels to attempt, default both
     * @return array ['email' => bool, 'whatsapp' => bool]
     */
    public static function send(
        string $event,
        array  $recipient,
        array  $context = [],
        array  $channels = self::DEFAULT_CHANNELS
    ): array {
        require_once __DIR__ . '/Mailer.php';
        require_once __DIR__ . '/WhatsApp.php';

        $results  = ['email' => false, 'whatsapp' => false];
        $errors   = [];
        $language = self::detectLanguage($recipient['name'] ?? '');

        foreach ($channels as $channel) {
            try {
                if ($channel === 'email' && !empty($recipient['email'])) {
                    $tpl = self::renderTemplate($event, 'email', $language, $context);
                    if ($tpl !== null) {
                        $ok = Mailer::send(
                            $recipient['email'],
                            $tpl['subject'] ?? 'Cardify notification',
                            $tpl['body'] ?? '',
                            $tpl['attachments'] ?? []
                        );
                        $results['email'] = (bool)$ok;
                        if (!$ok) {
                            $errors['email'] = 'Mailer::send returned false';
                        }
                    } else {
                        $errors['email'] = "template not found: {$event}.email.{$language}";
                    }
                } elseif ($channel === 'whatsapp' && !empty($recipient['phone'])) {
                    $tpl = self::renderTemplate($event, 'whatsapp', $language, $context);
                    if ($tpl !== null) {
                        $res = WhatsApp::sendMessage($recipient['phone'], $tpl['body'] ?? '');
                        $results['whatsapp'] = (bool)($res['success'] ?? false);
                        if (!$results['whatsapp']) {
                            $errors['whatsapp'] = $res['error'] ?? 'unknown';
                        }
                    } else {
                        $errors['whatsapp'] = "template not found: {$event}.whatsapp.{$language}";
                    }
                }
            } catch (Throwable $e) {
                $errors[$channel] = $e->getMessage();
                error_log("[Notifier] {$event}/{$channel} failed: " . $e->getMessage());
            }
        }

        self::logResult($event, $recipient, $channels, $results, $errors, $context);
        return $results;
    }

    /**
     * Render a template file to ['subject' => ..., 'body' => ..., 'attachments' => ...].
     * Returns null if the template file does not exist.
     */
    private static function renderTemplate(string $event, string $channel, string $language, array $context): ?array {
        $dir = __DIR__ . '/notifications/templates';
        $candidates = [
            "{$dir}/{$event}.{$channel}.{$language}.php",
            "{$dir}/{$event}.{$channel}.en.php",
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $subject = null;
                $body = '';
                $attachments = [];
                extract($context, EXTR_SKIP);
                ob_start();
                include $path;
                $buffered = ob_get_clean();
                if (empty($body) && !empty($buffered)) {
                    $body = $buffered;
                }
                return [
                    'subject' => $subject,
                    'body' => $body,
                    'attachments' => $attachments,
                ];
            }
        }
        return null;
    }

    /**
     * Simple heuristic: if name contains Arabic Unicode range, use Arabic.
     */
    private static function detectLanguage(string $name): string {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $name) ? 'ar' : 'en';
    }

    /**
     * Log attempt + result to notification_log.
     */
    private static function logResult(string $event, array $recipient, array $channels, array $results, array $errors, array $context): void {
        try {
            $db = Database::getInstance();
            $succeeded = array_keys(array_filter($results));
            $failed = array_keys(array_filter($results, fn($v) => !$v));
            // Only count channels that were actually attempted (in $channels)
            $attemptedSet = array_values(array_intersect($channels, ['email', 'whatsapp']));

            $db->insert('notification_log', [
                'id' => self::uuid(),
                'event' => $event,
                'company_id' => $recipient['company_id'] ?? null,
                'recipient_email' => $recipient['email'] ?? null,
                'recipient_phone' => $recipient['phone'] ?? null,
                'recipient_name' => $recipient['name'] ?? null,
                'channels_attempted' => implode(',', $attemptedSet),
                'channels_succeeded' => implode(',', array_intersect($attemptedSet, $succeeded)),
                'channels_failed' => implode(',', array_intersect($attemptedSet, $failed)),
                'error_json' => !empty($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                'context_json' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Exception $e) {
            error_log('[Notifier] log insert failed: ' . $e->getMessage());
        }
    }

    private static function uuid(): string {
        if (function_exists('generateUUID')) return generateUUID();
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
