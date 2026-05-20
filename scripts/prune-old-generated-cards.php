<?php
/**
 * One-time + repeatable cleanup: keep only the latest generated_cards row per
 * employee, deleting older rows and their orphaned on-disk PNG/PDF files.
 *
 * Every consumer (CardRenderer::forEmployee, digital_card.php, card-pdf.php)
 * reads only ORDER BY generated_at DESC LIMIT 1, so every older row + file is
 * dead storage. Going forward logGeneratedCard() prunes inline; this script
 * cleans up the backlog and is safe to re-run.
 *
 * Usage:
 *   php scripts/prune-old-generated-cards.php           # dry run (report only)
 *   php scripts/prune-old-generated-cards.php --apply    # actually delete
 */
require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance();

// Employees that have more than one card row.
$dupes = $db->fetchAll(
    "SELECT employee_id, company_id, COUNT(*) AS n
       FROM generated_cards
      GROUP BY employee_id, company_id
     HAVING n > 1"
);

$rowsToDelete = 0;
$filesDeleted = 0;
$bytesFreed = 0;

foreach ($dupes as $d) {
    $employeeId = $d['employee_id'];
    $companyId  = $d['company_id'];

    // All rows for this employee, newest first. Keep [0], prune the rest.
    $rows = $db->fetchAll(
        "SELECT * FROM generated_cards
          WHERE employee_id = :eid AND company_id = :cid
          ORDER BY generated_at DESC",
        ['eid' => $employeeId, 'cid' => $companyId]
    );
    $keep = array_shift($rows);
    if (empty($rows)) {
        continue;
    }

    // Files the surviving row points at, never delete these.
    $protected = [];
    foreach (['front_file_path', 'back_file_path', 'front_web_path', 'back_web_path', 'pdf_file_path'] as $k) {
        if (!empty($keep[$k])) {
            $protected[basename((string) $keep[$k])] = true;
        }
    }

    $cardsDir = COMPANIES_UPLOADS_DIR . '/' . $companyId . '/cards';

    foreach ($rows as $row) {
        foreach (['front_file_path', 'back_file_path', 'front_web_path', 'back_web_path', 'pdf_file_path'] as $k) {
            if (empty($row[$k])) {
                continue;
            }
            $name = basename((string) $row[$k]);
            if ($name === '' || isset($protected[$name])) {
                continue;
            }
            $fs = $cardsDir . '/' . $name;
            if (is_file($fs)) {
                $bytesFreed += (int) @filesize($fs);
                $filesDeleted++;
                if ($apply) {
                    @unlink($fs);
                }
            }
        }
        $rowsToDelete++;
        if ($apply) {
            $db->delete('generated_cards', 'id = :id', ['id' => $row['id']]);
        }
    }
}

printf(
    "%s employees with dupes: %d | stale rows: %d | orphan files: %d | space: %.2f MB\n",
    $apply ? '[APPLIED]' : '[DRY RUN]',
    count($dupes),
    $rowsToDelete,
    $filesDeleted,
    $bytesFreed / 1048576
);
if (!$apply) {
    echo "Re-run with --apply to delete.\n";
}
