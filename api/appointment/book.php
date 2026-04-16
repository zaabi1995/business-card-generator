<?php
/**
 * POST /api/appointment/book.php
 * Body: employee_id, slot_start (Y-m-d H:i:s), name, email, phone, notes
 *
 * Books an appointment slot, emails the card owner. Public endpoint.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Appointments.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $employeeId = trim($_POST['employee_id'] ?? '');
    $slotStart  = trim($_POST['slot_start'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    // Honeypot
    if (!empty($_POST['website_url'])) {
        echo json_encode(['success' => true]); // silently drop bots
        exit;
    }

    if ($employeeId === '' || $slotStart === '' || $name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name, employee, and slot required']);
        exit;
    }
    if ($email === '' && $phone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide email or phone']);
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email']);
        exit;
    }
    if (strlen($notes) > 4000) $notes = substr($notes, 0, 4000);

    $db = Database::getInstance();
    $employee = $db->fetchOne(
        "SELECT e.*, c.name AS _company_name FROM employees e
         JOIN companies c ON c.id = e.company_id
         WHERE e.id = :id",
        ['id' => $employeeId]
    );
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    $settings = Appointments::loadSettings($employee['id'], $employee['company_id']);
    if (empty($settings['enabled'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Appointment booking disabled']);
        exit;
    }

    // Validate slot is still available (re-compute slot list for that day)
    $datePart = substr($slotStart, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid slot_start']);
        exit;
    }
    $slots = Appointments::computeSlots($settings, $datePart);
    $match = null;
    foreach ($slots as $s) {
        if ($s['start'] === $slotStart) { $match = $s; break; }
    }
    if (!$match) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Slot no longer available']);
        exit;
    }

    $id = Appointments::generateUuid();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $db->insert('appointments', [
        'id' => $id,
        'employee_id' => $employee['id'],
        'company_id' => $employee['company_id'],
        'visitor_name' => $name,
        'visitor_email' => $email ?: null,
        'visitor_phone' => $phone ?: null,
        'visitor_notes' => $notes ?: null,
        'slot_start' => $match['start'],
        'slot_end' => $match['end'],
        'status' => 'pending',
        'ip' => $ip,
    ]);

    // Email owner (non-fatal on failure)
    try {
        require_once INCLUDES_DIR . '/Mailer.php';
        $toEmail = !empty($settings['notification_email']) ? $settings['notification_email'] : ($employee['email'] ?? '');
        if ($toEmail) {
            $ownerName = $employee['name_en'] ?? ($employee['name'] ?? 'there');
            $tz = $settings['timezone'] ?? 'Asia/Muscat';
            try {
                $dt = new DateTime($match['start'], new DateTimeZone($tz));
                $dateLabel = $dt->format('l, M j, Y');
                $timeLabel = $dt->format('g:i A');
            } catch (Throwable $e) {
                $dateLabel = $datePart; $timeLabel = $match['label'] ?? '';
            }
            $subject = 'New appointment booked — ' . $name . ' on ' . $dateLabel . ' at ' . $timeLabel;

            $lines = [];
            $lines[] = '<p>Hi ' . htmlspecialchars($ownerName) . ',</p>';
            $lines[] = '<p>You have a new appointment request through your Cardify digital business card:</p>';
            $lines[] = '<ul>';
            $lines[] = '<li><strong>When:</strong> ' . htmlspecialchars($dateLabel . ' at ' . $timeLabel) . ' (' . htmlspecialchars($tz) . ')</li>';
            $lines[] = '<li><strong>Duration:</strong> ' . (int)$settings['duration_minutes'] . ' minutes</li>';
            $lines[] = '<li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>';
            if ($email) $lines[] = '<li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>';
            if ($phone) $lines[] = '<li><strong>Phone:</strong> ' . htmlspecialchars($phone) . '</li>';
            if ($notes) $lines[] = '<li><strong>Notes:</strong><br>' . nl2br(htmlspecialchars($notes)) . '</li>';
            $lines[] = '</ul>';
            $lines[] = '<p><a href="https://cardify.om/admin/appointments.php" style="display:inline-block;background:#2563eb;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;">Manage appointments</a></p>';
            $lines[] = '<p style="color:#888;font-size:12px;">Sent by Cardify &middot; ' . date('Y-m-d H:i') . '</p>';
            Mailer::send($toEmail, $subject, implode("\n", $lines));
        }
    } catch (Throwable $e) {
        error_log('api/appointment/book.php mailer: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('api/appointment/book.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
