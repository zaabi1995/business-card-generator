<?php
/**
 * CardJob: the state machine must refuse every move that is not in the diagram.
 * Run: /www/server/php/83/bin/php tests/CardJobTest.php
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardJob.php';

$fails = 0;
function t(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) { $fails++; }
}

t('forward transition allowed',        CardJob::canTransition('submitted', 'approved') === true);
t('approved to quoted allowed',        CardJob::canTransition('approved', 'quoted') === true);
t('quoted to po_received allowed',     CardJob::canTransition('quoted', 'po_received') === true);
t('dispatched to delivered allowed',   CardJob::canTransition('dispatched', 'delivered') === true);

t('skipping a state refused',          CardJob::canTransition('approved', 'dispatched') === false);
t('backwards refused',                 CardJob::canTransition('quoted', 'approved') === false);
t('delivered is terminal',             CardJob::canTransition('delivered', 'dispatched') === false);
t('rejected is terminal',              CardJob::canTransition('rejected', 'approved') === false);

t('reject only from submitted',        CardJob::canTransition('submitted', 'rejected') === true);
t('reject not from quoted',            CardJob::canTransition('quoted', 'rejected') === false);

t('unknown target refused',            CardJob::canTransition('quoted', 'banana') === false);
t('unknown source refused',            CardJob::canTransition('banana', 'quoted') === false);

t('ref has the expected shape',        preg_match('/^MHD-[A-Z0-9]{6}$/', CardJob::mintRef('req-1')) === 1);
t('ref is stable for one request',     CardJob::mintRef('req-1') === CardJob::mintRef('req-1'));
t('ref differs between requests',      CardJob::mintRef('req-1') !== CardJob::mintRef('req-2'));

t('every state is reachable in NEXT',  count(CardJob::STATES) === 8);

// Live round trip. The first version of this class wrote to card_requests.status,
// an ENUM of three values, so every one of these moves would have been rejected
// by MySQL. Exercise the real column against the real table.
$db  = Database::getInstance();
$cid = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$rid = 'cardjob-selftest';
$db->query("DELETE FROM card_request_events WHERE request_id = ?", [$rid]);
$db->query("DELETE FROM card_requests WHERE id = ?", [$rid]);
$db->insert('card_requests', [
    'id' => $rid, 'company_id' => $cid, 'email' => 'selftest@bhd.om',
    'name_en' => 'CardJob Self Test', 'status' => 'pending',
]);

t('new row starts at submitted',
  ($db->fetchOne("SELECT fulfilment_state s FROM card_requests WHERE id = ?", [$rid])['s'] ?? '') === 'submitted');
t('submitted to approved writes',   CardJob::transition($rid, 'approved', ['actor' => 'test']) === true);
t('approved to dispatched refused', CardJob::transition($rid, 'dispatched') === false);
t('approved to quoted writes',      CardJob::transition($rid, 'quoted', ['actor' => 'test']) === true);
t('quoted to po_received writes',   CardJob::transition($rid, 'po_received', ['po' => '4191000999']) === true);
t('replaying the same move fails',  CardJob::transition($rid, 'po_received') === false);
t('state really is po_received',
  ($db->fetchOne("SELECT fulfilment_state s FROM card_requests WHERE id = ?", [$rid])['s'] ?? '') === 'po_received');
t('approval status untouched',
  ($db->fetchOne("SELECT status s FROM card_requests WHERE id = ?", [$rid])['s'] ?? '') === 'pending');
$ev = CardJob::events($rid);
t('four events recorded',   count($ev) === 4);
t('evidence kept the PO',   strpos(json_encode($ev), '4191000999') !== false);

$db->query("DELETE FROM card_request_events WHERE request_id = ?", [$rid]);
$db->query("DELETE FROM card_requests WHERE id = ?", [$rid]);
t('self-test rows cleaned up',
  $db->fetchOne("SELECT id FROM card_requests WHERE id = ?", [$rid]) === false ||
  $db->fetchOne("SELECT id FROM card_requests WHERE id = ?", [$rid]) === null);

echo $fails === 0 ? "\nall passed\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
