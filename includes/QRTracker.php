<?php
/**
 * QR Code Scan Tracker
 * Logs and analyzes QR code scans for employee business cards
 */
class QRTracker {
    
    private static $db = null;
    
    /**
     * Initialize database connection
     */
    private static function init() {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }
    
    /**
     * Log a QR code scan
     * @param string $employeeId Employee ID
     * @param string $companyId Company ID
     * @return array Result with scan_id
     */
    public static function logScan($employeeId, $companyId) {
        self::init();
        
        // Get or create visitor ID
        $visitorId = self::getOrCreateVisitorId();
        
        // Parse user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $deviceInfo = self::parseUserAgent($userAgent);
        
        // Get IP address
        $ipAddress = self::getClientIP();
        
        // Get geolocation from IP (async-friendly, can be null)
        $geoData = self::getGeoFromIP($ipAddress);
        
        // Prepare scan data
        $scanData = [
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'visitor_id' => $visitorId,
            'ip_address' => $ipAddress,
            'user_agent' => substr($userAgent, 0, 65535), // Truncate if too long
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'browser_version' => $deviceInfo['browser_version'],
            'os' => $deviceInfo['os'],
            'os_version' => $deviceInfo['os_version'],
            'country_code' => $geoData['country_code'] ?? null,
            'country_name' => $geoData['country_name'] ?? null,
            'region' => $geoData['region'] ?? null,
            'city' => $geoData['city'] ?? null,
            'latitude' => $geoData['latitude'] ?? null,
            'longitude' => $geoData['longitude'] ?? null,
            'timezone' => $geoData['timezone'] ?? null,
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 65535) : null,
            'landing_url' => self::getCurrentUrl()
        ];
        
