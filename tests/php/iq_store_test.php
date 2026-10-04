<?php
declare(strict_types=1);
/*
 * The IQ test against a real database (run it on a local copy, never production):
 * the clock, scoring, sign-in adopting a guest attempt, the leaderboard's first-attempt and
 * stayed-on-the-page rules, the retake wait, and the Paymob callback (signature, amount,
 * idempotency, what each product grants).
 *
 * Run: php tests/php/iq_store_test.php   (uses config.php's database; refuses the production one)
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/iq/test';
$_SERVER['SCRIPT_NAME'] = '/iq/test-runner.php';
require __DIR__ . '/../../config.php';
require INCLUDES_DIR . '/iq/IqPay.php';

if (DB_NAME === 'bc') { fwrite(STDERR, "refusing to run against the production database\n"); exit(2); }
if (session_status() === PHP_SESSION_NONE) @session_start();

$failures = 0;
function check(string $name, bool $ok, $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : '  -> ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}
$db = Database::getInstance();
foreach (['iq_answers', 'iq_attempts', 'iq_users', 'iq_payments', 'iq_settings'] as $t) $db->exec("DELETE FROM $t");
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

function guest(): void { $_COOKIE = []; unset($_SESSION['iq_user_id']); }
function fill(array $a, int $right): array
{
    // Answers every question in order: the first $right correctly, the rest wrongly.
    for ($i = 0; $i < IQE_COUNT; $i++) {
        $q = IqStore::serve($a);
        if ($q === null) break;
        $row = Database::getInstance()->fetchOne('SELECT * FROM iq_answers WHERE attempt_id = :a AND idx = :i', ['a' => $a['id'], 'i' => $i]);
        $full = iqe_question((string)$a['seed'], $i, (string)$row['kind'], (int)$row['level']);
        $choice = $i < $right ? $full['answer'] : ($full['answer'] + 1) % count($full['options']);
        IqStore::answer($a, $i, $choice);
        $a = IqStore::settle($a);
    }
    return $a;
}

/* 1. A guest takes the test; the server clock and scoring. */
guest();
$a = IqStore::start('Guest One', 30, 'OM', 'en');
check('a guest attempt gets a private token cookie and a 16-char public id', isset($_COOKIE[IqStore::GUEST_COOKIE]) && strlen($a['public_id']) === 16);
check('the guest owns it', IqStore::owns($a));
$q = IqStore::serve($a);
check('a served question carries no answer and shows the whole test clock', !isset($q['answer']) && $q['seconds'] === IQE_TEST_SECONDS && $q['remaining'] > IQE_TEST_SECONDS - 5, $q['remaining']);
IqStore::answer($a, 0, 99);
$row = $db->fetchOne('SELECT * FROM iq_answers WHERE attempt_id = :a AND idx = 0', ['a' => $a['id']]);
check('an invalid choice counts as wrong', (int)$row['correct'] === 0 && $row['choice'] === null);
IqStore::answer($a, 0, 0);
$row2 = $db->fetchOne('SELECT * FROM iq_answers WHERE attempt_id = :a AND idx = 0', ['a' => $a['id']]);
check('a second answer to the same question is ignored', $row2['answered_ts'] === $row['answered_ts']);
$a = fill(IqStore::settle($a), 25);
check('finishing all 30 scores the attempt', $a['status'] === 'done' && $a['iq'] !== null && (int)$a['correct'] >= 24, [$a['status'], $a['iq'], $a['correct']]);
$res = IqStore::result($a);
check('the result has an IQ, a 90% range and a percentile', $res['iq_low'] < $res['iq'] && $res['iq'] < $res['iq_high'] && $res['percentile'] >= 1);
check('a guest cannot start again for 30 days', IqStore::nextFreeStart() !== null);

