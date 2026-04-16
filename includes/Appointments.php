<?php
/**
 * Appointments — slot computation + booking helpers.
 * Mirrors the style of CardSections.php.
 */
class Appointments {
    const DEFAULT_SETTINGS = [
        'enabled' => 0,
        'duration_minutes' => 30,
        'buffer_minutes' => 0,
        'timezone' => 'Asia/Muscat',
        'available_days' => 'mon,tue,wed,thu',
        'available_start' => '09:00:00',
        'available_end' => '17:00:00',
        'max_advance_days' => 30,
        'notification_email' => null,
    ];

    const DAY_KEYS = ['sun','mon','tue','wed','thu','fri','sat'];

    public static function loadSettings($employeeId, $companyId) {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT * FROM employee_appointment_settings WHERE employee_id = :id",
            ['id' => $employeeId]
        );
        if (!$row) {
            return array_merge(self::DEFAULT_SETTINGS, [
                'employee_id' => $employeeId,
                'company_id' => $companyId,
            ]);
        }
        return $row;
    }

    public static function saveSettings($employeeId, $companyId, array $data) {
        $db = Database::getInstance();
        $payload = [
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'duration_minutes' => max(5, min(480, (int)($data['duration_minutes'] ?? 30))),
            'buffer_minutes' => max(0, min(240, (int)($data['buffer_minutes'] ?? 0))),
            'timezone' => trim($data['timezone'] ?? 'Asia/Muscat') ?: 'Asia/Muscat',
            'available_days' => self::sanitizeDays($data['available_days'] ?? []),
            'available_start' => self::sanitizeTime($data['available_start'] ?? '09:00'),
            'available_end' => self::sanitizeTime($data['available_end'] ?? '17:00'),
            'max_advance_days' => max(1, min(365, (int)($data['max_advance_days'] ?? 30))),
            'notification_email' => trim($data['notification_email'] ?? '') ?: null,
        ];

        $existing = $db->fetchOne(
            "SELECT employee_id FROM employee_appointment_settings WHERE employee_id = :id",
            ['id' => $employeeId]
        );
        if ($existing) {
            $db->update('employee_appointment_settings', $payload, 'employee_id = :id', ['id' => $employeeId]);
        } else {
            $payload['employee_id'] = $employeeId;
            $payload['company_id'] = $companyId;
            $db->insert('employee_appointment_settings', $payload);
        }
        return self::loadSettings($employeeId, $companyId);
    }

    private static function sanitizeDays($days) {
        if (is_string($days)) {
            $days = array_map('trim', explode(',', $days));
        }
        if (!is_array($days)) return 'mon,tue,wed,thu';
        $valid = array_values(array_intersect(self::DAY_KEYS, array_map('strtolower', $days)));
        return $valid ? implode(',', $valid) : 'mon,tue,wed,thu';
    }

    private static function sanitizeTime($t) {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($t), $m)) {
            $h = max(0, min(23, (int)$m[1]));
            $mn = max(0, min(59, (int)$m[2]));
            return sprintf('%02d:%02d:00', $h, $mn);
        }
        return '09:00:00';
    }

    /**
     * Compute available slots for a given date.
     * Returns array of ['start' => ISO8601, 'end' => ISO8601, 'label' => '09:30 AM'].
     */
    public static function computeSlots($settings, $dateYmd) {
        if (empty($settings['enabled'])) return [];

        try {
            $tz = new DateTimeZone($settings['timezone'] ?? 'Asia/Muscat');
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Muscat');
        }

        $date = DateTime::createFromFormat('!Y-m-d', $dateYmd, $tz);
        if (!$date) return [];

        // Bounds check
        $today = new DateTime('today', $tz);
        $maxAdvance = (int)($settings['max_advance_days'] ?? 30);
        $maxDate = (clone $today)->modify("+{$maxAdvance} days");
        if ($date < $today || $date > $maxDate) return [];

        // Day-of-week filter
        $dayKey = strtolower(substr($date->format('D'), 0, 3));
        $allowedDays = explode(',', $settings['available_days'] ?? '');
        if (!in_array($dayKey, $allowedDays, true)) return [];

        $duration = max(5, (int)$settings['duration_minutes']);
        $buffer = max(0, (int)$settings['buffer_minutes']);
        $step = $duration + $buffer;

        list($sH, $sM) = array_pad(array_map('intval', explode(':', $settings['available_start'])), 2, 0);
        list($eH, $eM) = array_pad(array_map('intval', explode(':', $settings['available_end'])), 2, 0);

        $slotStart = (clone $date)->setTime($sH, $sM, 0);
        $dayEnd = (clone $date)->setTime($eH, $eM, 0);
        $now = new DateTime('now', $tz);

        // Existing non-cancelled appointments for this employee on this date
        $db = Database::getInstance();
        $dayStartStr = $slotStart->format('Y-m-d 00:00:00');
        $dayEndStr = $slotStart->format('Y-m-d 23:59:59');
        $taken = $db->fetchAll(
            "SELECT slot_start, slot_end FROM appointments
             WHERE employee_id = :eid AND status != 'cancelled'
               AND slot_start >= :s AND slot_start <= :e",
            ['eid' => $settings['employee_id'], 's' => $dayStartStr, 'e' => $dayEndStr]
        );

        $slots = [];
        while (true) {
            $slotEnd = (clone $slotStart)->modify("+{$duration} minutes");
            if ($slotEnd > $dayEnd) break;
            if ($slotStart < $now) {
                $slotStart->modify("+{$step} minutes");
                continue;
            }
            // Overlap check
            $overlaps = false;
            $sStr = $slotStart->format('Y-m-d H:i:s');
            $eStr = $slotEnd->format('Y-m-d H:i:s');
            foreach ($taken as $t) {
                if ($sStr < $t['slot_end'] && $eStr > $t['slot_start']) {
                    $overlaps = true; break;
                }
            }
            if (!$overlaps) {
                $slots[] = [
                    'start' => $sStr,
                    'end' => $eStr,
                    'label' => $slotStart->format('g:i A'),
                ];
            }
            $slotStart->modify("+{$step} minutes");
        }
        return $slots;
    }

    public static function generateUuid() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
