<?php
/**
 * POST /api/lead.php
 * Body: employee_id, name, email, phone, message
 *
 * Records a lead tied to an employee's public card and emails the card owner.
 * Public endpoint, rate-limited per IP.
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardSections.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    if (!isSameOriginRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cross-origin POST blocked']);
        exit;
    }

    $employeeId = trim($_POST['employee_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Honeypot
    if (!empty($_POST['website_url'])) {
        echo json_encode(['success' => true]); // silently drop bots
        exit;
    }

    if ($employeeId === '' || $name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name is required']);
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

    $master = CardSections::loadMaster($employeeId, $employee['company_id']);
    if (empty($master['lead_form_enabled'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Lead form disabled']);
        exit;
    }

    // Client IP via shared helper (Codex round-2 Finding 3, match writes).
    $ip = getClientIp();
    if (!CardSections::canSubmitLead($employeeId, $ip)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions, try again later']);
        exit;
    }

    CardSections::recordLead($employeeId, $employee['company_id'], [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'message' => $message,
    ], $ip);

    // Email the card owner (non-fatal on failure)
    try {
        require_once INCLUDES_DIR . '/Mailer.php';
        $toEmail = !empty($master['lead_form_email']) ? $master['lead_form_email'] : ($employee['email'] ?? '');
        if ($toEmail) {
            $subject = 'New lead from your Cardify card, ' . $name;
            $ownerName = $employee['name_en'] ?? ($employee['name'] ?? 'there');
            $lines = [];
            $lines[] = '<p>Hi ' . htmlspecialchars($ownerName) . ',</p>';
            $lines[] = '<p>Someone just contacted you through your Cardify digital business card:</p>';
            $lines[] = '<ul>';
            $lines[] = '<li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>';
            if ($email) $lines[] = '<li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>';
            if ($phone) $lines[] = '<li><strong>Phone:</strong> ' . htmlspecialchars($phone) . '</li>';
            if ($message) $lines[] = '<li><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message)) . '</li>';
            $lines[] = '</ul>';
            $lines[] = '<p style="color:#888;font-size:12px;">Sent by Cardify &middot; ' . date('Y-m-d H:i') . '</p>';
            Mailer::send($toEmail, $subject, implode("\n", $lines));
        }
    } catch (Throwable $e) {
        error_log('api/lead.php mailer: ' . $e->getMessage());
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('api/lead.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