/* 2. Time up: unanswered questions count as wrong. */
guest();
$b = IqStore::start('Slow Person', 40, null, 'ar');
IqStore::serve($b);
$db->exec('UPDATE iq_attempts SET started_ts = started_ts - ' . (IQE_TEST_SECONDS + 5) . ' WHERE id = ' . (int)$b['id']);
$b = IqStore::settle($db->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $b['id']]));
$late = $db->fetchOne('SELECT COUNT(*) n, SUM(late) l FROM iq_answers WHERE attempt_id = :a', ['a' => $b['id']]);
check('when 33 minutes pass, all 30 close as wrong and the attempt is scored', $b['status'] === 'done' && (int)$late['n'] === 30 && (int)$late['l'] === 30 && $b['iq'] !== null, [$b['status'], $late]);

/* 3. Signing in adopts this browser's guest attempt. */
guest();
$c = IqStore::start('Claimed Person', 25, 'AE', 'en');
$c = fill($c, 20);
$u = IqStore::signIn('claimed@example.com', 'email');
$c = $db->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $c['id']]);
check('signing in adopts the guest attempt and takes its name', $c['user_id'] === $u['id']
    && $db->fetchOne('SELECT display_name FROM iq_users WHERE id = :id', ['id' => $u['id']])['display_name'] === 'Claimed Person');
check('the same email signs into the same account', IqStore::signIn('claimed@example.com', 'email')['id'] === $u['id']);

/* 4. Leaderboard: first attempt only, and only when the person stayed on the page. */
$db->update('iq_users', ['country' => 'AE'], 'id = :id', ['id' => $u['id']]);
$board = IqStore::leaderboard();
check('the signed-in first attempt is on the board', count($board) === 1 && (int)$board[0]['iq'] === (int)$c['iq']);
$db->update('iq_users', ['pro_until' => date('Y-m-d H:i:s', time() + 86400)], 'id = :id', ['id' => $u['id']]);
check('a Pro member may start again at once', IqStore::nextFreeStart() === null);
$d = fill(IqStore::start('Claimed Person', 25, 'AE', 'en'), 30);
$board = IqStore::leaderboard();
check('a better retake does not replace the first attempt on the board', count($board) === 1 && (int)$board[0]['iq'] === (int)$c['iq'] && (int)$d['iq'] > (int)$c['iq'], [$board, $d['iq']]);
check('the board shows the Pro badge', (int)$board[0]['pro'] === 1);
check('country filter', count(IqStore::leaderboard('all', 'AE')) === 1 && count(IqStore::leaderboard('all', 'OM')) === 0);

guest();
$e = fill(IqStore::start('Tab Switcher', 30, null, 'en'), 28);
for ($i = 0; $i < 3; $i++) $db->exec('UPDATE iq_attempts SET focus_lost = focus_lost + 1 WHERE id = ' . (int)$e['id']);
$u2 = IqStore::signIn('switcher@example.com', 'email');
check('a first attempt where the person left the page 3 times is not on the board', count(IqStore::leaderboard()) === 1);
$place = IqStore::boardPlace($u2['id']);
check('and the account is told why', $place !== null && $place['eligible'] === false);
$db->update('iq_users', ['leaderboard' => 0], 'id = :id', ['id' => $u['id']]);
check('opting out removes a person from the board', count(IqStore::leaderboard()) === 0);
$db->update('iq_users', ['leaderboard' => 1], 'id = :id', ['id' => $u['id']]);

/* 5. Payments: nothing is on sale until a price is set. */
$_SESSION['iq_user_id'] = $u['id'];
$u = IqStore::user();
try { IqPay::checkout($u, 'report', $c); $err = null; } catch (IqError $x) { $err = $x->codeName; }
check('without a price, checkout refuses', $err === 'not_on_sale', $err);

