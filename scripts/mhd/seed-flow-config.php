<?php
/**
 * Per-division routing, BHD-ERP client and rate for the MHD card flow.
 *
 * head_email is the manager copied on the approval email. responsible_email
 * stays the approver, because MHD already orders from the division mailbox and
 * not from individuals: across 3,121 card threads the mailboxes are the senders
 * and they carry the approval wording.
 *
 * erp_client_name is the live BHD-ERP client for that division, so the quotation
 * is raised against the right account. Verified against 365 days of invoices.
 *
 * Research: ~/claude/research/mhd-division-approvers-2026-09-16/
 */
// Absolute, so the script runs the same whether it sits in the repo or is
// staged into /tmp for a one-off run.
$root = is_file(dirname(__DIR__, 2) . '/config.php')
    ? dirname(__DIR__, 2)
    : '/www/wwwroot/cardify.om';
chdir($root);
require_once $root . '/config.php';

$db  = Database::getInstance();
$CID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';

// slug => [head to CC, BHD-ERP client name]
$MAP = [
    'itics'              => ['bipin.k@mhd.co.om',       'MOHSIN HAIDER DARWISH (ITICS)'],
    // The (Technology and Communication Division) account was a duplicate and
    // was merged into this one on 16 Sep 2026, with its VAT number and mailbox.
    'tech-comm'          => ['rajanikanth.r@mhd.co.om', 'Mohsin Haider Darwish LLC.(ITICS-Tech & Comm)'],
    'infrastructure'     => ['himanshu.p@mhd.co.om',    'MOHSIN HAIDER DARWISH (Infrastructure & Building Systems)'],
    'office-products'    => ['sadasivam@mhd.co.om',     'Mohsin Haider Darwish LLC.(ITICS - Office Products Division)'],
    'consumer'           => [null,                      'Mohsin Haider Darwish L.L.C.( Consumer Division )'],
    'logistics'          => [null,                      'MOHSIN HAIDER DARWISH LOGISTICS LLC'],
    'ipd'                => ['devanand.v@mhd.co.om',    'Mohsin Haider Darwish L.L.C.( Building Materials & Industrial Products Division) IPD'],
    'building-materials' => ['devanand.v@mhd.co.om',    'Mohsin Haider Darwish L.L.C.( Building Materials & Industrial Products Division) IPD'],
    'healthcare'         => ['anandha.k@mhd.co.om',     'Mohsin Haider Darwish L.L.C.( Healthcare Division )'],
    'eep'                => ['gokul.p@mhd.co.om',       'MOHSIN HAIDER DARWISH (ITICS)'],
];

foreach ($MAP as $slug => [$head, $erp]) {
    $db->query(
        "UPDATE departments SET head_email = ?, erp_client_name = ?, card_unit_price = 0.030, updated_at = NOW()
          WHERE company_id = ? AND slug = ?",
        [$head, $erp, $CID, $slug]
    );
    printf("%-20s head=%-28s erp=%s\n", $slug, $head ?: '(none)', $erp);
}

// Automotive has never ordered a card from BHD: zero invoices in 365 days, and
// only one or two email threads ever. Ali: "we don't print for them."
$db->query("UPDATE departments SET portal_enabled = 0, updated_at = NOW()
             WHERE company_id = ? AND slug = 'automotive'", [$CID]);
echo "automotive           portal_enabled = 0 (never ordered)\n";

echo "\n-- live divisions --\n";
foreach ($db->fetchAll(
    "SELECT slug, responsible_email, head_email, card_unit_price, portal_enabled
       FROM departments WHERE company_id = ? ORDER BY portal_enabled DESC, slug", [$CID]) as $r) {
    printf("%-20s %-26s %-26s %s  %s\n", $r['slug'], $r['responsible_email'],
        $r['head_email'] ?: '(none)', $r['card_unit_price'],
        $r['portal_enabled'] ? 'live' : 'hidden');
}
