<?php
/**
 * Check if employee exists by email
 * Used by portal to detect existing employees for update/reprint requests
 * Also returns last request data for easy reordering
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = sanitizeEmail($input['email'] ?? '');
    $companyId = $input['company_id'] ?? '';
    
    if (empty($email) || empty($companyId)) {
        echo json_encode(['exists' => false]);
        exit;
    }
    
    $db = Database::getInstance();
    $employee = findEmployeeByEmail($email, $companyId);
    
    // Also check for previous requests (for employees who haven't been fully created yet)
    $lastRequest = $db->fetchOne(
        "SELECT * FROM card_requests 
         WHERE email = :email AND company_id = :cid 
         ORDER BY submitted_at DESC LIMIT 1",
        ['email' => $email, 'cid' => $companyId]
    );
    
    // Count total cards generated for this employee
    $cardCount = 0;
    if ($employee) {
        $countResult = $db->fetchOne(
            "SELECT COUNT(*) as count FROM generated_cards WHERE employee_id = :eid AND company_id = :cid",
            ['eid' => $employee['id'], 'cid' => $companyId]
        );
        $cardCount = (int)($countResult['count'] ?? 0);
    }
    
    // Count total requests
    $requestCount = 0;
    $requestCountResult = $db->fetchOne(
        "SELECT COUNT(*) as count FROM card_requests WHERE email = :email AND company_id = :cid",
        ['email' => $email, 'cid' => $companyId]
    );
    $requestCount = (int)($requestCountResult['count'] ?? 0);
    
    if ($employee) {
        // Return employee data
        echo json_encode([
            'exists' => true,
            'source' => 'employee',
            'cards_generated' => $cardCount,
            'total_requests' => $requestCount,
            'employee' => [
                'id' => $employee['id'],
                'name' => $employee['name_en'] ?: $employee['name_ar'] ?: '',
                'name_en' => $employee['name_en'] ?? '',
                'name_ar' => $employee['name_ar'] ?? '',
                'position_en' => $employee['position_en'] ?? '',
                'position_ar' => $employee['position_ar'] ?? '',
                'phone' => $employee['phone'] ?? '',
                'phone_ar' => $employee['phone_ar'] ?? '',
                'mobile' => $employee['mobile'] ?? '',
                'mobile_ar' => $employee['mobile_ar'] ?? '',
                'website' => $employee['website'] ?? '',
                'website_ar' => $employee['website_ar'] ?? '',
                'address_en' => $employee['address_en'] ?? '',
                'address_ar' => $employee['address_ar'] ?? ''
            ]
        ]);
    } elseif ($lastRequest) {
        // Return last request data (employee not created yet, but has previous request)
        echo json_encode([
            'exists' => true,
            'source' => 'request',
            'cards_generated' => 0,
            'total_requests' => $requestCount,
            'last_request_status' => $lastRequest['status'],
            'employee' => [
                'id' => null,
                'name' => $lastRequest['name_en'] ?: $lastRequest['name_ar'] ?: '',
                'name_en' => $lastRequest['name_en'] ?? '',
                'name_ar' => $lastRequest['name_ar'] ?? '',
                'position_en' => $lastRequest['position_en'] ?? '',
                'position_ar' => $lastRequest['position_ar'] ?? '',
                'phone' => $lastRequest['phone'] ?? '',
                'phone_ar' => $lastRequest['phone_ar'] ?? '',
                'mobile' => $lastRequest['mobile'] ?? '',
                'mobile_ar' => $lastRequest['mobile_ar'] ?? '',
                'website' => $lastRequest['website'] ?? '',
                'website_ar' => $lastRequest['website_ar'] ?? '',
                'address_en' => $lastRequest['address_en'] ?? '',
                'address_ar' => $lastRequest['address_ar'] ?? ''
            ]
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    
} catch (Exception $e) {
    echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
}
