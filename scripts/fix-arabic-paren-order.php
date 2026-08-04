<?php
/**
 * r63 / llm62-2: repair om_companies name_ar rows whose parentheses were
 * stored mirrored, in logical order, by the registry extraction.
 *
 * r62 found row 1218 ("ألفاللرخام )شركة منطقة حره( ش م م") and deliberately did
 * NOT hand-edit it, because one hand-edit would have hidden the question of how
 * many rows carry the shape. That question is now answered, on the population
 * rather than on the visible row: evidence/r63/probe_arabic_write_path.py walks
 * all 2,502 rows and both Arabic columns and finds THREE pairs, all in name_ar.
 *
 * The first detector tried was a regex for ')...(' and it was wrong: any string
 * with two ordinary pairs contains that sequence, so it scored three legitimate
 * Arabic summaries as mangled. Paren BALANCE is the test, and it splits the
 * three real rows into two shapes that need two different transforms:
 *
 *   unopened  a ')' arrives at depth 0. Every paren glyph in the string is
 *             mirrored, so swapping '(' <-> ')' restores it.
 *               1218  ألفاللرخام )شركة منطقة حره( ش م م
 *               1318  ...اللوجستية) المنطقة الحره بصلاله ( ش م م
 *   unclosed  the string ends open because the trailing ')' was carried to the
 *             FRONT as '('. Swapping would mirror the pairs that are already
 *             right; the repair is to move the orphan.
 *               216   ( مجموعة الخليج للتأمين (الخليج) ش.م.ب مقفلة (فرع عمان
 *
 * Every repair is checked twice before it is written: the result must balance,
 * and its pair count must equal the pair count of the row's own English name,
 * which is the independent witness for how many parenthetical groups the name
 * has. A row failing either check is printed and skipped, never guessed at.
 *
 * NOT repaired here: the missing space in "ألفاللرخام" (should be "ألفا للرخام",
 * per name_en "ALPHA MARBLE"). That is a word-boundary loss from the same
 * extraction and needs its own population measurement; fixing it inside a paren
 * script would be the single-row hand-edit r62 refused, wearing a different hat.
 *
 * Run:  php scripts/fix-arabic-paren-order.php          (dry, default)
 *       php scripts/fix-arabic-paren-order.php --apply
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ArabicText.php';

$APPLY = in_array('--apply', $argv, true);
$pdo = Database::getInstance()->getConnection();

function pairCount(string $s): int
{
    return min(substr_count($s, '('), substr_count($s, ')'));
}

/** Mirrored glyphs: swap every paren. */
function swapParens(string $s): string
{
    return strtr($s, ['(' => ')', ')' => '(']);
}

/** Orphan leading '(' that is really the trailing ')'. */
function moveOrphan(string $s): string
{
    $t = ltrim($s);
    if (!str_starts_with($t, '(')) {
        return $s;
    }
    return rtrim(ltrim(substr($t, 1))) . ')';
}

/** Tidy "word(" -> "word (" and "( x )" -> "(x)" without touching the letters. */
function tidySpacing(string $s): string
{
    $s = preg_replace('/\(\s+/u', '(', $s);
    $s = preg_replace('/\s+\)/u', ')', $s);
    $s = preg_replace('/(\S)\(/u', '$1 (', $s);
    $s = preg_replace('/\)(\S)/u', ') $1', $s);
    return preg_replace('/\s{2,}/u', ' ', trim($s));
}

$rows = $pdo->query("SELECT id, name_en, name_ar FROM om_companies WHERE name_ar LIKE '%(%' OR name_ar LIKE '%)%'")
            ->fetchAll(PDO::FETCH_ASSOC);

$repaired = $skipped = $clean = 0;
foreach ($rows as $r) {
    $fault = ArabicText::parenFault($r['name_ar']);
    if ($fault === null) { $clean++; continue; }

    $fixed = $fault === 'unopened' ? swapParens($r['name_ar']) : moveOrphan($r['name_ar']);
    $fixed = tidySpacing($fixed);

    $balanced = ArabicText::parenFault($fixed) === null;
    $matches  = pairCount($fixed) === pairCount($r['name_en'] ?? '');

    printf("id=%-5d [%s]\n  before: %s\n  after : %s\n  en    : %s\n"
         . "  balanced=%s pairs_match_en=%s (%d vs %d)\n",
        $r['id'], $fault, $r['name_ar'], $fixed, $r['name_en'],
        var_export($balanced, true), var_export($matches, true),
        pairCount($fixed), pairCount($r['name_en'] ?? ''));

    if (!$balanced || !$matches) {
        echo "  -> SKIPPED, the transform did not prove itself\n\n";
        $skipped++;
        continue;
    }
    if ($APPLY) {
        $pdo->prepare("UPDATE om_companies SET name_ar = :n, updated_at = NOW() WHERE id = :id")
            ->execute([':n' => $fixed, ':id' => $r['id']]);
        echo "  -> WRITTEN\n\n";
    } else {
        echo "  -> would write (dry run)\n\n";
    }
    $repaired++;
}

printf("%d row(s) with parens scanned: %d clean, %d repaired%s, %d skipped\n",
    count($rows), $clean, $repaired, $APPLY ? '' : ' (dry)', $skipped);
exit($skipped > 0 ? 1 : 0);
