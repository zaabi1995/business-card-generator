<?php
/**
 * POST /api/my-card-verify.php  {phone, code}
 *
 * Step 2 of "get my card". Verifies the WhatsApp OTP and, only then, returns
 * the card that belongs to that number: its public URL and the two wallet
 * links. Existence of a card is revealed HERE and nowhere earlier, because by
 * this point the caller has proven they control the number.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/ScanCardLookup.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

header('Content-Type: application/json; charset=utf-8');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    out(['ok' => false, 'error' => 'post_only']);
}

$in = json_decode(file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$phone = ScanCardLookup::normalizePhone((string)($in['phone'] ?? ''));
$code  = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
if ($phone === null || $code === '') out(['ok' => false, 'error' => 'err_code']);

$result = OtpService::verify($phone, $code, 'mycard');
if (empty($result['ok']) && empty($result['success'])) {
    out(['ok' => false, 'error' => 'err_code']);
}

$pdo = Database::getInstance()->getConnection();
$digits = preg_replace('/\D/', '', $phone);
$tail = substr((string)$digits, -8);
$clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(e.mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
$stmt = $pdo->prepare(
    "SELECT e.*, c.slug AS company_slug, c.name AS company_name
       FROM employees e
       JOIN companies c ON c.id = e.company_id
      WHERE e.status = 'active' AND e.deleted_at IS NULL AND $clean LIKE :tail
      LIMIT 10"
);
$stmt->execute([':tail' => '%' . $tail]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) out(['ok' => true, 'found' => false]);

// EVERY card on this number, not just one.
//
// The first cut required exactly one match and returned "no card" otherwise,
// which failed for the people most likely to use this: anyone who appears under
// more than one company (a group CEO, a consultant, a founder with a personal
// card and a company one) matched several rows and was told they had none. They
// have proven they own the number, so they may see all of it and pick.
$cards = [];
foreach ($rows as $emp) {
    $slug = (string) $emp['company_slug'];
    $shareUrl = CardifyConvention::employeeShareUrl($slug, $emp);
    // The wallet pass is keyed by the SHARE TOKEN, the last segment of the
    // public URL, NOT employee_id (which is empty on every row in this schema).
    $token = rawurldecode((string) basename((string) parse_url($shareUrl, PHP_URL_PATH)));
    $cards[] = [
        'name'    => trim((string)(($emp['name_en'] ?? '') ?: ($emp['name_ar'] ?? ''))),
        'name_ar' => (string)($emp['name_ar'] ?? ''),
        'title'   => trim((string)(($emp['position_en'] ?? '') ?: ($emp['position_ar'] ?? ''))),
        'company' => (string)($emp['company_name'] ?? ''),
        'url'     => $shareUrl,
        'wallet_apple'  => '/wallet_apple.php?i='  . rawurlencode($token) . '&c=' . rawurlencode($slug),
        'wallet_google' => '/wallet_google.php?i=' . rawurlencode($token) . '&c=' . rawurlencode($slug),
    ];
}

out(['ok' => true, 'found' => true, 'cards' => $cards, 'card' => $cards[0]]);
