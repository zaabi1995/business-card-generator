<?php
/**
 * GET /api/appointment/slots.php?eid=<employee_id>&date=<yyyy-mm-dd>
 *
 * Returns JSON: { success: true, slots: [{start, end, label}, ...] }
 * Public — no auth.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Appointments.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $eid = trim($_GET['eid'] ?? '');
    $date = trim($_GET['date'] ?? '');

    if ($eid === '' || $date === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'eid and date required']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'date must be YYYY-MM-DD']);
        exit;
    }

    $db = Database::getInstance();
    $employee = $db->fetchOne(
        "SELECT id, company_id FROM employees WHERE id = :id",
        ['id' => $eid]
    );
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    $settings = Appointments::loadSettings($employee['id'], $employee['company_id']);
    if (empty($settings['enabled'])) {
        echo json_encode(['success' => true, 'slots' => [], 'enabled' => false]);
        exit;
    }

    $slots = Appointments::computeSlots($settings, $date);
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'duration_minutes' => (int)$settings['duration_minutes'],
        'slots' => $slots,
    ]);
} catch (Throwable $e) {
    error_log('api/appointment/slots.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
