<?php
/**
 * r63 / llm62-1: prove the om_companies Arabic write guard, on both arms.
 *
 * llm52-3 and llm56-2 both cost a round to a guard that was trusted before it
 * was run: one grepped where its comment claimed it parsed, the other asserted
 * an identity the site never made. So this runs the guard against a known-good
 * input and a known-bad one before anything depends on it, and it is committed
 * so the next round can re-run it rather than re-reason about it.
 *
 * Four arms, all against the LIVE table inside a transaction that is always
 * rolled back:
 *   1. BROKEN: a raw INSERT of a presentation-form name must be REFUSED.
 *   2. BROKEN: a raw UPDATE putting the shape onto a row must be REFUSED.
 *   3. WORKING: the same name through ArabicText::normalize() must be ACCEPTED
 *      and must land in the normal Arabic block.
 *   4. CONTROL: an ordinary Arabic name must insert untouched, and an UPDATE
 *      that does not touch an Arabic column must still work. A guard that
 *      refuses everything is not a guard, it is an outage.
 *
 * Run: /www/server/php/83/bin/php scripts/verify-arabic-write-guard.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ArabicText.php';

$pdo = Database::getInstance()->getConnection();

$CLEAN_AR = 'شركة الاختبار للتقنية ش.م.م';

/**
 * Build the r62 shape rather than hand-typing codepoints.
 *
 * The first version of this script pasted a guessed run of U+FE.. characters
 * and its comment claimed they spelled "شركة النهضة". They did not: NFKC
 * folded them to "شرلة الؤضضة", so the arm was green while testing a string
 * nobody meant. Derive the map instead, by scanning Presentation Forms-A/B for
 * the codepoints whose own NFKC is exactly the letter wanted. Then the dirty
 * string is dirty BY CONSTRUCTION and folds back to a stated expected value.
 */
function presentationFormOf(string $letter): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ([[0xFB50, 0xFDFF], [0xFE70, 0xFEFF]] as [$lo, $hi]) {
            for ($cp = $lo; $cp <= $hi; $cp++) {
                $ch = mb_chr($cp, 'UTF-8');
                $folded = Normalizer::normalize($ch, Normalizer::FORM_KC);
                // single-letter folds only: the ligatures fold to several
                // letters and would change the word being spelled
                if ($folded !== false && mb_strlen($folded) === 1 && !isset($map[$folded])) {
                    $map[$folded] = $ch;
                }
            }
        }
    }
    return $map[$letter] ?? $letter;
}

$EXPECT_CLEAN = 'شركة النهضة';
$DIRTY = implode('', array_map(
    fn($c) => presentationFormOf($c),
    preg_split('//u', $EXPECT_CLEAN, -1, PREG_SPLIT_NO_EMPTY)
));
$SLUG = 'r63-arabic-guard-probe-' . bin2hex(random_bytes(4));

$results = [];
$rec = function (string $arm, bool $ok, string $detail) use (&$results) {
    $results[] = [$arm, $ok, $detail];
    printf("%-5s %-42s %s\n", $ok ? 'ok' : 'FAIL', $arm, $detail);
};

// arm 0: the fixture itself. A test whose input is not what it says it is
// reports on nothing, which is how the first run of this script passed an arm.
$rec('FIXTURE is dirty and folds to the word',
     ArabicText::hasPresentationForms($DIRTY)
       && ArabicText::normalize($DIRTY) === $EXPECT_CLEAN,
     "'$DIRTY' -> '" . ArabicText::normalize($DIRTY) . "' (want '$EXPECT_CLEAN')");

