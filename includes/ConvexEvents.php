<?php
/**
 * ConvexEvents.php
 *
 * Non-blocking server-to-server event push from Cardify PHP to the self-hosted
 * Convex backend. Used by the live admin analytics surface; the MySQL
 * `card_events` table remains the system of record.
 *
 * Hard timeouts: 100ms connect, 200ms total. The HTTP call must NEVER add
 * perceivable latency to a card view, so failures are silent.
 *
 * Configuration in config.php:
 *
 *   define('FEATURE_LIVE_ANALYTICS', true);
 *   define('CONVEX_INGEST_URL',    'https://cardify.om/_convex/http/ingest');
 *   define('CONVEX_INGEST_SECRET', '<openssl rand -hex 32>');
 *
 * Returns immediately when the feature flag is off or the URL is unset.
 */

class ConvexEvents
{
    /**
     * Fire-and-forget event push to Convex.
     *
     * @param string      $employeeId        MySQL employees.id (UUID)
     * @param string      $companyMysqlId    MySQL companies.id
     * @param string      $companySlug       Company slug (e.g. "bhd")
     * @param string      $companyNameEn     Company display name (English)
     * @param string|null $companyNameAr     Company display name (Arabic, optional)
     * @param string      $eventType         One of CardAnalytics::EVENT_TYPES
     * @param array       $extras            Optional: ctaTarget, visitorId, ip,
     *                                       countryCode, countryName, city,
     *                                       device, browser, os, referrer
     * @return void
     */
    public static function send(
        $employeeId,
        $companyMysqlId,
        $companySlug,
        $companyNameEn,
        $companyNameAr,
        $eventType,
        array $extras = []
    ) {
        if (!defined('FEATURE_LIVE_ANALYTICS') || !FEATURE_LIVE_ANALYTICS) {
            return;
        }
        if (!defined('CONVEX_INGEST_URL') || empty(CONVEX_INGEST_URL)) {
            return;
        }
        if (!defined('CONVEX_INGEST_SECRET') || empty(CONVEX_INGEST_SECRET)) {
            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }

        $payload = array_merge(
            [
                'companyMysqlId' => (string) $companyMysqlId,
                'companySlug'    => (string) $companySlug,
                'companyNameEn'  => (string) $companyNameEn,
                'employeeId'     => (string) $employeeId,
                'type'           => (string) $eventType,
            ],
            $extras
        );

        if ($companyNameAr !== null && $companyNameAr !== '') {
            $payload['companyNameAr'] = (string) $companyNameAr;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return;
        }

        $ch = curl_init(CONVEX_INGEST_URL);
        if (!$ch) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Cardify-Ingest-Secret: ' . CONVEX_INGEST_SECRET,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 100,
            CURLOPT_TIMEOUT_MS        => 200,
            CURLOPT_NOSIGNAL          => 1,
            CURLOPT_FORBID_REUSE      => false,
            CURLOPT_FRESH_CONNECT     => false,
        ]);

        $resp = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || ($httpCode !== 0 && $httpCode >= 400)) {
            // Best-effort log; never throw. Convex outage must not affect the user.
            @error_log('ConvexEvents::send failed (' . $httpCode . '): ' . $err);
        }
    }
}
