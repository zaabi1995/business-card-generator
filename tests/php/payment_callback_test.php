<?php
/**
 * The Paymob callback, end to end on our side of the wire.
 *
 * No sandbox exists for this integration, so nothing here charges anything.
 * What it does is drive the exact code a callback runs through, with payloads
 * shaped like Paymob's, and pin the two defects found on 5 Sep 2026 when
 * production showed 5 payments and not one carrying a Paymob transaction id:
 *
 * 1. The POST webhook could not identify the payment. Paymob sends Cardify's
 *    own reference as obj.order.merchant_order_id; the flatten replaced
 *    obj.order with its numeric id and threw the reference away. The
 *    notification_url carries no query string, so every server-to-server
 *    callback reached "Missing order reference" and answered 400. Settlement
 *    depended on the customer's browser completing the redirect.
 *
 * 2. The query string was merged over the body wholesale. merchant_order_id
 *    and special_reference are not among the twenty fields Paymob signs, so a
 *    replayed genuine callback carrying ?special_reference=<another payment>
 *    still verified, and only the amount check stood between that and settling
 *    a different order of the same value.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/includes/JsonLd.php';

if (!defined('PAYMOB_HMAC_SECRET')) define('PAYMOB_HMAC_SECRET', 'test-secret-not-a-real-key');

// Payment.php pulls in the app. Load only what this test drives by evaluating
// the class in isolation would be worse than reading the file: parse it and
// require it with the database calls unreached, which is what happens because
// every assertion below stops before the first query.
if (!class_exists('Database')) {
    eval('class Database { public static function getInstance() { throw new RuntimeException("no database in this test"); } }');
}
require_once $root . '/includes/Payment.php';

$failures = 0;
function payCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

/** A transaction webhook shaped like Paymob's. */
function paymobWebhook(array $over = []): array
{
    $obj = array_merge([
        'id' => 1234567,
        'amount_cents' => 5000,
        'created_at' => '2026-09-05T10:00:00.000000',
        'currency' => 'OMR',
        'error_occured' => false,
        'has_parent_transaction' => false,
        'integration_id' => 998877,
        'is_3d_secure' => true,
        'is_auth' => false,
        'is_capture' => false,
        'is_refunded' => false,
        'is_standalone_payment' => true,
        'is_voided' => false,
        'owner' => 42,
        'pending' => false,
        'success' => true,
        'order' => ['id' => 7654321, 'merchant_order_id' => 'CRD-PRINT-001'],
        'source_data' => ['pan' => '4242', 'sub_type' => 'MasterCard', 'type' => 'card'],
        'data' => ['card_num' => 'xxxx-4242', 'card_type' => 'MasterCard'],
    ], $over);
    return ['type' => 'TRANSACTION', 'obj' => $obj];
}

// 1. the reference survives the flatten, which is the whole webhook path
$flat = Payment::flattenCallback(paymobWebhook(), []);
payCheck($flat['merchant_order_id'] === 'CRD-PRINT-001',
    'a POST webhook still carries the reference Cardify generated',
    $flat['merchant_order_id'] ?? '(missing)');
payCheck((string) $flat['order'] === '7654321',
    "Paymob's own order id is kept for the HMAC", (string) ($flat['order'] ?? ''));
payCheck($flat['source_data_pan'] === '4242' && $flat['source_data_type'] === 'card',
    'the signed source_data fields are flattened for the HMAC');

// 2. the query string cannot choose which payment a callback settles
$hijack = Payment::flattenCallback(paymobWebhook(), [
    'merchant_order_id'  => 'CRD-PRINT-999',
    'special_reference'  => 'CRD-PRINT-999',
    'amount_cents'       => 1,
    'success'            => 'true',
    'order'              => '1',
    'id'                 => '1',
]);
payCheck($hijack['merchant_order_id'] === 'CRD-PRINT-001',
    'a query string cannot redirect a callback to another payment',
    (string) $hijack['merchant_order_id']);
payCheck((int) $hijack['amount_cents'] === 5000,
    'a query string cannot restate the amount', (string) $hijack['amount_cents']);
payCheck((string) $hijack['order'] === '7654321',
    'a query string cannot restate the Paymob order id');
payCheck($hijack['success'] === true,
    'a query string cannot flip a failed transaction to successful',
    var_export($hijack['success'], true));

// A field Paymob does not sign and Cardify does not key on still passes
// through, because the redirect really does carry some of them.
$extra = Payment::flattenCallback(paymobWebhook(), ['txn_response_code' => 'APPROVED']);
payCheck(($extra['txn_response_code'] ?? '') === 'APPROVED',
    'an unrelated query field still reaches the handler');

