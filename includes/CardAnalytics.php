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

            // Best-effort: mirror to Convex for the live admin analytics surface.
            // Hard-capped 200ms timeout, silent on failure. MySQL above is canonical.
            self::mirrorToConvex(
                $employeeId,
                $companyId,
                $eventType,
                $ctaTarget,
                $visitorId,
                $ip,
                $ua,
                $device,
                $geo,
                $referrer
            );

            return true;
        } catch (Exception $e) {
            error_log('CardAnalytics::log failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mirror the just-logged event into Convex (live analytics).
     * No-op when FEATURE_LIVE_ANALYTICS is off or the URL/secret are missing.
     */
    private static function mirrorToConvex(
        $employeeId,
        $companyId,
        $eventType,
        $ctaTarget,
        $visitorId,
        $ip,
        $ua,
        array $device,
        array $geo,
        $referrer
    ) {
        if (!defined('FEATURE_LIVE_ANALYTICS') || !FEATURE_LIVE_ANALYTICS) {
            return;
        }
        try {
            require_once __DIR__ . '/ConvexEvents.php';

            $company = function_exists('findCompanyById')
                ? findCompanyById($companyId)
                : null;

            $companySlug   = $company['slug']    ?? (string) $companyId;
            $companyNameEn = $company['name_en'] ?? $company['name'] ?? $companySlug;
            $companyNameAr = $company['name_ar'] ?? null;

            ConvexEvents::send(
                $employeeId,
                $companyId,
                $companySlug,
                $companyNameEn,
                $companyNameAr,
                $eventType,
                [
                    'ctaTarget'   => $ctaTarget ? substr($ctaTarget, 0, 512) : null,
                    'visitorId'   => $visitorId,
                    'ip'          => $ip,
                    'countryCode' => $geo['country_code'] ?? null,
                    'countryName' => $geo['country_name'] ?? null,
                    'city'        => $geo['city'] ?? null,
                    'device'      => $device['device_type'] ?? null,
                    'browser'     => $device['browser'] ?? null,
                    'os'          => $device['os'] ?? null,
                    'referrer'    => $referrer ? substr($referrer, 0, 512) : null,
                ]
            );
        } catch (Throwable $e) {
            @error_log('CardAnalytics::mirrorToConvex failed: ' . $e->getMessage());
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

    // ==================================================================
    // Growth Dashboard helpers
    // ==================================================================

    /**
     * Top performing cards for a company over the last N days.
     * Returns: [{employee_id, name, views, clicks, viral_clicks, saves, conversion}]
     */
    public static function getTopCards($companyId, $days = 7, $limit = 20)
    {
        self::init();
        $days  = (int) $days  > 0 ? (int) $days  : 7;
        $limit = (int) $limit > 0 ? (int) $limit : 20;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND e.company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }

        // Aggregate event counts per employee, joined to employees for name.
        $sql = "SELECT
                    e.id AS employee_id,
                    COALESCE(NULLIF(e.name_en, ''), NULLIF(e.name_ar, ''), e.email) AS name,
                    SUM(ev.event_type = 'view')               AS views,
                    SUM(ev.event_type LIKE 'click\\_%' AND ev.event_type <> 'short_link_click') AS clicks,
                    SUM(ev.event_type = 'viral_footer_click') AS viral_clicks,
                    SUM(ev.event_type = 'save_contact')       AS saves
                FROM employees e
                LEFT JOIN card_events ev
                       ON ev.employee_id = e.id
                      AND ev.created_at >= :start
                WHERE e.status = 'active' {$where}
                GROUP BY e.id, name
                HAVING views > 0 OR clicks > 0 OR viral_clicks > 0 OR saves > 0
                ORDER BY views DESC, clicks DESC
                LIMIT {$limit}";

        $rows = self::$db->fetchAll($sql, $params) ?: [];
        foreach ($rows as &$r) {
            $v = (int) $r['views'];
            $s = (int) $r['saves'];
            $r['views']        = $v;
            $r['clicks']       = (int) $r['clicks'];
            $r['viral_clicks'] = (int) $r['viral_clicks'];
            $r['saves']        = $s;
            $r['conversion']   = $v > 0 ? round(100 * $s / $v, 1) : 0.0;
        }
        return $rows;
    }

    /**
     * 5-stage funnel: views -> qr_scans -> cta clicks -> saves -> viral clicks.
     */
    public static function getFunnelSummary($companyId, $days = 7)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 7;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }

        $rows = self::$db->fetchAll(
            "SELECT event_type, COUNT(*) AS c
               FROM card_events
              WHERE created_at >= :start {$where}
              GROUP BY event_type",
            $params
        ) ?: [];

        $stages = [
            'views'         => 0,
            'qr_scans'      => 0,
            'cta_clicks'    => 0,
            'saves'         => 0,
            'viral_clicks'  => 0,
        ];
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            $t = $r['event_type'];
            if ($t === 'view') {
                $stages['views'] += $c;
            } elseif ($t === 'qr_scan') {
                $stages['qr_scans'] += $c;
            } elseif ($t === 'save_contact') {
                $stages['saves'] += $c;
            } elseif ($t === 'viral_footer_click') {
                $stages['viral_clicks'] += $c;
            } elseif (strpos($t, 'click_') === 0 && $t !== 'short_link_click') {
                $stages['cta_clicks'] += $c;
            }
        }
        return $stages;
    }

    /**
     * % of ACTIVE cards (employees with status='active' AND >=1 event in last 30d)
     * that have each public-card section enabled.
     */
    public static function getSectionAdoption($companyId)
    {
        self::init();
        $start30 = date('Y-m-d 00:00:00', strtotime('-30 days'));

        $params = ['start' => $start30];
        $where  = '';
        if ($companyId) {
            $where = 'AND e.company_id = :cid';
            $params['cid'] = $companyId;
        }

        // Active cards = status='active' AND at least 1 card_event in last 30d.
        // NOTE: employees uses utf8mb4_unicode_ci, card_events uses
        // utf8mb4_general_ci on some legacy installs, force the collation on
        // the join predicate so MySQL doesn't throw "Illegal mix of collations".
        $activeRow = self::$db->fetchOne(
            "SELECT COUNT(DISTINCT e.id) AS c
               FROM employees e
               JOIN card_events ev ON ev.employee_id COLLATE utf8mb4_unicode_ci = e.id
              WHERE e.status = 'active'
                AND ev.created_at >= :start {$where}",
            $params
        );
        $totalActive = (int) ($activeRow['c'] ?? 0);

        $sections = [
            'bio', 'services', 'gallery', 'testimonials',
            'lead_form', 'video', 'location', 'offers',
            'faq', 'hours', 'products',
        ];

        // Build a sum(col) query that respects column existence.
        $cols = self::$db->fetchAll(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_card_sections'"
        ) ?: [];
        $have = array_flip(array_column($cols, 'COLUMN_NAME'));

        if ($totalActive === 0) {
            $out = [];
            foreach ($sections as $s) {
                $out[] = [
                    'section' => $s,
                    'enabled' => 0,
                    'total'   => 0,
                    'percent' => 0.0,
                    'has_column' => isset($have[$s . '_enabled']),
                ];
            }
            return ['total_active' => 0, 'sections' => $out];
        }

        // Codex round-3 finding #6, the previous query joined card_events
        // then SUM()'d section_enabled per joined row, so every card counted
        // once per event (massive over-count). Do it in two steps instead:
        //
        //   1) resolve the DISTINCT set of active employee_ids (from events),
        //   2) count enabled flags in employee_card_sections for just those.
        //
        // One row per card → one vote per section. Clean percentages.
        $selects = [];
        foreach ($sections as $s) {
            $col = $s . '_enabled';
            if (isset($have[$col])) {
                $selects[] = "SUM(CASE WHEN s.`{$col}` = 1 THEN 1 ELSE 0 END) AS `{$s}`";
            } else {
                $selects[] = "0 AS `{$s}`";
            }
        }

        $sql = "SELECT " . implode(', ', $selects) . "
                  FROM (
                      SELECT DISTINCT e.id
                        FROM employees e
                        JOIN card_events ev ON ev.employee_id COLLATE utf8mb4_unicode_ci = e.id
                       WHERE e.status = 'active'
                         AND ev.created_at >= :start {$where}
                  ) AS active_cards
                  LEFT JOIN employee_card_sections s
                         ON s.employee_id = active_cards.id";

        $row = self::$db->fetchOne($sql, $params) ?: [];

        $out = [];
        foreach ($sections as $s) {
            $enabled = (int) ($row[$s] ?? 0);
            $out[] = [
                'section'    => $s,
                'enabled'    => $enabled,
                'total'      => $totalActive,
                'percent'    => $totalActive > 0 ? round(100 * $enabled / $totalActive, 1) : 0.0,
                'has_column' => isset($have[$s . '_enabled']),
            ];
        }
        return ['total_active' => $totalActive, 'sections' => $out];
    }

    /**
     * Company-scoped daily time series for the last N days covering all event
     * categories used by the Growth Dashboard line chart.
     */
    public static function getCompanyTimeSeries($companyId, $days = 30)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 30;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }

        $rows = self::$db->fetchAll(
            "SELECT DATE(created_at) AS d,
                    SUM(event_type = 'view')               AS views,
                    SUM(event_type = 'click_phone')        AS c_phone,
                    SUM(event_type = 'click_whatsapp')     AS c_whatsapp,
                    SUM(event_type = 'click_email')        AS c_email,
                    SUM(event_type = 'save_contact')       AS saves,
                    SUM(event_type = 'viral_footer_click') AS viral_clicks
               FROM card_events
              WHERE created_at >= :start {$where}
              GROUP BY DATE(created_at)
              ORDER BY d ASC",
            $params
        ) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $r = $map[$d] ?? [];
            $series[] = [
                'date'         => $d,
                'views'        => (int) ($r['views']       ?? 0),
                'c_phone'      => (int) ($r['c_phone']     ?? 0),
                'c_whatsapp'   => (int) ($r['c_whatsapp']  ?? 0),
                'c_email'      => (int) ($r['c_email']     ?? 0),
                'saves'        => (int) ($r['saves']       ?? 0),
                'viral_clicks' => (int) ($r['viral_clicks'] ?? 0),
            ];
        }
        return $series;
    }

    /**
     * Events totals (views + any click_*) for a company/date range, with
     * previous-period comparison for delta arrows.
     */
    public static function getEventTotals($companyId, $days = 7)
    {
        self::init();
        $days = (int) $days > 0 ? (int) $days : 7;

        $end      = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $start    = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        $prevEnd  = $start;
        $prevStart = date('Y-m-d 00:00:00', strtotime("-" . (2 * $days) . " days"));

        $where = $companyId ? 'AND company_id = :cid' : '';

        $totalFor = function ($s, $e) use ($where, $companyId) {
            $params = ['s' => $s, 'e' => $e];
            if ($companyId) {
                $params['cid'] = $companyId;
            }
            $r = self::$db->fetchOne(
                "SELECT COUNT(*) AS c
                   FROM card_events
                  WHERE created_at >= :s AND created_at < :e {$where}
                    AND (event_type = 'view' OR event_type LIKE 'click\\_%')",
                $params
            );
            return (int) ($r['c'] ?? 0);
        };

        $cur  = $totalFor($start, $end);
        $prev = $totalFor($prevStart, $prevEnd);
        $delta = $prev > 0 ? round(100 * ($cur - $prev) / $prev, 1) : ($cur > 0 ? 100.0 : 0.0);

        return ['current' => $cur, 'previous' => $prev, 'delta_pct' => $delta];
    }

    /**
     * Active card count: status='active' AND >=1 view in last N days (default 30).
     */
    public static function getActiveCardCount($companyId, $days = 30)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 30;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND e.company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }

        $r = self::$db->fetchOne(
            "SELECT COUNT(DISTINCT e.id) AS c
               FROM employees e
               JOIN card_events ev ON ev.employee_id = e.id AND ev.event_type = 'view'
              WHERE e.status = 'active'
                AND ev.created_at >= :start {$where}",
            $params
        );
        return (int) ($r['c'] ?? 0);
    }

    /**
     * New cardify_signup_leads in last N days (returns 0 if the table is missing).
     */
    public static function getNewLeadsCount($companyId, $days = 7)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 7;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        try {
            $exists = self::$db->fetchOne(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cardify_signup_leads'"
            );
            if ((int) ($exists['c'] ?? 0) === 0) {
                return 0;
            }
            // cardify_signup_leads is global (not company-scoped); super_admin sees all.
            $r = self::$db->fetchOne(
                "SELECT COUNT(*) AS c FROM cardify_signup_leads WHERE created_at >= :start",
                ['start' => $start]
            );
            return (int) ($r['c'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Viral-footer click total over last N days (company-scoped).
     */
    public static function getViralClicks($companyId, $days = 7)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 7;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }
        $r = self::$db->fetchOne(
            "SELECT COUNT(*) AS c FROM card_events
              WHERE event_type = 'viral_footer_click'
                AND created_at >= :start {$where}",
            $params
        );
        return (int) ($r['c'] ?? 0);
    }

    /**
     * Scan -> Save conversion proxy: share of distinct visitor sessions that
     * both viewed and saved_contact in the same UTC day within the last N days.
     */
    public static function getScanToSaveRate($companyId, $days = 7)
    {
        self::init();
        $days  = (int) $days > 0 ? (int) $days : 7;
        $start = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        $where = $companyId ? 'AND company_id = :cid' : '';
        $params = ['start' => $start];
        if ($companyId) {
            $params['cid'] = $companyId;
        }

        $view = self::$db->fetchOne(
            "SELECT COUNT(DISTINCT visitor_id) AS c FROM card_events
              WHERE event_type IN ('view','qr_scan')
                AND visitor_id IS NOT NULL
                AND created_at >= :start {$where}",
            $params
        );
        $save = self::$db->fetchOne(
            "SELECT COUNT(DISTINCT visitor_id) AS c FROM card_events
              WHERE event_type = 'save_contact'
                AND visitor_id IS NOT NULL
                AND created_at >= :start {$where}",
            $params
        );
        $v = (int) ($view['c'] ?? 0);
        $s = (int) ($save['c'] ?? 0);
        return [
            'views' => $v,
            'saves' => $s,
            'rate'  => $v > 0 ? round(100 * $s / $v, 2) : 0.0,
        ];
    }

    /**
     * Feature health: per known feature table, returns {table, rows, status}
     * where status is OK (rows>0), EMPTY (rows=0), or MISSING (table absent).
     */
    public static function getFeatureHealth()
    {
        self::init();
        $features = [
            'card_events', 'employee_card_sections', 'employee_card_services',
            'employee_card_gallery', 'employee_card_testimonials', 'employee_card_leads',
            'employee_card_offers', 'employee_card_faqs', 'employee_business_hours',
            'employee_card_products', 'nfc_cards', 'appointments', 'short_links',
            'card_requests', 'qr_scans', 'cardify_signup_leads',
        ];
        $out = [];
        foreach ($features as $t) {
            $status = 'MISSING';
            $rows = 0;
            try {
                $e = self::$db->fetchOne(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
                    ['t' => $t]
                );
                if ((int) ($e['c'] ?? 0) > 0) {
                    $r = self::$db->fetchOne("SELECT COUNT(*) AS c FROM `{$t}`");
                    $rows = (int) ($r['c'] ?? 0);
                    $status = $rows > 0 ? 'OK' : 'EMPTY';
                }
            } catch (Exception $e) {
                $status = 'ERROR';
            }
            $out[] = ['table' => $t, 'rows' => $rows, 'status' => $status];
        }
        return $out;
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
