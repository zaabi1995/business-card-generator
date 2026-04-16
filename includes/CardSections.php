<?php
/**
 * CardSections - Helper for public employee card sections
 *
 * Loads and saves section data (bio, services, gallery, testimonials, lead form)
 * for the public digital card page.
 */

class CardSections
{
    const SECTION_KEYS = ['bio', 'services', 'gallery', 'testimonials', 'lead_form'];
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
                'services_enabled' => 0,
                'gallery_enabled' => 0,
                'testimonials_enabled' => 0,
                'lead_form_enabled' => 0,
                'lead_form_email' => '',
                'section_order' => implode(',', self::SECTION_KEYS),
            ];
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

        $stmt = $pdo->prepare(
            "INSERT INTO employee_card_sections
                (employee_id, company_id, bio_enabled, bio_text, services_enabled, gallery_enabled,
                 testimonials_enabled, lead_form_enabled, lead_form_email, section_order)
             VALUES
                (:eid, :cid, :be, :bt, :se, :ge, :te, :le, :lem, :ord)
             ON DUPLICATE KEY UPDATE
                bio_enabled = VALUES(bio_enabled),
                bio_text = VALUES(bio_text),
                services_enabled = VALUES(services_enabled),
                gallery_enabled = VALUES(gallery_enabled),
                testimonials_enabled = VALUES(testimonials_enabled),
                lead_form_enabled = VALUES(lead_form_enabled),
                lead_form_email = VALUES(lead_form_email),
                section_order = VALUES(section_order)"
        );
        $stmt->execute([
            'eid' => $employeeId,
            'cid' => $companyId,
            'be' => !empty($data['bio_enabled']) ? 1 : 0,
            'bt' => (string)($data['bio_text'] ?? ''),
            'se' => !empty($data['services_enabled']) ? 1 : 0,
            'ge' => !empty($data['gallery_enabled']) ? 1 : 0,
            'te' => !empty($data['testimonials_enabled']) ? 1 : 0,
            'le' => !empty($data['lead_form_enabled']) ? 1 : 0,
            'lem' => trim((string)($data['lead_form_email'] ?? '')),
            'ord' => $order,
        ]);
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

        if (!in_array($subdir, ['gallery', 'testimonials'], true)) {
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