// The GET redirect has no body, so the query string IS the callback.
$redirect = Payment::flattenCallback([], ['merchant_order_id' => 'CRD-PRINT-002', 'success' => 'true']);
payCheck(($redirect['merchant_order_id'] ?? '') === 'CRD-PRINT-002',
    'the GET redirect path still reads its fields from the query string');

// 3. the signature
$signed = Payment::flattenCallback(paymobWebhook(), []);
$hmac = Payment::computeHmac($signed, PAYMOB_HMAC_SECRET);
payCheck(Payment::verifyHmac($signed, $hmac), 'a correctly signed callback verifies');
payCheck(strlen($hmac) === 128, 'the signature is sha512 hex', (string) strlen($hmac));

$tampered = $signed;
$tampered['amount_cents'] = 1;
payCheck(!Payment::verifyHmac($tampered, $hmac), 'changing the amount breaks the signature');
$tampered = $signed;
$tampered['success'] = false;
payCheck(!Payment::verifyHmac($tampered, $hmac), 'changing the outcome breaks the signature');
$tampered = $signed;
$tampered['order'] = '9999999';
payCheck(!Payment::verifyHmac($tampered, $hmac), 'changing the order id breaks the signature');
payCheck(!Payment::verifyHmac($signed, str_repeat('0', 128)), 'a wrong signature is refused');
payCheck(!Payment::verifyHmac($signed, ''), 'an empty signature is refused');

// The reference is NOT signed, which is exactly why the query string is
// no longer allowed to supply it. Pinned so the reasoning stays visible.
$refChanged = $signed;
$refChanged['merchant_order_id'] = 'CRD-PRINT-999';
payCheck(Payment::verifyHmac($refChanged, $hmac),
    'the reference is outside the signature, so it must come from the body');

// 4. the amount conversion the callback checks against
payCheck(Payment::toSmallestUnit(5.0, 'OMR') === 5000, 'OMR converts on three decimals',
    (string) Payment::toSmallestUnit(5.0, 'OMR'));
payCheck(Payment::toSmallestUnit(0.045, 'OMR') === 45, 'a sub-baisa rate rounds correctly',
    (string) Payment::toSmallestUnit(0.045, 'OMR'));
payCheck(Payment::toSmallestUnit(10.0, 'OMR') === 10000, 'the NFC card price converts',
    (string) Payment::toSmallestUnit(10.0, 'OMR'));
payCheck(Payment::toSmallestUnit(1.0, 'USD') === 100, 'a two-decimal currency still converts',
    (string) Payment::toSmallestUnit(1.0, 'USD'));

// 5. the handler's own contract, read from the source: the things that must
//    never be true again.
$src = file_get_contents($root . '/includes/Payment.php');
payCheck(!preg_match('/\$data = array_merge\(\$data, \$_GET\)/', $src),
    'no blanket merge of the query string over the callback body');
payCheck(str_contains($src, "'success' => false, 'error' => 'Missing HMAC signature'"),
    'a callback with no signature is refused');
payCheck(str_contains($src, "'success' => false, 'error' => 'Invalid HMAC signature'"),
    'a callback with a bad signature is refused');
payCheck(str_contains($src, "'idempotent' => true"),
    'a repeat callback on a paid payment returns without re-running the side effects');
payCheck(str_contains($src, "'error' => 'Amount mismatch'"),
    'a callback whose amount does not match the order is refused');
payCheck(str_contains($src, "'error' => 'Order mismatch'"),
    'a callback for a different Paymob order than the one bound is refused');
payCheck(substr_count($src, "PaymentRetry::markSucceeded") === 1,
    'a success closes any open dunning retry');
foreach (['activateSubscription', 'confirmPrintOrder', 'confirmCardOrder'] as $fn) {
    payCheck(str_contains($src, "self::{$fn}("), "a paid callback dispatches {$fn}");
}

$callback = file_get_contents($root . '/paymob/callback.php');
payCheck(str_contains($callback, "http_response_code(\$result['success'] ? 200 : 400)"),
    'the webhook answers Paymob with a status it can retry on');
payCheck(str_contains($callback, "defined('APP_HOST') ? APP_HOST"),
    'the redirect target comes from APP_HOST, never the Host header');
payCheck(str_contains($callback, "payment=success") && str_contains($callback, "payment=error"),
    'both outcomes route the customer somewhere that says what happened');

$emDash = "\xE2\x80\x94";
foreach (['includes/Payment.php', 'paymob/callback.php'] as $rel) {
    payCheck(!str_contains(file_get_contents($root . '/' . $rel), $emDash), "{$rel} contains no em dash");
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
