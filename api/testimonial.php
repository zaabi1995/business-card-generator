<?php
/**
 * POST /api/testimonial.php
 * Body (multipart): employee_id, name, email (opt), rating (0-5, opt), quote, photo (file, opt)
 *
 * Public visitor testimonial submission. Creates a row with status='pending' and
 * notifies the card owner. Owner moderates from /company/.../employee admin page.
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardSections.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $employeeId = trim($_POST['employee_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $quote = trim($_POST['quote'] ?? '');
    $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? (int)$_POST['rating'] : null;

    // Honeypot
    if (!empty($_POST['website_url'])) {
        echo json_encode(['success' => true]); // silently drop bots
        exit;
    }

    if ($employeeId === '' || $name === '' || $quote === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name and testimonial are required']);
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email']);
        exit;
    }
    if ($rating !== null && ($rating < 0 || $rating > 5)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Rating must be 0-5']);
        exit;
    }

    $db = Database::getInstance();
    $employee = $db->fetchOne(
        "SELECT e.* FROM employees e WHERE e.id = :id",
        ['id' => $employeeId]
    );
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    $master = CardSections::loadMaster($employeeId, $employee['company_id']);
    if (empty($master['testimonials_enabled'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Testimonials disabled for this card']);
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!CardSections::canSubmitTestimonial($ip)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions, try again later']);
        exit;
    }

    // Optional photo upload — reuses CardSections::handleImageUpload (DRY)
    $photoPath = null;
    if (!empty($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
        $err = null;
        $photoPath = CardSections::handleImageUpload($_FILES['photo'], $employeeId, 'testimonials', $err);
        if ($err) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Photo upload: ' . $err]);
            exit;
        }
    }

    $tid = CardSections::addTestimonial(
        $employeeId,
        $name,
        $quote,
        $photoPath,
        [
            'status' => 'pending',
            'submitted_by_visitor' => true,
            'visitor_email' => $email,
            'rating' => $rating,
            'submitter_ip' => $ip,
        ]
    );

    if (!$tid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Failed to save testimonial']);
        exit;
    }

    // Email the card owner (non-fatal)
    try {
        require_once INCLUDES_DIR . '/Mailer.php';
        $toEmail = $employee['email'] ?? '';
        if ($toEmail) {
            $ownerName = $employee['name_en'] ?? ($employee['name'] ?? 'there');
            $subject = 'New testimonial pending review — ' . $name;
            $stars = $rating ? str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) : '(no rating)';
            $lines = [];
            $lines[] = '<p>Hi ' . htmlspecialchars($ownerName) . ',</p>';
            $lines[] = '<p>A visitor just submitted a testimonial on your Cardify card. Sign in to approve or reject it.</p>';
            $lines[] = '<ul>';
            $lines[] = '<li><strong>Name:</strong> ' . htmlspecialchars($name) . '</li>';
            if ($email) $lines[] = '<li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>';
            $lines[] = '<li><strong>Rating:</strong> ' . $stars . '</li>';
            $lines[] = '<li><strong>Quote:</strong><br>' . nl2br(htmlspecialchars($quote)) . '</li>';
            $lines[] = '</ul>';
            $lines[] = '<p style="color:#888;font-size:12px;">Sent by Cardify &middot; ' . date('Y-m-d H:i') . '</p>';
            Mailer::send($toEmail, $subject, implode("\n", $lines));
        }
    } catch (Throwable $e) {
        error_log('api/testimonial.php mailer: ' . $e->getMessage());
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('api/testimonial.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