$pdo->beginTransaction();
try {
    $insert = "INSERT INTO om_companies (name_en, name_ar, slug, sector, wilayat, size_bucket, curated)
               VALUES (:en, :ar, :s, 'other', 'muscat', 'medium', 0)";

    // --- arm 1: raw INSERT of the dirty shape must be refused -------------
    try {
        $pdo->prepare($insert)->execute([':en' => 'r63 probe', ':ar' => $DIRTY, ':s' => $SLUG . '-a']);
        $rec('BROKEN insert refused', false, 'the trigger let the shape through');
    } catch (PDOException $e) {
        $rec('BROKEN insert refused', str_contains($e->getMessage(), 'presentation forms'),
             substr($e->getMessage(), 0, 90));
    }

    // --- arm 3: the same name via normalize() must be accepted ------------
    $normalised = ArabicText::normalize($DIRTY);
    try {
        $pdo->prepare($insert)->execute([':en' => 'r63 probe', ':ar' => $normalised, ':s' => $SLUG . '-b']);
        $id = (int) $pdo->lastInsertId();
        $back = $pdo->query("SELECT name_ar FROM om_companies WHERE id = $id")->fetchColumn();
        $rec('WORKING normalise accepted', $id > 0 && $back === $EXPECT_CLEAN, "stored: $back");
    } catch (PDOException $e) {
        $id = 0;
        $rec('WORKING normalise accepted', false, substr($e->getMessage(), 0, 90));
    }

    // --- arm 2: raw UPDATE putting the shape on must be refused -----------
    if ($id) {
        try {
            $pdo->prepare("UPDATE om_companies SET name_ar = :ar WHERE id = :id")
                ->execute([':ar' => $DIRTY, ':id' => $id]);
            $rec('BROKEN update refused', false, 'the trigger let the shape through');
        } catch (PDOException $e) {
            $rec('BROKEN update refused', str_contains($e->getMessage(), 'presentation forms'),
                 substr($e->getMessage(), 0, 90));
        }

        // --- arm 4b: an UPDATE that does not touch an Arabic column ------
        try {
            $pdo->prepare("UPDATE om_companies SET logo_status = 'none' WHERE id = :id")
                ->execute([':id' => $id]);
            $rec('CONTROL non-Arabic update passes', true, 'logo_status updated');
        } catch (PDOException $e) {
            $rec('CONTROL non-Arabic update passes', false, substr($e->getMessage(), 0, 90));
        }
    }

    // --- arm 4a: an ordinary Arabic name must insert untouched ------------
    try {
        $pdo->prepare($insert)->execute([':en' => 'r63 control', ':ar' => $CLEAN_AR, ':s' => $SLUG . '-c']);
        $back = $pdo->query("SELECT name_ar FROM om_companies WHERE slug = " . $pdo->quote($SLUG . '-c'))->fetchColumn();
        $rec('CONTROL ordinary Arabic passes', $back === $CLEAN_AR, "stored byte-identical: " . var_export($back === $CLEAN_AR, true));
    } catch (PDOException $e) {
        $rec('CONTROL ordinary Arabic passes', false, substr($e->getMessage(), 0, 90));
    }
} finally {
    // Nothing this script writes survives it. The guard is proved on the live
    // table because that is where the trigger lives, not on a copy of it.
    $pdo->rollBack();
}

$left = (int) $pdo->query(
    "SELECT COUNT(*) FROM om_companies WHERE slug LIKE " . $pdo->quote($SLUG . '%')
)->fetchColumn();
$rec('rollback left nothing behind', $left === 0, "$left row(s)");

// --- arm 5: every caller actually LOADS the class --------------------------
// php -l cannot see this: an unloaded class is a runtime fatal, and two of the
// five importers were wired in this round without their require, because they
// use `require '<abs path>'` where the others use `require_once INCLUDES_DIR`.
// Both linted clean. A wiring change that lints green and dies on the next
// import is the shape this arm exists to refuse.
$callers = [];
foreach (['scripts', 'includes', 'admin/super/logos'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') ?: [] as $f) {
        if (basename($f) === 'ArabicText.php') { continue; }  // it is the class
        $src = file_get_contents($f);
        if (str_contains($src, 'ArabicText::') && !str_contains($src, 'ArabicText.php')) {
            $callers[] = basename($f);
        }
    }
}
$rec('every ArabicText caller loads it', $callers === [],
     $callers ? implode(', ', $callers) : 'all callers require the class');

// --- arm 6: the live table is still clean ----------------------------------
$dirtyRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM om_companies
      WHERE name_ar REGEXP '[\\\\x{FB50}-\\\\x{FDFF}\\\\x{FE70}-\\\\x{FEFF}]'
         OR summary_ar REGEXP '[\\\\x{FB50}-\\\\x{FDFF}\\\\x{FE70}-\\\\x{FEFF}]'"
)->fetchColumn();
$rec('live table holds no presentation forms', $dirtyRows === 0, "$dirtyRows row(s)");

$failed = count(array_filter($results, fn($r) => !$r[1]));
echo $failed === 0
    ? "\nGUARD PROVED: refuses the shape, accepts the normalised form and ordinary Arabic.\n"
    : "\n$failed arm(s) FAILED; the guard is not trustworthy yet.\n";
exit($failed === 0 ? 0 : 1);
