<?php
/**
 * Shared approve chain for card requests.
 *
 * Extracted verbatim (in behaviour) from admin/requests.php's
 * `case 'approve':` so both the logged-in admin flow (requests list)
 * and the scoped magic-link flow (approve-request / one-tap-approve)
 * run identical employee-create/update + status='approved' logic.
 *
 * Callers own the redirect to batch_generate; this function only
 * mutates data and stashes $_SESSION['pending_card_generation'].
 *
 * A later task adds the print-order placement inside this function
 * guarded by $sendToPrint (see the marked line below).
 */

/**
 * @param array   $request    card_requests row (already scoped to the company)
 * @param array   $company    companies row (for company_en/company_ar fallbacks)
 * @param string  $companyId  the request's company_id (scope for employee ops)
 * @param ?string $reviewedBy user_id / admin identifier writing reviewed_by
 * @param bool    $sendToPrint whether to also place a print order (later task)
 * @return array{success:bool,employee_id:?string,status_msg:string,quantity:int,error:?string,send_to_print:bool}
 */
function approveRequestChain(array $request, array $company, string $companyId, ?string $reviewedBy, bool $sendToPrint = false): array
{
    $db = Database::getInstance();

    $companyName = $company['name_en'] ?? $company['name'] ?? 'Company';

    // Get request type (new, update, reprint)
    $requestType = $request['request_type'] ?? 'new';
    $quantityRequested = max(1, (int)($request['quantity_requested'] ?? 1));

    // Check if employee already exists with this email
    $existingEmployee = findEmployeeByEmail($request['email'], $companyId);

    $employeeData = [
        'email' => $request['email'],
        'name_en' => $request['name_en'],
        'name_ar' => $request['name_ar'],
        'position_en' => $request['position_en'],
        'position_ar' => $request['position_ar'],
        'phone' => $request['phone'],
        'phone_ar' => $request['phone_ar'],
        'mobile' => $request['mobile'],
        'mobile_ar' => $request['mobile_ar'],
        'website' => $request['website'],
        'website_ar' => $request['website_ar'],
        'address_en' => $request['address_en'],
        'address_2_en' => $request['address_2_en'] ?? '',
        'address_ar' => $request['address_ar'],
        'address_2_ar' => $request['address_2_ar'] ?? '',
        'company_en' => $request['company_en'] ?: ($company['name_en'] ?? $companyName),
        'company_ar' => $request['company_ar'] ?: ($company['name_ar'] ?? ''),
        'department_id' => $request['department_id'],
        'photo' => $request['photo'],
        'status' => 'active'
    ];

    $employeeId = null;
    $result = ['success' => false];
    $statusMsg = '';

    if ($existingEmployee) {
        // Employee exists
        $employeeId = $existingEmployee['id'];

        if ($requestType === 'reprint') {
            // Reprint request - don't update employee info, just generate cards
            $result = ['success' => true, 'id' => $employeeId];
            $statusMsg = 'Reprint approved';
        } else {
            // Update request - update employee info
            try {
                $updateData = array_filter($employeeData, fn($v) => !empty($v));
                unset($updateData['email']); // Don't update email

                if (!empty($updateData)) {
                    $db->update('employees', $updateData, 'id = :id', ['id' => $employeeId]);
                }
                $result = ['success' => true, 'id' => $employeeId];
                $statusMsg = 'Employee updated';
            } catch (Exception $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }
        }
    } else {
        // Create new employee
        $result = addEmployee($employeeData, $companyId);
        if ($result['success']) {
            $employeeId = $result['id'];
            $statusMsg = 'Employee created';
        }
    }

    if (!($result['success'] && $employeeId)) {
        return [
            'success' => false,
            'employee_id' => null,
            'status_msg' => '',
            'quantity' => $quantityRequested,
            'error' => $result['error'] ?? 'Unknown error',
            'send_to_print' => $sendToPrint,
        ];
    }

    try {
        // Update request status
        $db->query(
            "UPDATE card_requests SET status = 'approved', employee_id = :eid, reviewed_at = NOW(), reviewed_by = :uid WHERE id = :id",
            ['id' => $request['id'], 'eid' => $employeeId, 'uid' => $reviewedBy]
        );

        // Store approval info for card generation
        $_SESSION['pending_card_generation'] = [
            'request_id' => $request['id'],
            'employee_id' => $employeeId,
            'email' => $request['email'],
            'name' => $request['name_en'] ?: $request['name_ar'],
            'request_type' => $requestType,
            'quantity' => $quantityRequested
        ];

        // Place the BHD print order (200 pcs) when the admin chose "send to
        // print". Idempotent: never re-place if this request already has one.
        // Non-fatal: a print-order failure must not undo the approval.
        $printOrderId = null;
        $printOrderNumber = null;
        if ($sendToPrint && empty($request['print_order_id'])) {
            try {
                require_once INCLUDES_DIR . '/PrintShopIntegration.php';
                $host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
                $abs = function ($p) use ($host) {
                    $p = trim((string)$p);
                    if ($p === '') return '';
                    return strpos($p, 'http') === 0 ? $p : 'https://' . $host . '/' . ltrim($p, '/');
                };
                $order = PrintShopIntegration::createOrder([
                    'print_shop_id'  => 2, // BHD Printing & Designing (internal provider)
                    'company_id'     => $companyId,
                    'employee_id'    => $employeeId,
                    'quantity'       => $quantityRequested,
                    'card_front_url' => $abs($request['preview_front_path'] ?? $request['preview_front'] ?? ''),
                    'card_back_url'  => $abs($request['preview_back_path'] ?? $request['preview_back'] ?? ''),
                    'payment_status' => 'pending',
                ]);
                if (!empty($order['success']) && !empty($order['order_id'])) {
                    $printOrderId = $order['order_id'];
                    $row = $db->fetchOne(
                        "SELECT order_number FROM print_orders WHERE id = :oid",
                        ['oid' => $printOrderId]
                    );
                    $printOrderNumber = $row['order_number'] ?? null;
                    $db->query(
                        "UPDATE card_requests SET print_order_id = :oid WHERE id = :rid",
                        ['oid' => $printOrderId, 'rid' => $request['id']]
                    );
                } else {
                    error_log('approveRequestChain: print order not placed: ' . ($order['error'] ?? 'unknown'));
                }
            } catch (Throwable $e) {
                error_log('approveRequestChain print order error: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'employee_id' => $employeeId,
            'status_msg' => $statusMsg,
            'quantity' => $quantityRequested,
            'error' => null,
            'send_to_print' => $sendToPrint,
            'print_order_id' => $printOrderId,
            'print_order_number' => $printOrderNumber,
        ];
    } catch (Exception $e) {
        error_log("Error approving request: " . $e->getMessage());
        return [
            'success' => false,
            'employee_id' => $employeeId,
            'status_msg' => $statusMsg,
            'quantity' => $quantityRequested,
            'error' => 'Error updating request status.',
            'send_to_print' => $sendToPrint,
        ];
    }
}
