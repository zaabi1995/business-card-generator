<?php
/**
 * POST /api/scan/refine.php - structure OCR TEXT into a parsed contact using a
 * model, for phones that cannot run Apple Intelligence.
 *
 * PRIVACY, deliberate: this endpoint accepts TEXT ONLY. The card photo never
 * leaves the device. Devices with Apple Intelligence keep doing this entirely on
 * device and never call here at all; this is the fallback for hardware that
 * cannot, which would otherwise be stuck with OCR heuristics alone.
 *
 * The app grounds whatever comes back against the OCR of the same card
 * (groundParsedInOcr), so a value this endpoint invents is dropped rather than
 * saved. Treat the response as a suggestion, never as authority.
 *
 * Body (JSON): { text (required, the OCR text of one card) }
 * Response: {success, parsed: {name_en, name_ar, title_en, ..., card_type}}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
// Costs a model call, so tighter than the free endpoints.
scanRateLimit($ctx, 'refine', 120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$text = is_array($body) ? trim((string)($body['text'] ?? '')) : '';
if ($text === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'text required']);
    exit;
}
// A business card's OCR is small; anything larger is not a card.
if (mb_strlen($text) > 4000) {
    $text = mb_substr($text, 0, 4000);
}

if (!defined('OPENROUTER_API_KEY') || !OPENROUTER_API_KEY) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'refine_unavailable']);
    exit;
}

$system =
    "You extract structured contact details from the OCR text of ONE business card.\n" .
    "Reply with ONLY a JSON object, no prose, using exactly these keys:\n" .
    "{\"name_en\":\"\",\"name_ar\":\"\",\"title_en\":\"\",\"title_ar\":\"\",\"company_en\":\"\",\"company_ar\":\"\"," .
    "\"phones\":[{\"number\":\"\",\"type\":\"mobile\"}],\"emails\":[\"\"],\"website\":\"\"," .
    "\"address_en\":\"\",\"address_ar\":\"\",\"card_type\":\"business\"}\n" .
    "Rules: copy values VERBATIM from the text, never invent or complete a value. " .
    "Arabic goes in the _ar fields and English in the _en fields. " .
    "Leave a field as an empty string when the card does not show it. " .
    "A person's job title is not the company name.";

$payload = [
    'model' => defined('AI_CLASSIFY_MODEL') ? AI_CLASSIFY_MODEL : 'qwen/qwen-2.5-72b-instruct',
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $text],
    ],
    'max_tokens' => 900,
    'temperature' => 0.1,
];

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'HTTP-Referer: https://cardify.om',
        'X-Title: Cardify Scan Refine',
    ],
    CURLOPT_TIMEOUT => 45,
]);
$responseBody = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $code !== 200) {
    error_log('[scan/refine] upstream failed: ' . ($curlError ?: ('http ' . $code)));
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'refine_failed']);
    exit;
}

$decoded = json_decode((string)$responseBody, true);
$content = $decoded['choices'][0]['message']['content'] ?? '';
$first = strpos((string)$content, '{');
$last = strrpos((string)$content, '}');
if ($first === false || $last === false || $last <= $first) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'refine_unparseable']);
    exit;
}
$parsed = json_decode(substr((string)$content, $first, $last - $first + 1), true);
if (!is_array($parsed)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'refine_unparseable']);
    exit;
}

// Shape defensively: the app grounds every value anyway, but never hand back a
// structure it did not ask for.
$stringField = static function ($value): string {
    return is_string($value) ? trim($value) : '';
};
$phones = [];
if (isset($parsed['phones']) && is_array($parsed['phones'])) {
    foreach (array_slice($parsed['phones'], 0, 10) as $phone) {
        if (is_array($phone) && isset($phone['number'])) {
            $number = $stringField($phone['number']);
            if ($number !== '') {
                $phones[] = ['number' => $number, 'type' => $stringField($phone['type'] ?? 'mobile') ?: 'mobile'];
            }
        } elseif (is_string($phone) && trim($phone) !== '') {
            $phones[] = ['number' => trim($phone), 'type' => 'mobile'];
        }
    }
}
$emails = [];
if (isset($parsed['emails']) && is_array($parsed['emails'])) {
    foreach (array_slice($parsed['emails'], 0, 10) as $email) {
        $value = $stringField($email);
        if ($value !== '') $emails[] = $value;
    }
}

echo json_encode([
    'success' => true,
    'parsed' => [
        'name_en' => $stringField($parsed['name_en'] ?? ''),
        'name_ar' => $stringField($parsed['name_ar'] ?? ''),
        'title_en' => $stringField($parsed['title_en'] ?? ''),
        'title_ar' => $stringField($parsed['title_ar'] ?? ''),
        'company_en' => $stringField($parsed['company_en'] ?? ''),
        'company_ar' => $stringField($parsed['company_ar'] ?? ''),
        'phones' => $phones,
        'emails' => $emails,
        'website' => $stringField($parsed['website'] ?? ''),
        'address_en' => $stringField($parsed['address_en'] ?? ''),
        'address_ar' => $stringField($parsed['address_ar'] ?? ''),
        'card_type' => $stringField($parsed['card_type'] ?? 'business') ?: 'business',
    ],
]);
