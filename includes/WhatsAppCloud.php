<?php
// WhatsAppCloud: Meta official Cloud API sender for the Cardify Scan claim loop.
// Direct WABA only, never through a BSP partner. Template messages only
// (business-initiated), one invite per shadow profile is enforced by the caller.
require_once __DIR__ . '/Database.php';

class WhatsAppCloud {

    const GRAPH_URL = 'https://graph.facebook.com/v21.0/';

    public static function isConfigured(): bool {
        return self::setting('wa_cloud_token') !== null && self::setting('wa_cloud_phone_id') !== null;
    }

    public static function sendTemplate(string $toE164, string $templateName, array $bodyParams, string $lang = 'ar'): array {
        $token = self::setting('wa_cloud_token');
        $phoneId = self::setting('wa_cloud_phone_id');
        if (!$token || !$phoneId) return ['success' => false, 'error' => 'not_configured'];

        $components = [];
        if ($bodyParams) {
            $components[] = ['type' => 'body', 'parameters' => array_map(function ($p) {
                return ['type' => 'text', 'text' => (string)$p];
            }, $bodyParams)];
        }
        $template = ['name' => $templateName, 'language' => ['code' => $lang]];
        // Meta rejects a zero-param template call when 'components' is present
        // but empty; only send the key when there is at least one component.
        if ($components) { $template['components'] = $components; }
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => ltrim($toE164, '+'),
            'type' => 'template',
            'template' => $template,
        ]);

        $ch = curl_init(self::GRAPH_URL . $phoneId . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            error_log('[WhatsAppCloud] send failed http=' . $code . ' body=' . substr((string)$resp, 0, 300));
            return ['success' => false, 'error' => 'http_' . $code];
        }
        return ['success' => true, 'error' => null];
    }

    private static function setting(string $key): ?string {
        $row = Database::getInstance()->fetchOne(
            "SELECT setting_value FROM system_settings WHERE setting_key = :k", ['k' => $key]
        );
        return $row ? ($row['setting_value'] ?: null) : null;
    }
}
