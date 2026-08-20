<?php
/**
 * Save preview card images for a card request.
 * Called from the portal when employee submits their request.
 *
 * Hardened 2026-05-06 (E2E loop iter 28):
 *   - require POST + valid CSRF
 *   - resolve company_id from session (getCurrentCompanyId), not POST. Previous
 *     code accepted any POST'd company_id, so a logged-in employee from tenant
 *     A could write previews into tenant B's upload dir, or escape via path
 *     traversal in the company id (`../../etc`).
 *   - validate company_id is a UUID format (defensive double-check)
 *   - finfo MIME check on every uploaded file (rule 7)
 *   - verify request_id (if provided) belongs to the same company before
 *     updating; previously any logged-in caller could update any card_requests row
 *   - sanitize the catch-all error response
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!validateCSRFToken($csrf)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    // Resolve company from session, not from POST. Logged-in employees only see
    // their own tenant.
    $sessionCompany = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
    $userRole = Auth::getCurrentRole();
    if ($userRole === 'super_admin') {
        // Super-admin can act on behalf of any tenant; accept POST'd company_id
        // but still sanitize it.
        $companyId = $_POST['company_id'] ?? $sessionCompany;
    } else {
        $companyId = $sessionCompany;
    }

    if (!$companyId || !preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', (string) $companyId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_company_context']);
        exit;
    }

    $requestId = $_POST['request_id'] ?? null;

    $outputDir = getCompanyUploadsPath($companyId) . '/request_previews';
    if (!file_exists($outputDir)) {
        @mkdir($outputDir, 0755, true);
    }

    $result = [
        'success'   => true,
        'front_url' => null,
        'back_url'  => null,
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $baseName = 'preview_' . date('Ymd_His') . '_' . uniqid();

    foreach (['front', 'back'] as $side) {
        if (!isset($_FILES[$side]) || $_FILES[$side]['error'] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = $_FILES[$side]['tmp_name'];
        // Cap each preview at 4 MB so a hostile caller can't fill the disk.
        if ((int) $_FILES[$side]['size'] > 4 * 1024 * 1024) {
            continue;
        }
        $mime = $finfo->file($tmp);
        if ($mime !== 'image/png') {
            continue;
        }
        $filename = $baseName . '_' . $side . '.png';
        $diskPath = $outputDir . '/' . $filename;
        if (move_uploaded_file($tmp, $diskPath)) {
            $result[$side . '_url'] = 'uploads/companies/' . $companyId . '/request_previews/' . $filename;
        }
    }

    // Update card_requests ONLY if the row belongs to this company. Without this,
    // any caller could update any card_requests row's preview pointers.
    if ($requestId && ($result['front_url'] || $result['back_url'])) {
        $db = Database::getInstance();
        if ($db->isConnected()) {
            $owns = $db->fetchOne(
                "SELECT id FROM card_requests WHERE id = :id AND company_id = :cid LIMIT 1",
                ['id' => $requestId, 'cid' => $companyId]
            );
            if ($owns) {
                $updateData = ['preview_generated_at' => dbNow()];
                if ($result['front_url']) $updateData['preview_front'] = $result['front_url'];
                if ($result['back_url'])  $updateData['preview_back']  = $result['back_url'];
                $db->update('card_requests', $updateData, 'id = :where_id', ['where_id' => $requestId]);
            }
        }
    }

    echo json_encode($result);

} catch (Exception $e) {
    error_log('[save_request_preview] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'save_failed']);
}
