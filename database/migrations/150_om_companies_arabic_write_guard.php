<?php
/**
 * Migration 150: refuse Arabic presentation forms at the om_companies write path.
 *
 * r62 repaired 10 name_ar rows written in Arabic Presentation Forms-A/B
 * (U+FB50-FDFF, U+FE70-FEFF). Nothing refused the shape on write, so the next
 * registry import would reintroduce it and every gate would stay green: the
 * strings RENDER correctly and only fail the queries nobody watches (directory
 * search, card pre-fill, any crawler asking in normal Arabic).
 *
 * includes/ArabicText.php normalises for importers that call it. This trigger
 * is for the ones that do not: a script written next month, an ad-hoc mysql
 * session, a restored dump. It fires only on a shape that is never legitimate.
 *
 * Two deliberate limits, so the guard's edges are stated rather than assumed:
 *
 *  1. The UPDATE arm only checks a column whose value CHANGED. Any legacy row
 *     that already held the shape can still have its logo or website updated;
 *     only a write that puts the shape there is refused. On today's table this
 *     is moot (0 rows carry it) and it stops the guard from stranding a row the
 *     way an integrity guard stranded BILL-337.
 *
 *     "CHANGED" must be tested with BINARY. The first version of this
 *     migration used a plain `NOT (NEW.col <=> OLD.col)` and the verify script
 *     caught it on its first run: om_companies is utf8mb4_unicode_ci, and that
 *     collation folds a presentation form onto its normal-block letter, so
 *     `'ﺷﺮﻛﺔ' <=> 'شركة'` is 1. The change-detector therefore reported "not
 *     changed" for the exact write this trigger exists to refuse, and the
 *     UPDATE arm passed the dirty value straight through while the INSERT arm
 *     looked green. Byte comparison is the only one that sees the difference.
 *  2. It covers name_ar and summary_ar, the two Arabic-bearing columns on this
 *     table, measured from SHOW COLUMNS rather than assumed. A new Arabic
 *     column needs a line here, and the sibling gate is what will say so.
 */

require_once __DIR__ . '/../../config.php';

$PATTERN = '[\\\\x{FB50}-\\\\x{FDFF}\\\\x{FE70}-\\\\x{FEFF}]';
// SIGNAL MESSAGE_TEXT is capped at 128 characters; keep the message inside it
// and still name the fix, because a refusal nobody can act on is a wall.
$MSG = 'om_companies: Arabic presentation forms in %s. '
     . 'Pass it through ArabicText::normalize() (NFKC) first.';

$body = static function (string $new, string $old = null) use ($PATTERN, $MSG): string {
    $sql = '';
    foreach (['name_ar', 'summary_ar'] as $col) {
        $changed = $old === null ? '' : " AND NOT (BINARY $new.$col <=> BINARY $old.$col)";
        $sql .= "
  IF $new.$col REGEXP '$PATTERN'$changed THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . sprintf($MSG, $col) . "';
  END IF;";
    }
    return $sql;
};

try {
    $db = Database::getInstance();
    $db->exec("DROP TRIGGER IF EXISTS om_companies_arabic_bi");
    $db->exec("DROP TRIGGER IF EXISTS om_companies_arabic_bu");

    $db->exec("CREATE TRIGGER om_companies_arabic_bi BEFORE INSERT ON om_companies
FOR EACH ROW
BEGIN" . $body('NEW') . "
END");

    $db->exec("CREATE TRIGGER om_companies_arabic_bu BEFORE UPDATE ON om_companies
FOR EACH ROW
BEGIN" . $body('NEW', 'OLD') . "
END");

    echo "Migration 150: om_companies_arabic_bi/bu installed\n";
} catch (Exception $e) {
    echo "Migration 150 failed: " . $e->getMessage() . "\n";
    exit(1);
}
