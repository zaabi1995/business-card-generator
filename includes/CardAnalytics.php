<?php
/**
 * CardAnalytics
 *
 * Per-employee-card event tracking. Logs views, CTA clicks, QR scans,
 * save-to-contact, and wallet-add events into the `card_events` table.
 *
 * Reuses user-agent parsing and geo lookup from QRTracker to keep logic
 * consistent across both event stores.
 */

require_once __DIR__ . '/QRTracker.php';

class CardAnalytics
{
    private static $db = null;

    /** Allowed event types (must match the DB ENUM exactly). */
    const EVENT_TYPES = [
        'view',
        'click_phone',
        'click_mobile',
        'click_whatsapp',
        'click_email',
        'click_website',
        'click_map',
        'click_social',
        'save_contact',
        'wallet_add',
        'qr_scan',
        'offer_redeem',
        'product_order_click',
        'short_link_click',
        'viral_footer_click',
        'viral_footer_view',
    ];

    private static function init()
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }

    /**
     * Log an arbitrary card event.
     *
     * @param string      $employeeId
     * @param string      $companyId
     * @param string      $eventType  One of self::EVENT_TYPES
     * @param string|null $ctaTarget  Optional URL/value the click went to
     * @return bool
     */
    public static function log($employeeId, $companyId, $eventType, $ctaTarget = null)
    {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            return false;
        }

        try {
            self::init();

            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = self::getClientIP();
            $device = self::parseUserAgent($ua);
            $geo = self::getGeoFromIP($ip);
            $referrer = $_SERVER['HTTP_REFERER'] ?? null;
            if ($referrer !== null) {
                $referrer = substr($referrer, 0, 1024);
            }

            // Daily visitor hash (IP+UA+YYYY-MM-DD), no PII persistence required
            $visitorId = substr(
                hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d')),
                0,
                32
            );

            self::$db->insert('card_events', [
                'employee_id'  => $employeeId,
                'company_id'   => $companyId,
                'event_type'   => $eventType,
                'cta_target'   => $ctaTarget ? substr($ctaTarget, 0, 512) : null,
                'visitor_id'   => $visitorId,
                'ip_address'   => $ip,
                'user_agent'   => substr($ua, 0, 65535),
                'device_type'  => $device['device_type'] ?? 'unknown',
                'browser'      => $device['browser'] ?? null,
                'os'           => $device['os'] ?? null,
                'country_code' => $geo['country_code'] ?? null,
                'country_name' => $geo['country_name'] ?? null,
                'referrer'     => $referrer,
            ]);
            return true;
        } catch (Exception $e) {
            error_log('CardAnalytics::log failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Convenience: log a view event, auto-detect qr_scan from utm_source/referrer. */
    public static function logView($employeeId, $companyId)
    {
        $isQr = false;
        $utmSource = $_GET['utm_source'] ?? '';
        if ($utmSource === 'qr') {
            $isQr = true;
        } elseif (empty($_SERVER['HTTP_REFERER'])) {
            // Direct hits with no referrer are typically QR/NFC scans
            $isQr = true;
        }
        return self::log($employeeId, $companyId, $isQr ? 'qr_scan' : 'view');
    }

    /**
     * Build dashboard stats for a single employee over `days`.
     *
     * @return array
     */
    public static function getEmployeeStats($employeeId, $days = 30)
    {
        self::init();
        $days = (int) $days;
        if ($days <= 0) {
            $days = 30;
        }
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $kpis = [
            'views'           => 0,
            'clicks'          => 0,
            'unique_visitors' => 0,
            'qr_scans'        => 0,
            'saves'           => 0,
            'wallet_adds'     => 0,
        ];

        // Aggregate counts per event_type
        $rows = self::$db->fetchAll(
            "SELECT event_type, COUNT(*) AS c
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start
             GROUP BY event_type",
            ['eid' => $employeeId, 'start' => $startDate]
        ) ?: [];

        foreach ($rows as $r) {
            switch ($r['event_type']) {
                case 'view':
                    $kpis['views'] += (int) $r['c'];
                    break;
                case 'qr_scan':
                    $kpis['qr_scans'] += (int) $r['c'];
                    break;
                case 'save_contact':
                    $kpis['saves'] += (int) $r['c'];
                    break;
                case 'wallet_add':
                    $kpis['wallet_adds'] += (int) $r['c'];
                    break;
                default:
                    if (strpos($r['event_type'], 'click_') === 0) {
                        $kpis['clicks'] += (int) $r['c'];
                    }
            }
        }

        // Union qr_scans table so older scans show up in KPIs (pre-card_events data)
        try {
            $qrRow = self::$db->fetchOne(
                "SELECT COUNT(*) AS c FROM qr_scans
                 WHERE employee_id = :eid AND scanned_at >= :start",
                ['eid' => $employeeId, 'start' => $startDate]
            );
            $kpis['qr_scans'] += (int) ($qrRow['c'] ?? 0);
        } catch (Exception $e) {
            // qr_scans may not exist on some installs; non-fatal
        }

        $uniqRow = self::$db->fetchOne(
            "SELECT COUNT(DISTINCT visitor_id) AS c
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start AND visitor_id IS NOT NULL",
            ['eid' => $employeeId, 'start' => $startDate]
        );
        $kpis['unique_visitors'] = (int) ($uniqRow['c'] ?? 0);

        // Daily time series (views + clicks) with zero-filled dates
        $daily = self::$db->fetchAll(
            "SELECT DATE(created_at) AS d,
                    SUM(event_type = 'view') AS views,
                    SUM(event_type LIKE 'click_%') AS clicks
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            ['eid' => $employeeId, 'start' => $startDate]
        ) ?: [];
        $dailyMap = [];
        foreach ($daily as $row) {
            $dailyMap[$row['d']] = [
                'views'  => (int) $row['views'],
                'clicks' => (int) $row['clicks'],
            ];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $series[] = [
                'date'   => $d,
                'views'  => $dailyMap[$d]['views'] ?? 0,
                'clicks' => $dailyMap[$d]['clicks'] ?? 0,
            ];
        }

        // CTA breakdown (bar chart)
        $ctaRows = self::$db->fetchAll(
            "SELECT event_type, COUNT(*) AS c
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start AND event_type LIKE 'click_%'
             GROUP BY event_type
             ORDER BY c DESC",
            ['eid' => $employeeId, 'start' => $startDate]
        ) ?: [];

        // Device breakdown (doughnut)
        $deviceRows = self::$db->fetchAll(
            "SELECT device_type, COUNT(*) AS c
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start
             GROUP BY device_type
             ORDER BY c DESC",
            ['eid' => $employeeId, 'start' => $startDate]
        ) ?: [];

        // Top referrers
        $referrerRows = self::$db->fetchAll(
            "SELECT referrer, COUNT(*) AS c
             FROM card_events
             WHERE employee_id = :eid AND created_at >= :start AND referrer IS NOT NULL AND referrer <> ''
             GROUP BY referrer
             ORDER BY c DESC
             LIMIT 10",
            ['eid' => $employeeId, 'start' => $startDate]
        ) ?: [];

        // Top countries (union qr_scans + card_events)
        $countryRows = self::$db->fetchAll(
            "SELECT country_code, country_name, SUM(c) AS c FROM (
                SELECT country_code, country_name, COUNT(*) AS c
                FROM card_events
                WHERE employee_id = :eid1 AND created_at >= :start1 AND country_code IS NOT NULL
                GROUP BY country_code, country_name
                UNION ALL
                SELECT country_code, country_name, COUNT(*) AS c
                FROM qr_scans
                WHERE employee_id = :eid2 AND scanned_at >= :start2 AND country_code IS NOT NULL
                GROUP BY country_code, country_name
             ) u
             GROUP BY country_code, country_name
             ORDER BY c DESC
             LIMIT 10",
            [
                'eid1' => $employeeId, 'start1' => $startDate,
                'eid2' => $employeeId, 'start2' => $startDate,
            ]
        ) ?: [];

        return [
            'days'      => $days,
            'kpis'      => $kpis,
            'series'    => $series,
            'cta'       => $ctaRows,
            'devices'   => $deviceRows,
            'referrers' => $referrerRows,
            'countries' => $countryRows,
        ];
    }

    // ------------------------------------------------------------------
    // Re-implemented helpers (kept private so this class stands alone,
    // but mirror QRTracker semantics so numbers line up).
    // ------------------------------------------------------------------

    private static function getClientIP()
    {
        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ] as $h) {
            if (!empty($_SERVER[$h])) {
                $ips = explode(',', $_SERVER[$h]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function parseUserAgent($ua)
    {
        $result = ['device_type' => 'unknown', 'browser' => null, 'os' => null];
        if (empty($ua)) {
            return $result;
        }
        $lc = strtolower($ua);
        if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $lc)) {
            $result['device_type'] = 'mobile';
        } elseif (preg_match('/tablet|ipad|playbook|silk/i', $lc)) {
            $result['device_type'] = 'tablet';
        } else {
            $result['device_type'] = 'desktop';
        }
        $browsers = [
            'Edge'    => '/edg(?:e|a|ios)?\//i',
            'Chrome'  => '/(?:chrome|crios)\//i',
            'Firefox' => '/(?:firefox|fxios)\//i',
            'Safari'  => '/version\/[\d.]+.*safari/i',
            'Opera'   => '/(?:opera|opr)\//i',
        ];
        foreach ($browsers as $name => $pat) {
            if (preg_match($pat, $ua)) {
                $result['browser'] = $name;
                break;
            }
        }
        if (preg_match('/windows nt/i', $ua)) {
            $result['os'] = 'Windows';
        } elseif (preg_match('/mac os x/i', $ua)) {
            $result['os'] = 'macOS';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $result['os'] = 'iOS';
        } elseif (preg_match('/android/i', $ua)) {
            $result['os'] = 'Android';
        } elseif (preg_match('/linux/i', $ua)) {
            $result['os'] = 'Linux';
        }
        return $result;
    }

    /** Geo lookup via ip-api.com (cached short per-request by static array). */
    private static $geoCache = [];
    private static function getGeoFromIP($ip)
    {
        if (isset(self::$geoCache[$ip])) {
            return self::$geoCache[$ip];
        }
        $result = ['country_code' => null, 'country_name' => null];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return self::$geoCache[$ip] = $result;
        }
        try {
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode';
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body) {
                $j = json_decode($body, true);
                if (!empty($j) && ($j['status'] ?? '') === 'success') {
                    $result['country_code'] = $j['countryCode'] ?? null;
                    $result['country_name'] = $j['country'] ?? null;
                }
            }
        } catch (Exception $e) {
            // silent
        }
        return self::$geoCache[$ip] = $result;
    }
}
