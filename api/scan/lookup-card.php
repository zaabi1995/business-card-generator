<?php
/**
 * POST /api/scan/lookup-card.php
 *
 * Finds one active Cardify profile from exact personal email or normalized
 * mobile identifiers extracted from a scanned card. Name-only lookup is never
 * accepted. Ambiguous identifiers return no profile data.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';
require_once INCLUDES_DIR . '/ScanCardLookup.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'lookup-card', 240);

$body = json_decode(file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$identifiers = ScanCardLookup::identifiers($body);
$emails = $identifiers['emails'];
$phones = $identifiers['phones'];

if ($emails === [] && $phones === []) {
    echo json_encode(['success' => true, 'match' => 'none']);
    exit;
}

try {
    $where = [];
    $params = [];
    foreach ($emails as $index => $email) {
        $key = 'lookup_email_' . $index;
        $where[] = 'LOWER(email) = :' . $key;
        $params[$key] = $email;
    }
    $cleanMobile = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
    foreach ($phones as $index => $phone) {
        $digits = preg_replace('/\D/', '', $phone);
        $tail = substr((string) $digits, -8);
        $mobileKey = 'lookup_mobile_' . $index;
        $where[] = $cleanMobile . ' LIKE :' . $mobileKey;
        $params[$mobileKey] = '%' . $tail;
    }

    $db = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT * FROM employees
         WHERE status = 'active'
           AND deleted_at IS NULL
           AND (" . implode(' OR ', $where) . ")
         ORDER BY id ASC
         LIMIT 51",
        $params
    );

    if (count($rows) > 50) {
        echo json_encode(['success' => true, 'match' => 'ambiguous']);
        exit;
    }

    $matches = ScanCardLookup::uniqueMatchedEmployees($rows, $emails, $phones);
    if (count($matches) !== 1) {
        echo json_encode([
            'success' => true,
            'match' => count($matches) > 1 ? 'ambiguous' : 'none',
        ]);
        exit;
    }

    $employee = $matches[0];
    $company = $db->fetchOne(
        'SELECT * FROM companies WHERE id = :lookup_company_id LIMIT 1',
        ['lookup_company_id' => $employee['company_id']]
    );
    if (!$company) {
        echo json_encode(['success' => true, 'match' => 'none']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'match' => 'unique',
        'card' => CardifyConvention::employeeToScanCard($employee, $company),
        'card_url' => CardifyConvention::employeeShareUrl((string) $company['slug'], $employee),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[scan/lookup-card] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
