<?php
/**
 * PoInbox: the subject tag and the purchase-order number must come out of real
 * MHD wording, and nothing that merely looks like a number may be taken for one.
 * Run: /www/server/php/83/bin/php tests/PoInboxTest.php
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/PoInbox.php';

$fails = 0;
function t(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) { $fails++; }
}

t('ref out of a reply subject',
  PoInbox::extractRef('RE: [MHD-A1B2C3] Quotation for Hatem Al Balushi') === 'MHD-A1B2C3');
t('ref survives the Outlook external banner',
  PoInbox::extractRef('RE: EXTERNAL.!!!   Re: [MHD-A1B2C3] Quotation') === 'MHD-A1B2C3');
t('ref matched case-insensitively',
  PoInbox::extractRef('re: [mhd-a1b2c3] quotation') === 'MHD-A1B2C3');
t('no ref when absent', PoInbox::extractRef('RE: Business Cards') === null);
t('a bare ref without brackets is not a ref',
  PoInbox::extractRef('RE: MHD-A1B2C3 quotation') === null);

t('PO number from the body',    PoInbox::extractPoNumber('Please find attached PO 4191000258') === '4191000258');
t('PO number with prefix 4101', PoInbox::extractPoNumber('our po no 4101000932 refers')        === '4101000932');
t('PO number from a filename',
  PoInbox::extractPoNumber('PO 4141008646 - Business Cards for Pradeep.pdf') === '4141008646');
t('nine digits is not a PO',    PoInbox::extractPoNumber('reference 419100025')  === null);
t('eleven digits is not a PO',  PoInbox::extractPoNumber('ref 41910002581')      === null);
t('a phone number is not a PO', PoInbox::extractPoNumber('call 96871557505')     === null);
t('MHD Logistics number their own way',
  PoInbox::extractPoNumber('our PO MHDL-PO/255 refers') === 'MHDL-PO/255');
t('a labelled number of another length counts',
  PoInbox::extractPoNumber('PO No. 480120014 attached') === '480120014');
t('an unlabelled odd number does not',
  PoInbox::extractPoNumber('invoice reference 480120014') === null);

// ingest() refuses everything it cannot prove, and says why.
t('a stranger is not this customer',
  PoInbox::isCustomerSender('attacker@evil.example', ['department_id' => null]) === false);
t('MHD is this customer',
  PoInbox::isCustomerSender('Devanand V <devanand.v@mhd.co.om>', ['department_id' => null]) === true);
t('MHD Logistics is this customer',
  PoInbox::isCustomerSender('ops@mhdlogistics.com', ['department_id' => null]) === true);
t('a lookalike domain is not',
  PoInbox::isCustomerSender('po@mhd.co.om.evil.example', ['department_id' => null]) === false);

t('our own outgoing copy is refused',
  (PoInbox::ingest(['subject' => '[MHD-A1B2C3] Quotation',
                    'from' => 'BHD Printing <sales@bhdoman.com>'])['reason'] ?? '') === 'sent by us');
t('a real customer sender is not refused as ours',
  PoInbox::isOwnSender('Devanand V <devanand.v@mhd.co.om>') === false);

t('no ref is refused',
  (PoInbox::ingest(['subject' => 'RE: business cards'])['reason'] ?? '') === 'no job ref in subject');
t('an unknown ref is refused',
  (PoInbox::ingest(['subject' => '[MHD-ZZZZZZ] po attached'])['reason'] ?? '') === 'no such job');

// A real job, at the wrong state, must not be advanced by a reply.
$db  = Database::getInstance();
$cid = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$rid = 'poinbox-selftest';
$ref = CardJob::mintRef($rid);
$db->query("DELETE FROM card_request_events WHERE request_id = ?", [$rid]);
$db->query("DELETE FROM card_requests WHERE id = ?", [$rid]);
$db->insert('card_requests', [
    'id' => $rid, 'company_id' => $cid, 'email' => 'selftest@bhd.om',
    'name_en' => 'PoInbox Self Test', 'status' => 'pending', 'job_ref' => $ref,
]);

$r = PoInbox::ingest(['subject' => "RE: [{$ref}] Quotation", 'from' => 'Devanand V <devanand.v@mhd.co.om>']);
t('a submitted job refuses a PO',
  ($r['reason'] ?? '') === 'job is submitted, not awaiting a purchase order');
t('and that refusal is transient, so the reply is read again',
  ($r['transient'] ?? false) === true);

CardJob::transition($rid, 'approved', ['actor' => 'test']);
CardJob::transition($rid, 'quoted',   ['actor' => 'test']);
$r = PoInbox::ingest(['subject' => "RE: [{$ref}] Quotation", 'from' => 'Devanand V <devanand.v@mhd.co.om>']);
t('a quoted job with no attachment is refused', ($r['reason'] ?? '') === 'no pdf attached');

// With a PDF it files, takes the number off the document and moves the job on.
$pdf = "%PDF-1.4\n purchase order 4191000258\n";
$r = PoInbox::ingest([
    'subject' => "RE: [{$ref}] Quotation", 'from' => 'Devanand V <devanand.v@mhd.co.om>',
    'message_id' => '<selftest@mhd.co.om>', 'body' => 'PO attached',
    'attachments' => [['name' => 'PO 4191000258 - Business Cards.pdf', 'data' => $pdf]],
]);
t('a PO with a PDF is filed',  ($r['matched'] ?? false) === true);
t('a reply with no PO number anywhere is refused', (function () use ($ref) {
    $r = PoInbox::ingest([
        'subject' => "RE: [{$ref}] Quotation", 'from' => 'Devanand V <devanand.v@mhd.co.om>',
        'body' => 'please proceed', 'attachments' => [['name' => 'scan.pdf', 'data' => '%PDF-1.4 nothing']],
    ]);
    return ($r['reason'] ?? '') === 'no purchase-order number in the reply or the pdf';
})());
t('a stranger who knows the job ref is refused', (function () use ($ref) {
    $r = PoInbox::ingest([
        'subject' => "RE: [{$ref}] Quotation", 'from' => 'attacker@evil.example',
        'attachments' => [['name' => 'PO 4191000258.pdf', 'data' => '%PDF-1.4']],
    ]);
    return ($r['reason'] ?? '') === 'sender is not this customer';
})());
t('the PO number is read',     ($r['po'] ?? '') === '4191000258');
$row = $db->fetchOne("SELECT fulfilment_state, po_number, po_file FROM card_requests WHERE id = ?", [$rid]);
t('the job reached po_received', ($row['fulfilment_state'] ?? '') === 'po_received');
t('the PO number is stored',     ($row['po_number'] ?? '') === '4191000258');
t('the file is kept where nginx will not serve it',
  strpos((string)($row['po_file'] ?? ''), BASE_DIR . '/private/') === 0 && is_file((string)$row['po_file']));

@unlink((string)$row['po_file']);
$db->query("DELETE FROM card_request_events WHERE request_id = ?", [$rid]);
$db->query("DELETE FROM card_requests WHERE id = ?", [$rid]);

echo $fails === 0 ? "\nall passed\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