/* 6. The Paymob callback for an IQ_ reference. */
IqStore::setSetting('price_report', '2.500');
IqStore::setSetting('price_pro_month', '4.000');
$pay = function (string $product, ?int $attemptId, float $amount) use ($db, $u): string {
    $ref = 'IQ_TST_' . bin2hex(random_bytes(6));
    $db->insert('iq_payments', ['id' => generateUUID(), 'user_id' => $u['id'], 'attempt_id' => $attemptId, 'product' => $product,
        'amount' => $amount, 'special_reference' => $ref, 'status' => 'pending']);
    return $ref;
};
$sign = function (array $d): array { $d['hmac'] = Payment::computeHmac($d, PAYMOB_HMAC_SECRET); return $d; };
$base = ['id' => '9001', 'order' => '777', 'success' => 'true', 'currency' => 'OMR', 'pending' => 'false', 'is_auth' => 'false',
    'is_capture' => 'false', 'is_standalone_payment' => 'true', 'is_voided' => 'false', 'is_refunded' => 'false', 'is_3d_secure' => 'true',
    'integration_id' => '63364', 'has_parent_transaction' => 'false', 'error_occured' => 'false', 'owner' => '1', 'source_data_pan' => '1234',
    'source_data_sub_type' => 'Visa', 'source_data_type' => 'card', 'created_at' => '2026-10-04'];
$c = $db->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $c['id']]);
$ref = $pay('report', (int)$c['id'], 2.5);
$bad = IqPay::handleCallback($base + ['merchant_order_id' => $ref, 'amount_cents' => '2500', 'hmac' => str_repeat('0', 128)], null);
check('a callback with a wrong signature is rejected', $bad['success'] === false);
$wrongAmt = IqPay::handleCallback($sign($base + ['merchant_order_id' => $ref, 'amount_cents' => '100']), null);
check('a callback with the wrong amount is rejected', $wrongAmt['success'] === false && $wrongAmt['error'] === 'Amount mismatch', $wrongAmt);
$ok = IqPay::handleCallback($sign($base + ['merchant_order_id' => $ref, 'amount_cents' => '2500']), null);
$c = $db->fetchOne('SELECT * FROM iq_attempts WHERE id = :id', ['id' => $c['id']]);
check('a signed, matching callback marks the report paid', $ok['success'] === true && (int)$c['report_paid'] === 1 && $ok['attempt'] === $c['public_id'], $ok);
$again = IqPay::handleCallback($sign($base + ['merchant_order_id' => $ref, 'amount_cents' => '2500']), null);
check('the same callback twice is processed once', $again['success'] === true && !empty($again['idempotent']));
$_COOKIE = [];
check('the paid report opens for its owner', IqPay::canSeeReport($u, $c));
unset($_SESSION['iq_user_id']);
check('and not for a stranger', !IqPay::canSeeReport(null, $c));
$_SESSION['iq_user_id'] = $u['id'];

$before = strtotime((string)$db->fetchOne('SELECT pro_until FROM iq_users WHERE id = :id', ['id' => $u['id']])['pro_until']);
$ref2 = $pay('pro_month', null, 4.0);
IqPay::handleCallback($sign($base + ['merchant_order_id' => $ref2, 'order' => '778', 'amount_cents' => '4000']), null);
$after = strtotime((string)$db->fetchOne('SELECT pro_until FROM iq_users WHERE id = :id', ['id' => $u['id']])['pro_until']);
check('IQ Pro adds 30 days on top of a running pass', abs(($after - $before) - 30 * 86400) < 120, [$before, $after]);
$ref3 = $pay('pro_month', null, 4.0);
$failed = IqPay::handleCallback($sign(array_merge($base, ['success' => 'false', 'merchant_order_id' => $ref3, 'order' => '779', 'amount_cents' => '4000'])), null);
$after2 = strtotime((string)$db->fetchOne('SELECT pro_until FROM iq_users WHERE id = :id', ['id' => $u['id']])['pro_until']);
check('a declined payment grants nothing', $failed['success'] === false && $after2 === $after);

foreach (['iq_answers', 'iq_attempts', 'iq_users', 'iq_payments', 'iq_settings'] as $t) $db->exec("DELETE FROM $t");
echo $failures === 0 ? "all iq store checks passed\n" : "$failures failure(s)\n";
exit($failures ? 1 : 0);
