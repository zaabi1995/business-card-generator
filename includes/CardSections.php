<?php
/**
 * CardSections - Helper for public employee card sections
 *
 * Loads and saves section data (bio, services, gallery, testimonials, lead form)
 * for the public digital card page.
 */

class CardSections
{
    // Display order on the public card page. Offers placed early (high-value CTA),
    // followed by bio → services/gallery → testimonials (social proof) → lead_form (bottom CTA).
    const SECTION_KEYS = ['bio', 'offers', 'services', 'products', 'gallery', 'video', 'testimonials', 'faq', 'lead_form', 'location', 'hours'];
    const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    // PHP date('N') returns 1=Mon..7=Sun; map to our keys.
    const DAY_BY_ISO = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    const DAY_NAMES_EN = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
        'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];
    const DAY_NAMES_AR = [
        'mon' => 'الاثنين', 'tue' => 'الثلاثاء', 'wed' => 'الأربعاء',
        'thu' => 'الخميس', 'fri' => 'الجمعة', 'sat' => 'السبت', 'sun' => 'الأحد',
    ];
    const DEFAULT_TZ = 'Asia/Muscat';
    const OFFER_PALETTE = ['#009bc1', '#ffbb00', '#824598', '#45c0ba', '#e74c3c', '#27ae60', '#111827'];
    const SUPPORTED_LOCALES = ['en', 'ar'];
    const DEFAULT_LOCALE = 'en';
    const LOCALE_COOKIE = 'cardify_locale';
    const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB
    const MAX_GALLERY_IMAGES = 12;
    const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Load master row for an employee (returns defaults if missing).
     */
    public static function loadMaster($employeeId, $companyId)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM employee_card_sections WHERE employee_id = :eid",
            ['eid' => $employeeId]
        );
        if (!$row) {
            return [
                'employee_id' => $employeeId,
                'company_id' => $companyId,
                'bio_enabled' => 0,
                'bio_text' => '',
                'bio_text_ar' => '',
                'services_enabled' => 0,
                'gallery_enabled' => 0,
                'testimonials_enabled' => 0,
                'lead_form_enabled' => 0,
                'lead_form_email' => '',
                'offers_enabled' => 0,
                'location_enabled' => 0,
                'location_address' => '',
                'location_lat' => null,
                'location_lng' => null,
                'location_label' => '',
                'video_enabled' => 0,
                'video_url' => '',
                'video_title' => '',
                'products_enabled' => 0,
                'hours_enabled' => 0,
                'hours_timezone' => self::DEFAULT_TZ,
                'faq_enabled' => 0,
                'section_order' => implode(',', self::SECTION_KEYS),
            ];
        }
        if (!array_key_exists('offers_enabled', $row)) {
            $row['offers_enabled'] = 0;
        }
        if (!array_key_exists('video_enabled', $row)) {
            $row['video_enabled'] = 0;
            $row['video_url'] = '';
            $row['video_title'] = '';
        }
        if (!array_key_exists('products_enabled', $row)) {
            $row['products_enabled'] = 0;
        }
        foreach (['location_enabled' => 0, 'location_address' => '', 'location_lat' => null, 'location_lng' => null, 'location_label' => ''] as $k => $v) {
            if (!array_key_exists($k, $row)) {
                $row[$k] = $v;
            }
        }
        foreach (['hours_enabled' => 0, 'hours_timezone' => self::DEFAULT_TZ] as $k => $v) {
            if (!array_key_exists($k, $row) || $row[$k] === null) {
                $row[$k] = $v;
            }
        }
        if (!array_key_exists('faq_enabled', $row)) {
            $row['faq_enabled'] = 0;
        }
        return $row;
    }

    public static function loadServices($employeeId)
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_services WHERE employee_id = :eid ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function loadGallery($employeeId)
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_gallery WHERE employee_id = :eid ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function loadTestimonials($employeeId, $status = null)
    {
        if ($status !== null && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return Database::getInstance()->fetchAll(
                "SELECT * FROM employee_card_testimonials WHERE employee_id = :eid AND status = :st ORDER BY position, created_at",
                ['eid' => $employeeId, 'st' => $status]
            );
        }
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_testimonials WHERE employee_id = :eid ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function loadApprovedTestimonials($employeeId)
    {
        return self::loadTestimonials($employeeId, 'approved');
    }

    public static function loadPendingTestimonials($employeeId)
    {
        return self::loadTestimonials($employeeId, 'pending');
    }

    public static function loadRejectedTestimonials($employeeId)
    {
        return self::loadTestimonials($employeeId, 'rejected');
    }

    public static function setTestimonialStatus($employeeId, $tid, $status)
    {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return false;
        }
        $stmt = Database::getInstance()->getConnection()->prepare(
            "UPDATE employee_card_testimonials SET status = :st WHERE id = :id AND employee_id = :eid"
        );
        $stmt->execute(['st' => $status, 'id' => $tid, 'eid' => $employeeId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Rate-limit visitor testimonial submissions — 3 per IP per hour (across all employees).
     */
    public static function canSubmitTestimonial($ip)
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) AS c FROM employee_card_testimonials
             WHERE submitter_ip = :ip AND submitted_by_visitor = 1
               AND created_at > (NOW() - INTERVAL 1 HOUR)",
            ['ip' => $ip]
        );
        return (int)($row['c'] ?? 0) < 3;
    }

    /**
     * Load offers for an employee.
     *
     * @param string $employeeId
     * @param bool   $publicOnly  When true, filter to enabled + non-expired only.
     */
    public static function loadOffers($employeeId, $publicOnly = false)
    {
        if ($publicOnly) {
            return Database::getInstance()->fetchAll(
                "SELECT * FROM employee_card_offers
                 WHERE employee_id = :eid
                   AND enabled = 1
                   AND (valid_until IS NULL OR valid_until >= CURDATE())
                   AND (valid_from  IS NULL OR valid_from  <= CURDATE())
                 ORDER BY position, created_at",
                ['eid' => $employeeId]
            );
        }
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_offers WHERE employee_id = :eid ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function addOffer($employeeId, $companyId, $title, $description, $discountLabel, $validUntil, $badgeColor)
    {
        $title = trim((string)$title);
        if ($title === '') return false;

        $color = trim((string)$badgeColor);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = self::OFFER_PALETTE[0];
        }

        $vu = trim((string)$validUntil);
        if ($vu === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vu)) {
            $vu = null;
        }

        $id = self::uuid();
        Database::getInstance()->insert('employee_card_offers', [
            'id' => $id,
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'title' => mb_substr($title, 0, 255),
            'description' => mb_substr(trim((string)$description), 0, 2000),
            'discount_label' => mb_substr(trim((string)$discountLabel), 0, 64),
            'valid_until' => $vu,
            'badge_color' => $color,
            'enabled' => 1,
            'position' => 0,
        ]);
        return $id;
    }

    public static function deleteOffer($employeeId, $offerId)
    {
        Database::getInstance()->getConnection()
            ->prepare("DELETE FROM employee_card_offers WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $offerId, 'eid' => $employeeId]);
    }

    /**
     * Atomic redemption increment. Returns the offer row (post-increment) or null
     * if not found / expired / disabled / wrong employee.
     */
    public static function redeemOffer($employeeId, $offerId)
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE employee_card_offers
                SET redemption_count = redemption_count + 1
              WHERE id = :id
                AND employee_id = :eid
                AND enabled = 1
                AND (valid_until IS NULL OR valid_until >= CURDATE())
                AND (valid_from  IS NULL OR valid_from  <= CURDATE())"
        );
        $stmt->execute(['id' => $offerId, 'eid' => $employeeId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return Database::getInstance()->fetchOne(
            "SELECT * FROM employee_card_offers WHERE id = :id",
            ['id' => $offerId]
        );
    }

    // ---- Product catalog -------------------------------------------------

    /**
     * Load products for an employee.
     *
     * @param string $employeeId
     * @param bool   $publicOnly  When true, only enabled products are returned.
     */
    public static function loadProducts($employeeId, $publicOnly = false)
    {
        if ($publicOnly) {
            return Database::getInstance()->fetchAll(
                "SELECT * FROM employee_card_products
                 WHERE employee_id = :eid AND enabled = 1
                 ORDER BY position, created_at",
                ['eid' => $employeeId]
            );
        }
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_products
             WHERE employee_id = :eid
             ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function addProduct($employeeId, $companyId, $title, $description, $price, $imagePath = null, $whatsappMessage = '')
    {
        $title = trim((string)$title);
        if ($title === '') return false;

        $priceVal = is_numeric($price) ? round((float)$price, 3) : 0.0;
        if ($priceVal < 0) $priceVal = 0.0;

        $id = self::uuid();
        Database::getInstance()->insert('employee_card_products', [
            'id' => $id,
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'title' => mb_substr($title, 0, 255),
            'description' => mb_substr(trim((string)$description), 0, 4000),
            'price' => $priceVal,
            'currency' => 'OMR',
            'image_path' => $imagePath ?: null,
            'whatsapp_message' => mb_substr(trim((string)$whatsappMessage), 0, 500) ?: null,
            'position' => 0,
            'enabled' => 1,
        ]);
        return $id;
    }

    public static function deleteProduct($employeeId, $productId)
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT image_path FROM employee_card_products WHERE id = :id AND employee_id = :eid",
            ['id' => $productId, 'eid' => $employeeId]
        );
        if ($existing && !empty($existing['image_path'])) {
            self::unlinkUpload($existing['image_path']);
        }
        $db->getConnection()
            ->prepare("DELETE FROM employee_card_products WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $productId, 'eid' => $employeeId]);
    }

    /**
     * Persist the product list order. Accepts an ordered array of product IDs.
     */
    public static function reorderProducts($employeeId, array $orderedIds)
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE employee_card_products SET position = :pos
             WHERE id = :id AND employee_id = :eid"
        );
        $i = 0;
        foreach ($orderedIds as $pid) {
            $pid = (string)$pid;
            if ($pid === '') continue;
            $stmt->execute(['pos' => $i++, 'id' => $pid, 'eid' => $employeeId]);
        }
    }

    /**
     * Format a price as "OMR 5.000" (3-decimal OMR convention; space for RTL-safety).
     */
    public static function formatPrice($price, $currency = 'OMR')
    {
        $val = number_format((float)$price, 3, '.', ',');
        $cur = $currency ?: 'OMR';
        return $cur . ' ' . $val;
    }

    /**
     * Build a wa.me URL for a product order. Uses {title} and {price} placeholders
     * in the per-product message template, falling back to a sensible default.
     */
    public static function buildProductWhatsappUrl($phone, array $product, $locale = 'en')
    {
        $digits = preg_replace('/[^0-9]/', '', (string)$phone);
        if ($digits === '') return '';

        $title = (string)($product['title'] ?? '');
        $price = self::formatPrice($product['price'] ?? 0, $product['currency'] ?? 'OMR');
        $template = trim((string)($product['whatsapp_message'] ?? ''));

        if ($template === '') {
            $template = ($locale === 'ar')
                ? 'أود طلب {title} بسعر {price}'
                : "I'd like to order {title} for {price}";
        }

        $msg = str_replace(
            ['{title}', '{price}'],
            [$title, $price],
            $template
        );

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($msg);
    }

    /**
     * Upsert master row with toggle + bio state.
     */
    public static function saveMaster($employeeId, $companyId, array $data)
    {
        $pdo = Database::getInstance()->getConnection();

        $order = isset($data['section_order']) ? $data['section_order'] : implode(',', self::SECTION_KEYS);
        $orderParts = array_values(array_unique(array_filter(array_map('trim', explode(',', $order)))));
        $orderParts = array_values(array_filter($orderParts, function ($k) {
            return in_array($k, self::SECTION_KEYS, true);
        }));
        foreach (self::SECTION_KEYS as $k) {
            if (!in_array($k, $orderParts, true)) {
                $orderParts[] = $k;
            }
        }
        $order = implode(',', $orderParts);

        $locAddr = mb_substr(trim((string)($data['location_address'] ?? '')), 0, 512);
        $locLabel = mb_substr(trim((string)($data['location_label'] ?? '')), 0, 120);
        $locLat = isset($data['location_lat']) && $data['location_lat'] !== '' && is_numeric($data['location_lat']) ? (float)$data['location_lat'] : null;
        $locLng = isset($data['location_lng']) && $data['location_lng'] !== '' && is_numeric($data['location_lng']) ? (float)$data['location_lng'] : null;

        $tz = trim((string)($data['hours_timezone'] ?? self::DEFAULT_TZ));
        if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
            $tz = self::DEFAULT_TZ;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO employee_card_sections
                (employee_id, company_id, bio_enabled, bio_text, bio_text_ar, services_enabled, gallery_enabled,
                 testimonials_enabled, lead_form_enabled, lead_form_email, offers_enabled,
                 video_enabled, video_url, video_title,
                 location_enabled, location_address, location_lat, location_lng, location_label,
                 products_enabled,
                 hours_enabled, hours_timezone,
                 faq_enabled,
                 section_order)
             VALUES
                (:eid, :cid, :be, :bt, :bta, :se, :ge, :te, :le, :lem, :oe,
                 :ve, :vu, :vt,
                 :loce, :loca, :loclat, :loclng, :loclbl,
                 :pe,
                 :he, :htz,
                 :fe,
                 :ord)
             ON DUPLICATE KEY UPDATE
                bio_enabled = VALUES(bio_enabled),
                bio_text = VALUES(bio_text),
                bio_text_ar = VALUES(bio_text_ar),
                services_enabled = VALUES(services_enabled),
                gallery_enabled = VALUES(gallery_enabled),
                testimonials_enabled = VALUES(testimonials_enabled),
                lead_form_enabled = VALUES(lead_form_enabled),
                lead_form_email = VALUES(lead_form_email),
                offers_enabled = VALUES(offers_enabled),
                video_enabled = VALUES(video_enabled),
                video_url = VALUES(video_url),
                video_title = VALUES(video_title),
                location_enabled = VALUES(location_enabled),
                location_address = VALUES(location_address),
                location_lat = VALUES(location_lat),
                location_lng = VALUES(location_lng),
                location_label = VALUES(location_label),
                products_enabled = VALUES(products_enabled),
                hours_enabled = VALUES(hours_enabled),
                hours_timezone = VALUES(hours_timezone),
                faq_enabled = VALUES(faq_enabled),
                section_order = VALUES(section_order)"
        );
        $videoUrl = trim((string)($data['video_url'] ?? ''));
        if ($videoUrl !== '' && !preg_match('#^https?://#i', $videoUrl)) {
            $videoUrl = 'https://' . $videoUrl;
        }
        $stmt->execute([
            'eid' => $employeeId,
            'cid' => $companyId,
            'be' => !empty($data['bio_enabled']) ? 1 : 0,
            'bt' => (string)($data['bio_text'] ?? ''),
            'bta' => (string)($data['bio_text_ar'] ?? ''),
            'se' => !empty($data['services_enabled']) ? 1 : 0,
            'ge' => !empty($data['gallery_enabled']) ? 1 : 0,
            'te' => !empty($data['testimonials_enabled']) ? 1 : 0,
            'le' => !empty($data['lead_form_enabled']) ? 1 : 0,
            'lem' => trim((string)($data['lead_form_email'] ?? '')),
            'oe' => !empty($data['offers_enabled']) ? 1 : 0,
            've' => !empty($data['video_enabled']) ? 1 : 0,
            'vu' => mb_substr($videoUrl, 0, 1024),
            'vt' => mb_substr(trim((string)($data['video_title'] ?? '')), 0, 200),
            'loce' => !empty($data['location_enabled']) ? 1 : 0,
            'loca' => $locAddr,
            'loclat' => $locLat,
            'loclng' => $locLng,
            'loclbl' => $locLabel,
            'pe' => !empty($data['products_enabled']) ? 1 : 0,
            'he' => !empty($data['hours_enabled']) ? 1 : 0,
            'htz' => $tz,
            'fe' => !empty($data['faq_enabled']) ? 1 : 0,
            'ord' => $order,
        ]);
    }

    // ---- Business Hours --------------------------------------------------

    /**
     * Load the weekly schedule for an employee as an associative array keyed
     * by day ('mon'..'sun'). Missing days fall back to a closed default so
     * callers can always render all 7 rows.
     */
    public static function loadBusinessHours($employeeId)
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT day_of_week, is_closed, open_time, close_time, break_start, break_end
               FROM employee_business_hours
              WHERE employee_id = :eid",
            ['eid' => $employeeId]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day_of_week']] = [
                'is_closed'   => (int)$r['is_closed'] === 1,
                'open_time'   => $r['open_time'],
                'close_time'  => $r['close_time'],
                'break_start' => $r['break_start'],
                'break_end'   => $r['break_end'],
            ];
        }
        $out = [];
        foreach (self::DAY_KEYS as $d) {
            $out[$d] = $byDay[$d] ?? [
                'is_closed'   => true,
                'open_time'   => null,
                'close_time'  => null,
                'break_start' => null,
                'break_end'   => null,
            ];
        }
        return $out;
    }

    /**
     * Bulk-replace the 7-day schedule. Input is an array keyed by day with
     * keys { is_closed, open_time, close_time, break_start?, break_end? }.
     * Blank/invalid times default to closed for that day.
     */
    public static function saveBusinessHours($employeeId, array $schedule)
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO employee_business_hours
                (employee_id, day_of_week, is_closed, open_time, close_time, break_start, break_end)
             VALUES (:eid, :d, :c, :ot, :ct, :bs, :be)
             ON DUPLICATE KEY UPDATE
                is_closed   = VALUES(is_closed),
                open_time   = VALUES(open_time),
                close_time  = VALUES(close_time),
                break_start = VALUES(break_start),
                break_end   = VALUES(break_end)"
        );
        foreach (self::DAY_KEYS as $d) {
            $row = isset($schedule[$d]) && is_array($schedule[$d]) ? $schedule[$d] : [];
            $isClosed = !empty($row['is_closed']);
            $open  = self::normalizeTime($row['open_time']  ?? null);
            $close = self::normalizeTime($row['close_time'] ?? null);
            $bs    = self::normalizeTime($row['break_start'] ?? null);
            $be    = self::normalizeTime($row['break_end']   ?? null);
            // If either open or close is missing, treat as closed to avoid half-configured rows.
            if (!$isClosed && ($open === null || $close === null)) {
                $isClosed = true;
                $open = $close = null;
            }
            if ($isClosed) {
                $open = $close = $bs = $be = null;
            } else {
                // Invalid break range → drop silently.
                if ($bs === null || $be === null) { $bs = $be = null; }
            }
            $stmt->execute([
                'eid' => $employeeId,
                'd'   => $d,
                'c'   => $isClosed ? 1 : 0,
                'ot'  => $open,
                'ct'  => $close,
                'bs'  => $bs,
                'be'  => $be,
            ]);
        }
    }

    private static function normalizeTime($v)
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        // Accept HH:MM or HH:MM:SS
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) {
            return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
        }
        return null;
    }

    /**
     * Compute current open/closed status in the card's configured timezone.
     *
     * Returns: [
     *   'is_open'      => bool,
     *   'on_break'     => bool,
     *   'today_key'    => 'mon'..'sun',
     *   'closes_at'    => 'HH:MM' | null,   (when open)
     *   'opens_at'     => 'HH:MM' | null,   (when closed — same-day reopen or next open day)
     *   'opens_day'    => 'mon'..'sun' | null,
     *   'same_day'     => bool,
     * ]
     */
    public static function computeOpenStatus(array $schedule, $tzName)
    {
        try {
            $tz = new DateTimeZone($tzName ?: self::DEFAULT_TZ);
        } catch (Throwable $e) {
            $tz = new DateTimeZone(self::DEFAULT_TZ);
        }
        $now = new DateTime('now', $tz);
        $isoToday = (int)$now->format('N'); // 1..7
        $todayKey = self::DAY_BY_ISO[$isoToday] ?? 'mon';
        $nowSec = ((int)$now->format('H')) * 3600 + ((int)$now->format('i')) * 60 + (int)$now->format('s');

        $t2s = function ($hms) {
            if (!$hms) return null;
            $p = explode(':', $hms);
            if (count($p) < 2) return null;
            return ((int)$p[0]) * 3600 + ((int)$p[1]) * 60 + (int)($p[2] ?? 0);
        };
        $s2hm = function ($sec) {
            $h = (int)floor($sec / 3600);
            $m = (int)floor(($sec % 3600) / 60);
            return sprintf('%02d:%02d', $h, $m);
        };

        $today = $schedule[$todayKey] ?? null;
        if ($today && empty($today['is_closed'])) {
            $open = $t2s($today['open_time']);
            $close = $t2s($today['close_time']);
            $bs = $t2s($today['break_start'] ?? null);
            $be = $t2s($today['break_end'] ?? null);
            if ($open !== null && $close !== null && $nowSec >= $open && $nowSec < $close) {
                if ($bs !== null && $be !== null && $nowSec >= $bs && $nowSec < $be) {
                    return [
                        'is_open' => false, 'on_break' => true, 'today_key' => $todayKey,
                        'closes_at' => null, 'opens_at' => $s2hm($be), 'opens_day' => $todayKey, 'same_day' => true,
                    ];
                }
                return [
                    'is_open' => true, 'on_break' => false, 'today_key' => $todayKey,
                    'closes_at' => $s2hm($close), 'opens_at' => null, 'opens_day' => null, 'same_day' => true,
                ];
            }
            // Before opening today
            if ($open !== null && $nowSec < $open) {
                return [
                    'is_open' => false, 'on_break' => false, 'today_key' => $todayKey,
                    'closes_at' => null, 'opens_at' => $s2hm($open), 'opens_day' => $todayKey, 'same_day' => true,
                ];
            }
        }
        // Find next open day (up to 7 days ahead)
        for ($i = 1; $i <= 7; $i++) {
            $iso = ((($isoToday - 1) + $i) % 7) + 1;
            $key = self::DAY_BY_ISO[$iso];
            $d = $schedule[$key] ?? null;
            if ($d && empty($d['is_closed']) && !empty($d['open_time'])) {
                return [
                    'is_open' => false, 'on_break' => false, 'today_key' => $todayKey,
                    'closes_at' => null, 'opens_at' => substr($d['open_time'], 0, 5),
                    'opens_day' => $key, 'same_day' => false,
                ];
            }
        }
        // Fully closed every day
        return [
            'is_open' => false, 'on_break' => false, 'today_key' => $todayKey,
            'closes_at' => null, 'opens_at' => null, 'opens_day' => null, 'same_day' => false,
        ];
    }

    /**
     * Build schema.org LocalBusiness `openingHours` strings from a schedule.
     *   e.g. ['Mo-Fr 09:00-17:00', 'Sa 10:00-14:00']
     * Day codes per schema.org: Mo, Tu, We, Th, Fr, Sa, Su.
     */
    public static function buildSchemaOpeningHours(array $schedule)
    {
        $codes = ['mon' => 'Mo', 'tue' => 'Tu', 'wed' => 'We', 'thu' => 'Th', 'fri' => 'Fr', 'sat' => 'Sa', 'sun' => 'Su'];
        $out = [];
        foreach (self::DAY_KEYS as $d) {
            $row = $schedule[$d] ?? null;
            if (!$row || !empty($row['is_closed']) || empty($row['open_time']) || empty($row['close_time'])) {
                continue;
            }
            $open = substr($row['open_time'], 0, 5);
            $close = substr($row['close_time'], 0, 5);
            // Emit one span per day; break times not expressible in schema.org openingHours.
            $out[] = $codes[$d] . ' ' . $open . '-' . $close;
        }
        return $out;
    }

    public static function dayName($dayKey, $locale)
    {
        if ($locale === 'ar') {
            return self::DAY_NAMES_AR[$dayKey] ?? $dayKey;
        }
        return self::DAY_NAMES_EN[$dayKey] ?? $dayKey;
    }

    /**
     * Parse a user-pasted URL into a renderable video spec.
     *
     * Returns an array:
     *   ['type' => 'youtube'|'vimeo'|'file'|'link', 'embed' => '<iframe/src>', 'url' => original]
     * or null if the URL is blank/invalid.
     */
    public static function parseVideoEmbed($url)
    {
        $url = trim((string)$url);
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) return null;

        // YouTube — youtu.be/ID, youtube.com/watch?v=ID, youtube.com/shorts/ID, /embed/ID
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m)) {
            return [
                'type'  => 'youtube',
                'embed' => 'https://www.youtube.com/embed/' . $m[1],
                'url'   => $url,
            ];
        }

        // Vimeo — vimeo.com/NUMERIC_ID (optionally /hash)
        if (preg_match('#vimeo\.com/(?:video/)?(\d{5,})#i', $url, $m)) {
            return [
                'type'  => 'vimeo',
                'embed' => 'https://player.vimeo.com/video/' . $m[1],
                'url'   => $url,
            ];
        }

        // Direct video file
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $path)) {
            return [
                'type'  => 'file',
                'embed' => $url,
                'url'   => $url,
            ];
        }

        // Anything else — render as external "Watch video" link
        return [
            'type'  => 'link',
            'embed' => $url,
            'url'   => $url,
        ];
    }

    // ---- i18n helpers ----------------------------------------------------

    /**
     * Resolve the active locale for the current request.
     * Order: ?lang=  ->  cookie cardify_locale  ->  default (en).
     * Persists ?lang= choice to cookie (7-day expiry) when present.
     */
    public static function resolveLocale()
    {
        $locale = self::DEFAULT_LOCALE;
        if (!empty($_GET['lang']) && in_array($_GET['lang'], self::SUPPORTED_LOCALES, true)) {
            $locale = $_GET['lang'];
            if (!headers_sent()) {
                @setcookie(self::LOCALE_COOKIE, $locale, [
                    'expires' => time() + 7 * 86400,
                    'path' => '/',
                    'samesite' => 'Lax',
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => false,
                ]);
            }
        } elseif (!empty($_COOKIE[self::LOCALE_COOKIE])
            && in_array($_COOKIE[self::LOCALE_COOKIE], self::SUPPORTED_LOCALES, true)) {
            $locale = $_COOKIE[self::LOCALE_COOKIE];
        }
        return $locale;
    }

    public static function isRtl($locale)
    {
        return $locale === 'ar';
    }

    /**
     * Return localized value for $base column, falling back to EN/base.
     */
    public static function tColumn(array $row, $base, $locale)
    {
        $primary = $row[$base . '_' . $locale] ?? null;
        if (is_string($primary) && trim($primary) !== '') return $primary;
        $fallback = $row[$base . '_en'] ?? ($row[$base] ?? '');
        return is_string($fallback) ? $fallback : '';
    }

    public static function loadServicesLocalized($employeeId, $locale)
    {
        $locale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
        $rows = self::loadServices($employeeId);
        if ($locale === 'en' || empty($rows)) return $rows;

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT service_id, title, description
                FROM employee_card_services_i18n
                WHERE locale = ? AND service_id IN ($placeholders)";
        $stmt = Database::getInstance()->getConnection()->prepare($sql);
        $stmt->execute(array_merge([$locale], $ids));
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[$r['service_id']] = $r;
        }
        foreach ($rows as &$r) {
            if (isset($byId[$r['id']])) {
                $tr = $byId[$r['id']];
                if (trim((string)($tr['title'] ?? '')) !== '')       $r['title'] = $tr['title'];
                if (trim((string)($tr['description'] ?? '')) !== '') $r['description'] = $tr['description'];
            }
        }
        return $rows;
    }

    public static function loadTestimonialsLocalized($employeeId, $locale)
    {
        $locale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
        $rows = self::loadTestimonials($employeeId);
        if ($locale === 'en' || empty($rows)) return $rows;

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT testimonial_id, name, quote
                FROM employee_card_testimonials_i18n
                WHERE locale = ? AND testimonial_id IN ($placeholders)";
        $stmt = Database::getInstance()->getConnection()->prepare($sql);
        $stmt->execute(array_merge([$locale], $ids));
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[$r['testimonial_id']] = $r;
        }
        foreach ($rows as &$r) {
            if (isset($byId[$r['id']])) {
                $tr = $byId[$r['id']];
                if (trim((string)($tr['name'] ?? '')) !== '')  $r['name']  = $tr['name'];
                if (trim((string)($tr['quote'] ?? '')) !== '') $r['quote'] = $tr['quote'];
            }
        }
        return $rows;
    }

    public static function upsertServiceTranslation($serviceId, $locale, $title, $description)
    {
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) return false;
        $serviceId = (string)$serviceId;
        if ($serviceId === '') return false;
        $title = trim((string)$title);
        $description = trim((string)$description);
        $pdo = Database::getInstance()->getConnection();
        if ($title === '' && $description === '') {
            $pdo->prepare("DELETE FROM employee_card_services_i18n WHERE service_id = ? AND locale = ?")
                ->execute([$serviceId, $locale]);
            return true;
        }
        if ($title === '') $title = '—';
        $stmt = $pdo->prepare(
            "INSERT INTO employee_card_services_i18n (service_id, locale, title, description)
             VALUES (:sid, :loc, :t, :d)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)"
        );
        $stmt->execute([
            'sid' => $serviceId,
            'loc' => $locale,
            't'   => mb_substr($title, 0, 255),
            'd'   => mb_substr($description, 0, 2000),
        ]);
        return true;
    }

    public static function upsertTestimonialTranslation($testimonialId, $locale, $name, $quote)
    {
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) return false;
        $testimonialId = (string)$testimonialId;
        if ($testimonialId === '') return false;
        $name = trim((string)$name);
        $quote = trim((string)$quote);
        $pdo = Database::getInstance()->getConnection();
        if ($name === '' && $quote === '') {
            $pdo->prepare("DELETE FROM employee_card_testimonials_i18n WHERE testimonial_id = ? AND locale = ?")
                ->execute([$testimonialId, $locale]);
            return true;
        }
        if ($name === '')  $name  = '—';
        if ($quote === '') $quote = '—';
        $stmt = $pdo->prepare(
            "INSERT INTO employee_card_testimonials_i18n (testimonial_id, locale, name, quote)
             VALUES (:tid, :loc, :n, :q)
             ON DUPLICATE KEY UPDATE name = VALUES(name), quote = VALUES(quote)"
        );
        $stmt->execute([
            'tid' => $testimonialId,
            'loc' => $locale,
            'n'   => mb_substr($name, 0, 255),
            'q'   => mb_substr($quote, 0, 2000),
        ]);
        return true;
    }

    public static function addService($employeeId, $icon, $title, $description)
    {
        $title = trim((string)$title);
        if ($title === '') return false;
        $id = self::uuid();
        Database::getInstance()->insert('employee_card_services', [
            'id' => $id,
            'employee_id' => $employeeId,
            'icon' => trim((string)$icon) ?: 'fa-solid fa-star',
            'title' => mb_substr($title, 0, 255),
            'description' => mb_substr(trim((string)$description), 0, 2000),
            'position' => 0,
        ]);
        return $id;
    }

    public static function deleteService($employeeId, $serviceId)
    {
        Database::getInstance()->getConnection()
            ->prepare("DELETE FROM employee_card_services WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $serviceId, 'eid' => $employeeId]);
    }

    public static function addTestimonial($employeeId, $name, $quote, $photoPath = null, $opts = [])
    {
        $name = trim((string)$name);
        $quote = trim((string)$quote);
        if ($name === '' || $quote === '') return false;
        $id = self::uuid();

        $status = isset($opts['status']) && in_array($opts['status'], ['pending', 'approved', 'rejected'], true)
            ? $opts['status']
            : 'approved'; // legacy default = owner-added (already approved)
        $submittedByVisitor = !empty($opts['submitted_by_visitor']) ? 1 : 0;
        $rating = isset($opts['rating']) ? (int)$opts['rating'] : null;
        if ($rating !== null) {
            $rating = max(0, min(5, $rating));
        }
        $visitorEmail = isset($opts['visitor_email']) ? trim((string)$opts['visitor_email']) : null;
        if ($visitorEmail === '') $visitorEmail = null;
        $submitterIp = isset($opts['submitter_ip']) ? mb_substr((string)$opts['submitter_ip'], 0, 64) : null;

        Database::getInstance()->insert('employee_card_testimonials', [
            'id' => $id,
            'employee_id' => $employeeId,
            'name' => mb_substr($name, 0, 255),
            'photo_path' => $photoPath,
            'quote' => mb_substr($quote, 0, 2000),
            'position' => 0,
            'status' => $status,
            'submitted_by_visitor' => $submittedByVisitor,
            'visitor_email' => $visitorEmail,
            'rating' => $rating,
            'submitter_ip' => $submitterIp,
        ]);
        return $id;
    }

    public static function deleteTestimonial($employeeId, $tid)
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT photo_path FROM employee_card_testimonials WHERE id = :id AND employee_id = :eid",
            ['id' => $tid, 'eid' => $employeeId]
        );
        if ($existing && !empty($existing['photo_path'])) {
            self::unlinkUpload($existing['photo_path']);
        }
        $db->getConnection()
            ->prepare("DELETE FROM employee_card_testimonials WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $tid, 'eid' => $employeeId]);
    }

    public static function addGalleryImage($employeeId, $filePath, $caption = '')
    {
        $id = self::uuid();
        Database::getInstance()->insert('employee_card_gallery', [
            'id' => $id,
            'employee_id' => $employeeId,
            'file_path' => $filePath,
            'caption' => mb_substr(trim((string)$caption), 0, 255),
            'position' => 0,
        ]);
        return $id;
    }

    public static function deleteGalleryImage($employeeId, $imgId)
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT file_path FROM employee_card_gallery WHERE id = :id AND employee_id = :eid",
            ['id' => $imgId, 'eid' => $employeeId]
        );
        if ($existing && !empty($existing['file_path'])) {
            self::unlinkUpload($existing['file_path']);
        }
        $db->getConnection()
            ->prepare("DELETE FROM employee_card_gallery WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $imgId, 'eid' => $employeeId]);
    }

    /**
     * Validate + move a single uploaded image. Returns relative web path or null on failure.
     */
    public static function handleImageUpload($file, $employeeId, $subdir, &$error = null)
    {
        if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $error = 'No file uploaded';
            return null;
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $error = 'Upload error code ' . $file['error'];
            return null;
        }
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            $error = 'File larger than 5 MB';
            return null;
        }

        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        if (!$mime || !in_array($mime, self::ALLOWED_MIME, true)) {
            $error = 'Only JPG, PNG, WebP allowed';
            return null;
        }

        if (!in_array($subdir, ['gallery', 'testimonials', 'products'], true)) {
            $error = 'Invalid upload target';
            return null;
        }

        $baseDir = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
        $relDir = 'uploads/cards/' . $employeeId . '/' . $subdir;
        $absDir = $baseDir . '/' . $relDir;
        if (!is_dir($absDir)) {
            if (!mkdir($absDir, 0755, true) && !is_dir($absDir)) {
                $error = 'Failed to create upload directory';
                return null;
            }
        }

        $ext = self::EXT_BY_MIME[$mime];
        try {
            $random = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $random = substr(sha1(uniqid('', true)), 0, 16);
        }
        $filename = $random . '.' . $ext;
        $absPath = $absDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            $error = 'Failed to store uploaded file';
            return null;
        }
        @chmod($absPath, 0644);

        return '/' . $relDir . '/' . $filename;
    }

    /**
     * Handle multi-file input (name="gallery_images[]") respecting per-employee cap.
     */
    public static function handleGalleryUpload($filesField, $employeeId, &$errors)
    {
        $errors = [];
        $stored = [];
        if (empty($filesField) || empty($filesField['name'])) {
            return $stored;
        }

        $existing = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) AS c FROM employee_card_gallery WHERE employee_id = :eid",
            ['eid' => $employeeId]
        );
        $remaining = self::MAX_GALLERY_IMAGES - (int)($existing['c'] ?? 0);
        if ($remaining <= 0) {
            $errors[] = 'Gallery full (max ' . self::MAX_GALLERY_IMAGES . ' images).';
            return $stored;
        }

        $names = (array)$filesField['name'];
        for ($i = 0; $i < count($names) && count($stored) < $remaining; $i++) {
            $one = [
                'name' => $filesField['name'][$i] ?? '',
                'type' => $filesField['type'][$i] ?? '',
                'tmp_name' => $filesField['tmp_name'][$i] ?? '',
                'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $filesField['size'][$i] ?? 0,
            ];
            if ($one['error'] === UPLOAD_ERR_NO_FILE) continue;
            $err = null;
            $path = self::handleImageUpload($one, $employeeId, 'gallery', $err);
            if ($path) {
                self::addGalleryImage($employeeId, $path, '');
                $stored[] = $path;
            } else {
                $errors[] = ($one['name'] ?: 'file') . ': ' . $err;
            }
        }
        return $stored;
    }

    /**
     * Rate-limit lead submissions — max 5 per IP per hour per employee.
     */
    public static function canSubmitLead($employeeId, $ip)
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) AS c FROM employee_card_leads
             WHERE employee_id = :eid AND ip = :ip AND created_at > (NOW() - INTERVAL 1 HOUR)",
            ['eid' => $employeeId, 'ip' => $ip]
        );
        return (int)($row['c'] ?? 0) < 5;
    }

    public static function recordLead($employeeId, $companyId, array $data, $ip)
    {
        $id = self::uuid();
        Database::getInstance()->insert('employee_card_leads', [
            'id' => $id,
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'name' => mb_substr(trim((string)($data['name'] ?? '')), 0, 255),
            'email' => mb_substr(trim((string)($data['email'] ?? '')), 0, 255),
            'phone' => mb_substr(trim((string)($data['phone'] ?? '')), 0, 50),
            'message' => mb_substr(trim((string)($data['message'] ?? '')), 0, 4000),
            'ip' => mb_substr((string)$ip, 0, 64),
        ]);
        return $id;
    }

    // ---- FAQ section -----------------------------------------------------

    /**
     * Load all FAQs for an employee (ordered by position, created_at).
     */
    public static function loadFaqs($employeeId)
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM employee_card_faqs WHERE employee_id = :eid ORDER BY position, created_at",
            ['eid' => $employeeId]
        );
    }

    public static function addFaq($employeeId, $companyId, $question, $answer, $questionAr = '', $answerAr = '')
    {
        $question = trim((string)$question);
        $answer   = trim((string)$answer);
        if ($question === '' || $answer === '') return false;

        $questionAr = trim((string)$questionAr);
        $answerAr   = trim((string)$answerAr);

        $id = self::uuid();
        Database::getInstance()->insert('employee_card_faqs', [
            'id' => $id,
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'question' => mb_substr($question, 0, 500),
            'answer' => $answer,
            'question_ar' => $questionAr !== '' ? mb_substr($questionAr, 0, 500) : null,
            'answer_ar' => $answerAr !== '' ? $answerAr : null,
            'position' => 0,
        ]);
        return $id;
    }

    public static function deleteFaq($employeeId, $faqId)
    {
        Database::getInstance()->getConnection()
            ->prepare("DELETE FROM employee_card_faqs WHERE id = :id AND employee_id = :eid")
            ->execute(['id' => $faqId, 'eid' => $employeeId]);
    }

    /**
     * Reorder FAQs by updating the position column. $orderedIds is the new order (top → bottom).
     */
    public static function reorderFaqs($employeeId, array $orderedIds)
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE employee_card_faqs SET position = :pos WHERE id = :id AND employee_id = :eid"
        );
        $pos = 0;
        foreach ($orderedIds as $fid) {
            $fid = trim((string)$fid);
            if ($fid === '') continue;
            $stmt->execute(['pos' => $pos++, 'id' => $fid, 'eid' => $employeeId]);
        }
    }

    /**
     * Return list with localized question/answer (AR overlay with EN fallback).
     */
    public static function loadFaqsLocalized($employeeId, $locale)
    {
        $locale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
        $rows = self::loadFaqs($employeeId);
        if ($locale === 'en' || empty($rows)) return $rows;
        foreach ($rows as &$r) {
            $qAr = trim((string)($r['question_ar'] ?? ''));
            $aAr = trim((string)($r['answer_ar'] ?? ''));
            if ($qAr !== '') $r['question'] = $qAr;
            if ($aAr !== '') $r['answer']   = $aAr;
        }
        return $rows;
    }

    /**
     * Render a bio block -> safe HTML (escape, then newlines to <br>, then **bold**).
     */
    public static function renderBioHtml($text)
    {
        $escaped = htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        return nl2br($escaped);
    }

    private static function unlinkUpload($relPath)
    {
        $baseDir = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
        $abs = $baseDir . '/' . ltrim($relPath, '/');
        $realBase = realpath($baseDir . '/uploads/cards');
        $realAbs = realpath($abs);
        if ($realBase && $realAbs && strpos($realAbs, $realBase) === 0 && is_file($realAbs)) {
            @unlink($realAbs);
        }
    }

    private static function uuid()
    {
        try {
            $data = random_bytes(16);
        } catch (Throwable $e) {
            $data = openssl_random_pseudo_bytes(16);
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
