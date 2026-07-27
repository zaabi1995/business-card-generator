<?php
/**
 * POST /api/my-card-request.php  {phone, language}
 *
 * Step 1 of "get my card": send a WhatsApp OTP to a number that owns a Cardify
 * profile, so the owner can pull up their own card (and add it to Apple or
 * Google Wallet) without signing in.
 *
 * PRIVACY: the response is IDENTICAL whether or not the number has a card.
 * Answering "no card for that number" would turn this endpoint into a free
 * reverse-lookup oracle: anyone could feed it a leaked phone list and learn
 * which numbers belong to Cardify users. Existence is only ever revealed at
 * verify time, to someone who has proven they own the number.
 *
 * An OTP is only actually SENT when a card exists, because sending WhatsApp
 * messages to arbitrary numbers costs money and is spam. The caller cannot tell
 * the difference from the response.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/ScanCardLookup.php';

header('Content-Type: application/json; charset=utf-8');
function out(array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    out(['ok' => false, 'error' => 'post_only']);
}

$in = json_decode(file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$phone = ScanCardLookup::normalizePhone((string)($in['phone'] ?? ''));
if ($phone === null) out(['ok' => false, 'error' => 'err_phone']);

// Rate limit per number AND per IP: without this the OTP send is a way to
// bill WhatsApp messages to Cardify, and a way to probe numbers by timing.
$pdo = Database::getInstance()->getConnection();
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$pdo->exec("CREATE TABLE IF NOT EXISTS my_card_otp_throttle (
    bucket VARCHAR(64) NOT NULL PRIMARY KEY,
    hits INT NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL
)");
foreach (["p:$phone" => 5, "i:$ip" => 20] as $bucket => $limit) {
    $row = $pdo->prepare("SELECT hits, window_start FROM my_card_otp_throttle WHERE bucket = ?");
    $row->execute([$bucket]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r && strtotime((string)$r['window_start']) > time() - 3600) {
        if ((int)$r['hits'] >= $limit) out(['ok' => false, 'error' => 'err_rate']);
        $pdo->prepare("UPDATE my_card_otp_throttle SET hits = hits + 1 WHERE bucket = ?")->execute([$bucket]);
    } else {
        $pdo->prepare("REPLACE INTO my_card_otp_throttle (bucket, hits, window_start) VALUES (?, 1, NOW())")
            ->execute([$bucket]);
    }
}

// Does a card exist? Same last-8-digits match lookup-card.php uses, so the two
// agree about what "this number has a card" means.
$digits = preg_replace('/\D/', '', $phone);
$tail = substr((string)$digits, -8);
$clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
$stmt = $pdo->prepare(
    "SELECT id FROM employees
      WHERE status = 'active' AND deleted_at IS NULL AND $clean LIKE :tail
      LIMIT 2"
);
$stmt->execute([':tail' => '%' . $tail]);
$found = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($found) === 1) {
    try {
        OtpService::send($phone, 'whatsapp', 'mycard');
    } catch (\Throwable $e) {
        error_log('[my-card-request] ' . $e->getMessage());
        // Still answer ok: a send failure must not become an existence oracle.
    }
}

out(['ok' => true]);