        try {
            // Insert scan record
            $scanId = self::$db->insert('qr_scans', $scanData);
            
            // Update employee scan count
            self::$db->query(
                "UPDATE employees SET total_scans = total_scans + 1, last_scanned_at = NOW() WHERE id = :id",
                ['id' => $employeeId]
            );
            
            // Update daily stats (async could be better, but keeping it simple)
            self::updateDailyStats($employeeId, $companyId, $deviceInfo['device_type']);
            
            return [
                'success' => true,
                'scan_id' => $scanId,
                'visitor_id' => $visitorId
            ];
        } catch (Exception $e) {
            error_log("QRTracker error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get or create a unique visitor ID via cookie
     */
    private static function getOrCreateVisitorId() {
        $cookieName = 'bc_visitor_id';
        
        if (isset($_COOKIE[$cookieName]) && !empty($_COOKIE[$cookieName])) {
            return $_COOKIE[$cookieName];
        }
        
        // Generate new visitor ID
        $visitorId = bin2hex(random_bytes(16));
        
        // Try to set cookie (may fail if headers already sent, that's ok).
        // Secure flag uses proxy-aware HTTPS detection (Codex round-2 Finding 7).
        if (!headers_sent()) {
            if (!function_exists('isHttpsRequest')) {
                require_once __DIR__ . '/UrlSafety.php';
            }
            $expiry = time() + (2 * 365 * 24 * 60 * 60);
            @setcookie($cookieName, $visitorId, [
                'expires' => $expiry,
                'path' => '/',
                'secure' => isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        return $visitorId;
    }
    
    /**
     * Get client IP address
     */
    private static function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_REAL_IP',            // Nginx
            'HTTP_CLIENT_IP',            // Other proxies
            'REMOTE_ADDR'                // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Parse user agent string
     */
    private static function parseUserAgent($userAgent) {
        $result = [
            'device_type' => 'unknown',
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null
        ];
        
        if (empty($userAgent)) {
            return $result;
        }
        
        $ua = strtolower($userAgent);
        
        // Detect device type
        if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) {
            $result['device_type'] = 'mobile';
        } elseif (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
            $result['device_type'] = 'tablet';
        } else {
            $result['device_type'] = 'desktop';
        }
        
        // Detect browser
        $browsers = [
            'Edge' => '/edg(?:e|a|ios)?\/(\d+[\.\d]*)/i',
            'Chrome' => '/(?:chrome|crios)\/(\d+[\.\d]*)/i',
            'Firefox' => '/(?:firefox|fxios)\/(\d+[\.\d]*)/i',
            'Safari' => '/version\/(\d+[\.\d]*).*safari/i',
            'Opera' => '/(?:opera|opr)\/(\d+[\.\d]*)/i',
            'IE' => '/(?:msie |rv:)(\d+[\.\d]*)/i',
            'Samsung Browser' => '/samsungbrowser\/(\d+[\.\d]*)/i'
        ];
        
        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $result['browser'] = $browser;
                $result['browser_version'] = $matches[1] ?? null;
                break;
            }
        }
        
        // Detect OS
        $osPatterns = [
            'Windows' => '/windows nt (\d+[\.\d]*)/i',
            'macOS' => '/mac os x (\d+[_\.\d]*)/i',
            'iOS' => '/(?:iphone|ipad|ipod).*os (\d+[_\.\d]*)/i',
            'Android' => '/android (\d+[\.\d]*)/i',
            'Linux' => '/linux/i',
            'Chrome OS' => '/cros/i'
        ];
        
        foreach ($osPatterns as $os => $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $result['os'] = $os;
                if (isset($matches[1])) {
                    $result['os_version'] = str_replace('_', '.', $matches[1]);
                }
                break;
            }
        }
        
        return $result;
    }
    
    /**
     * Get geolocation from IP address
     * Uses ip-api.com free service (limit: 45 requests/minute)
     */
    private static function getGeoFromIP($ip) {
        $result = [
            'country_code' => null,
            'country_name' => null,
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null
        ];
        
        // Skip for local/private IPs
        if (self::isPrivateIP($ip)) {
            return $result;
        }
        
        try {
            // Use ip-api.com (free, no API key required)
            // Note: ip-api.com free tier only supports HTTP; HTTPS requires pro plan
            // Using HTTP is acceptable here as this is non-sensitive geo-lookup data
            $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,countryCode,region,regionName,city,lat,lon,timezone";
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2, // 2 second timeout
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['status']) && $data['status'] === 'success') {
                    $result['country_code'] = $data['countryCode'] ?? null;
                    $result['country_name'] = $data['country'] ?? null;
                    $result['region'] = $data['regionName'] ?? null;
                    $result['city'] = $data['city'] ?? null;
                    $result['latitude'] = $data['lat'] ?? null;
                    $result['longitude'] = $data['lon'] ?? null;
                    $result['timezone'] = $data['timezone'] ?? null;
                }
            }
        } catch (Exception $e) {
            // Silently fail - geo is optional
            error_log("Geo lookup failed: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Check if IP is private/local
     */
    private static function isPrivateIP($ip) {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
    
    /**
     * Get current URL
     */
    private static function getCurrentUrl() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return substr($protocol . '://' . $host . $uri, 0, 65535);
    }
    
    /**
     * Update daily aggregated stats
     */
    private static function updateDailyStats($employeeId, $companyId, $deviceType) {
        $today = date('Y-m-d');
        
        try {
            // Check if record exists
            $existing = self::$db->fetchOne(
                "SELECT id FROM qr_scan_daily_stats WHERE employee_id = :eid AND scan_date = :date",
                ['eid' => $employeeId, 'date' => $today]
            );
            
            if ($existing) {
                // Update existing
                $deviceColumn = $deviceType . '_scans';
                if (!in_array($deviceType, ['mobile', 'desktop', 'tablet'])) {
                    $deviceColumn = 'mobile_scans'; // Default
                }
                
                self::$db->query(
                    "UPDATE qr_scan_daily_stats 
                     SET total_scans = total_scans + 1,
                         {$deviceColumn} = {$deviceColumn} + 1
                     WHERE employee_id = :eid AND scan_date = :date",
                    ['eid' => $employeeId, 'date' => $today]
                );
            } else {
                // Insert new
                $data = [
                    'employee_id' => $employeeId,
                    'company_id' => $companyId,
                    'scan_date' => $today,
                    'total_scans' => 1,
                    'unique_visitors' => 1,
                    'mobile_scans' => $deviceType === 'mobile' ? 1 : 0,
                    'desktop_scans' => $deviceType === 'desktop' ? 1 : 0,
                    'tablet_scans' => $deviceType === 'tablet' ? 1 : 0
                ];
                self::$db->insert('qr_scan_daily_stats', $data);
            }
        } catch (Exception $e) {
            error_log("Daily stats update failed: " . $e->getMessage());
        }
    }
    
    /**
     * Check if QR analytics is available for a company
     * Free users can track scans but cannot view detailed analytics
     * 
     * @param string $companyId Company ID
     * @return array ['available' => bool, 'reason' => string]
     */
    public static function checkAnalyticsAccess($companyId) {
        require_once INCLUDES_DIR . '/Billing.php';
        
        if (Billing::isQRTrackingEnabled($companyId)) {
            return ['available' => true, 'reason' => null];
        }
        
        return [
            'available' => false,
            'reason' => 'QR scan analytics is a premium feature. Upgrade your plan to access detailed scan statistics, geographic data, and device breakdowns.',
            'upgrade_required' => true
        ];
    }
    
    /**
     * Get scan statistics for an employee
     * Note: For free plans, returns limited stats with upgrade notice
     */
    public static function getEmployeeStats($employeeId, $days = 30, $companyId = null) {
        try {
            self::init();
            
            // Check analytics access if company ID provided
            if ($companyId) {
                $access = self::checkAnalyticsAccess($companyId);
                if (!$access['available']) {
                    // Return basic stats only for free users
                    return self::getBasicEmployeeStats($employeeId, $access['reason']);
                }
            }
            
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            // Total scans
            $totalScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE employee_id = :id",
                ['id' => $employeeId]
            );
            $totalScans = $totalScansRow['total'] ?? 0;
            
            // Unique visitors
            $uniqueVisitorsRow = self::$db->fetchOne(
                "SELECT COUNT(DISTINCT visitor_id) as total FROM qr_scans WHERE employee_id = :id",
                ['id' => $employeeId]
            );
            $uniqueVisitors = $uniqueVisitorsRow['total'] ?? 0;
            
            // Scans in period
            $periodScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE employee_id = :id AND scanned_at >= :start",
                ['id' => $employeeId, 'start' => $startDate]
            );
            $periodScans = $periodScansRow['total'] ?? 0;
            
            // Daily breakdown
            $dailyStats = self::$db->fetchAll(
                "SELECT scan_date, total_scans, unique_visitors, mobile_scans, desktop_scans, tablet_scans
                 FROM qr_scan_daily_stats 
                 WHERE employee_id = :id AND scan_date >= :start
                 ORDER BY scan_date ASC",
                ['id' => $employeeId, 'start' => $startDate]
            ) ?: [];
            
            // Device breakdown
            $deviceStats = self::$db->fetchAll(
                "SELECT device_type, COUNT(*) as count 
                 FROM qr_scans 
                 WHERE employee_id = :id AND scanned_at >= :start
                 GROUP BY device_type",
                ['id' => $employeeId, 'start' => $startDate]
            ) ?: [];
            
            // Country breakdown (top 10)
            $countryStats = self::$db->fetchAll(
                "SELECT country_code, country_name, COUNT(*) as count 
                 FROM qr_scans 
                 WHERE employee_id = :id AND scanned_at >= :start AND country_code IS NOT NULL
                 GROUP BY country_code, country_name
                 ORDER BY count DESC
                 LIMIT 10",
                ['id' => $employeeId, 'start' => $startDate]
            ) ?: [];
            
            // Browser breakdown
            $browserStats = self::$db->fetchAll(
                "SELECT browser, COUNT(*) as count 
                 FROM qr_scans 
                 WHERE employee_id = :id AND scanned_at >= :start AND browser IS NOT NULL
                 GROUP BY browser
                 ORDER BY count DESC
                 LIMIT 5",
                ['id' => $employeeId, 'start' => $startDate]
            ) ?: [];
            
            // Recent scans
            $recentScans = self::$db->fetchAll(
                "SELECT scanned_at, ip_address, device_type, browser, os, country_name, city
                 FROM qr_scans 
                 WHERE employee_id = :id
                 ORDER BY scanned_at DESC
                 LIMIT 20",
                ['id' => $employeeId]
            ) ?: [];
            
            return [
                'total_scans' => (int)$totalScans,
                'unique_visitors' => (int)$uniqueVisitors,
                'period_scans' => (int)$periodScans,
                'period_days' => $days,
                'daily' => $dailyStats,
                'devices' => $deviceStats,
                'countries' => $countryStats,
                'browsers' => $browserStats,
                'recent' => $recentScans
            ];
        } catch (Exception $e) {
            error_log("QRTracker::getEmployeeStats error: " . $e->getMessage());
            return self::emptyStats($days);
        }
    }
    
    /**
     * Get basic employee stats for free users (limited view)
     */
    private static function getBasicEmployeeStats($employeeId, $upgradeMessage) {
        try {
            self::init();
            
            // Total scans only - no breakdown
            $totalScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE employee_id = :id",
                ['id' => $employeeId]
            );
            $totalScans = $totalScansRow['total'] ?? 0;
            
            // Unique visitors only - no breakdown
            $uniqueVisitorsRow = self::$db->fetchOne(
                "SELECT COUNT(DISTINCT visitor_id) as total FROM qr_scans WHERE employee_id = :id",
                ['id' => $employeeId]
            );
            $uniqueVisitors = $uniqueVisitorsRow['total'] ?? 0;
            
            return [
                'total_scans' => (int)$totalScans,
                'unique_visitors' => (int)$uniqueVisitors,
                'period_scans' => 0,
                'period_days' => 30,
                'daily' => [],
                'devices' => [],
                'countries' => [],
                'browsers' => [],
                'recent' => [],
                'limited' => true,
                'upgrade_message' => $upgradeMessage
            ];
        } catch (Exception $e) {
            return self::emptyStats(30);
        }
    }
    
    /**
     * Get company-wide scan statistics
     * Note: For free plans, returns limited stats with upgrade notice
     */
    public static function getCompanyStats($companyId, $days = 30) {
        try {
            self::init();
            
            // Check analytics access
            $access = self::checkAnalyticsAccess($companyId);
            if (!$access['available']) {
                return self::getBasicCompanyStats($companyId, $access['reason']);
            }
            
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            // Total scans
            $totalScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE company_id = :id",
                ['id' => $companyId]
            );
            $totalScans = $totalScansRow['total'] ?? 0;
            
            // Scans in period
            $periodScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE company_id = :id AND scanned_at >= :start",
                ['id' => $companyId, 'start' => $startDate]
            );
            $periodScans = $periodScansRow['total'] ?? 0;
            
            // Unique visitors in period
            $uniqueVisitorsRow = self::$db->fetchOne(
                "SELECT COUNT(DISTINCT visitor_id) as total FROM qr_scans WHERE company_id = :id AND scanned_at >= :start",
                ['id' => $companyId, 'start' => $startDate]
            );
            $uniqueVisitors = $uniqueVisitorsRow['total'] ?? 0;
            
            // Daily totals
            $dailyStats = self::$db->fetchAll(
                "SELECT DATE(scanned_at) as scan_date, COUNT(*) as total_scans, COUNT(DISTINCT visitor_id) as unique_visitors
                 FROM qr_scans 
                 WHERE company_id = :id AND scanned_at >= :start
                 GROUP BY DATE(scanned_at)
                 ORDER BY scan_date ASC",
                ['id' => $companyId, 'start' => $startDate]
            ) ?: [];
            
            // Top employees
            $topEmployees = self::$db->fetchAll(
                "SELECT qs.employee_id, e.name_en, e.email, COUNT(*) as scan_count
                 FROM qr_scans qs
                 LEFT JOIN employees e ON qs.employee_id = e.id
                 WHERE qs.company_id = :id AND qs.scanned_at >= :start
                 GROUP BY qs.employee_id, e.name_en, e.email
                 ORDER BY scan_count DESC
                 LIMIT 10",
                ['id' => $companyId, 'start' => $startDate]
            ) ?: [];
            
            // Device breakdown
            $deviceStats = self::$db->fetchAll(
                "SELECT device_type, COUNT(*) as count 
                 FROM qr_scans 
                 WHERE company_id = :id AND scanned_at >= :start
                 GROUP BY device_type",
                ['id' => $companyId, 'start' => $startDate]
            ) ?: [];
            
            // Country breakdown
            $countryStats = self::$db->fetchAll(
                "SELECT country_code, country_name, COUNT(*) as count 
                 FROM qr_scans 
                 WHERE company_id = :id AND scanned_at >= :start AND country_code IS NOT NULL
                 GROUP BY country_code, country_name
                 ORDER BY count DESC
                 LIMIT 10",
                ['id' => $companyId, 'start' => $startDate]
            ) ?: [];
            
            return [
                'total_scans' => (int)$totalScans,
                'period_scans' => (int)$periodScans,
                'unique_visitors' => (int)$uniqueVisitors,
                'period_days' => $days,
                'daily' => $dailyStats,
                'top_employees' => $topEmployees,
                'devices' => $deviceStats,
                'countries' => $countryStats
            ];
        } catch (Exception $e) {
            error_log("QRTracker::getCompanyStats error: " . $e->getMessage());
            return self::emptyStats($days);
        }
    }
    
    /**
     * Get basic company stats for free users (limited view)
     */
    private static function getBasicCompanyStats($companyId, $upgradeMessage) {
        try {
            self::init();
            
            // Total scans only
            $totalScansRow = self::$db->fetchOne(
                "SELECT COUNT(*) as total FROM qr_scans WHERE company_id = :id",
                ['id' => $companyId]
            );
            $totalScans = $totalScansRow['total'] ?? 0;
            
            // Unique visitors only
            $uniqueVisitorsRow = self::$db->fetchOne(
                "SELECT COUNT(DISTINCT visitor_id) as total FROM qr_scans WHERE company_id = :id",
                ['id' => $companyId]
            );
            $uniqueVisitors = $uniqueVisitorsRow['total'] ?? 0;
            
            return [
                'total_scans' => (int)$totalScans,
                'period_scans' => 0,
                'unique_visitors' => (int)$uniqueVisitors,
                'period_days' => 30,
                'daily' => [],
                'top_employees' => [],
                'devices' => [],
                'countries' => [],
                'limited' => true,
                'upgrade_message' => $upgradeMessage
            ];
        } catch (Exception $e) {
            return self::emptyStats(30);
        }
    }
    
    /**
     * Return empty stats structure (used when table doesn't exist yet)
     */
    private static function emptyStats($days = 30) {
        return [
            'total_scans' => 0,
            'unique_visitors' => 0,
            'period_scans' => 0,
            'period_days' => $days,
            'daily' => [],
            'devices' => [],
            'countries' => [],
            'browsers' => [],
            'recent' => [],
            'top_employees' => []
        ];
    }
}
