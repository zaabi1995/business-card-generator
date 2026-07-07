<?php
/**
 * Seed the MHD divisions as departments under the parent `mhd` Cardify tenant.
 * Idempotent: upserts by (company_id, name). Sets routing (responsible_email),
 * portal visibility, and the QR default. template_pair_id stays NULL until the
 * shared MHD group-card design is imported (then backfilled here).
 *
 * Run: /www/server/php/83/bin/php scripts/mhd/seed-departments.php
 */
require_once __DIR__ . '/../../config.php';

$db  = Database::getInstance();
$CID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5'; // Mohsin Haider Darwish LLC (slug: mhd)

// name, slug, responsible_email  (routing verified from a year of BHD<->MHD mail)
$divisions = [
    ['ITICS',                              'itics',              'iticsceooffice@mhd.co.om'],
    ['Technology & Communications',        'tech-comm',          'tech.comm@mhd.co.om'],
    ['Office Products',                    'office-products',    'opd@mhd.co.om'],
    ['Infrastructure & Building Systems',  'infrastructure',     'ibs@mhd.co.om'],
    ['Healthcare',                         'healthcare',         'healthcare@mhd.co.om'],
    ['Building Materials',                 'building-materials', 'bmdsales@mhd.co.om'],
    ['EEP',                                'eep',                'eep@mhd.co.om'],
    ['IPD',                                'ipd',                'ipd@mhd.co.om'],
    // No MHD card-order mailbox in the archive -> fallback to BHD sales for now.
    ['Automotive',                         'automotive',         'sales@bhdoman.com'],
    ['Consumer',                           'consumer',           'sales@bhdoman.com'],
    ['Logistics',                          'logistics',          'sales@bhdoman.com'],
];

foreach ($divisions as [$name, $slug, $email]) {
    $row = $db->fetchOne(
        "SELECT id FROM departments WHERE company_id = :c AND name = :n",
        ['c' => $CID, 'n' => $name]
    );
    $data = [
        'name'               => $name,
        'slug'               => $slug,        // portal.php reads d.slug
        'portal_slug'        => $slug,        // admin UI reads portal_slug
        'responsible_email'  => $email,
        'include_qr_default' => 1,
        'portal_enabled'     => 1,
    ];
    if ($row) {
        $db->update('departments', $data, 'id = :id', ['id' => $row['id']]);
        echo "updated  {$name}  ({$email})\n";
    } else {
        $data['id']         = generateUUID();
        $data['company_id'] = $CID;
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->insert('departments', $data);
        echo "inserted {$name}  ({$email})\n";
    }
}

$n = $db->fetchOne("SELECT COUNT(*) AS c FROM departments WHERE company_id = :c AND deleted_at IS NULL", ['c' => $CID]);
echo "Total MHD departments: " . ($n['c'] ?? '?') . "\n";
