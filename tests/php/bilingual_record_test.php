<?php
/**
 * BilingualRecord: row() refuses, hasTwin() predicts, and the two never disagree.
 *
 * hasTwin() exists because refuse() error_logs and, under php-cli, THROWS. So
 * the English job page could not ask "does the Arabic twin exist" by calling
 * row('ar') speculatively: it would record a refusal no reader ever hit and
 * kill every gate that runs in CLI. The risk of a second entry point is that
 * the two answer differently and hreflang starts naming a URL that renders
 * "no such job" (llm77-1). This file is built to falsify exactly that: every
 * case below is asserted through BOTH paths and the agreement is the test.
 *
 * Run: php tests/php/bilingual_record_test.php
 */

require_once __DIR__ . '/../../includes/BilingualRecord.php';

$fails = 0;

/**
 * row() under php-cli THROWS on a refusal rather than returning null (see
 * refuse()), which is precisely why a speculative twin probe could not use it
 * and why hasTwin() exists. A test that wants row() as an ORACLE has to catch
 * that, and catching it here is itself the proof of the CLI behaviour.
 */
function rowSurvives(array $row, array $fields, string $locale, array $optional): bool {
    try {
        return BilingualRecord::row($row, $fields, 'career_listings', $locale, $optional) !== null;
    } catch (RuntimeException $e) {
        return false;
    }
}

function check($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("[%s] %s  (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

$FIELDS   = ['title', 'description'];
$OPTIONAL = ['requirements', 'location'];

$complete = [
    'slug' => 'full-stack-developer',
    'title' => 'Full Stack Developer',  'title_ar' => 'مطوّر برمجيات متكامل',
    'description' => 'Build things.',   'description_ar' => 'ابنِ أشياء.',
    'requirements' => 'PHP',            'requirements_ar' => 'بي إتش بي',
    'location' => 'Muscat',             'location_ar' => 'مسقط',
];

// --- row() behaviour, unchanged by the r78 refactor into demanded()/missingTwin()

$en = BilingualRecord::row($complete, $FIELDS, 'career_listings', 'en', $OPTIONAL);
check('en is the base row, untouched', $en['title'], 'Full Stack Developer');
check('en carries no _locale marker', array_key_exists('_locale', $en), false);

$ar = BilingualRecord::row($complete, $FIELDS, 'career_listings', 'ar', $OPTIONAL);
check('ar resolves title from title_ar', $ar['title'], 'مطوّر برمجيات متكامل');
check('ar resolves a whenFilled column too', $ar['location'], 'مسقط');
check('ar is marked with its locale', $ar['_locale'], 'ar');

// A blank twin is missing data, not an empty translation.
$blank = $complete; $blank['description_ar'] = '   ';
BilingualRecord::resetRefusals();
check('a whitespace-only twin refuses the row', rowSurvives($blank, $FIELDS, 'ar', $OPTIONAL), false);
check('the refusal names the column', BilingualRecord::refusals()[0]['missing'] ?? '', 'description_ar');

// An OPTIONAL column that is blank in the base is not owed a twin.
$noReqs = $complete; $noReqs['requirements'] = ''; unset($noReqs['requirements_ar']);
check('a blank optional column owes no twin', rowSurvives($noReqs, $FIELDS, 'ar', $OPTIONAL), true);

// ...but a FILLED one does, and a column absent from the SELECT is a caller bug.
$reqsUntwinned = $complete; unset($reqsUntwinned['requirements_ar']);
BilingualRecord::resetRefusals();
check('a filled optional column with no twin refuses', rowSurvives($reqsUntwinned, $FIELDS, 'ar', $OPTIONAL), false);
check('and says the column was not selected', BilingualRecord::refusals()[0]['missing'] ?? '', 'requirements_ar (not selected)');

// --- hasTwin() must agree with row() on every one of them, with no side effects

$cases = [
    'complete row'                  => $complete,
    'whitespace-only twin'          => $blank,
    'blank optional, no twin'       => $noReqs,
    'filled optional, no twin'      => $reqsUntwinned,
    'title twin missing'            => array_diff_key($complete, ['title_ar' => 1]),
];
BilingualRecord::resetRefusals();
foreach ($cases as $label => $row) {
    // row() is the oracle. It is allowed to record refusals; hasTwin() is not.
    $survives = rowSurvives($row, $FIELDS, 'ar', $OPTIONAL);
    $refusalsAfterOracle = count(BilingualRecord::refusals());
    $predicted = BilingualRecord::hasTwin($row, $FIELDS, 'ar', $OPTIONAL);
    check("hasTwin agrees with row on: $label", $predicted, $survives);
    check("hasTwin recorded nothing on: $label", count(BilingualRecord::refusals()), $refusalsAfterOracle);
}

// The English page asks about 'ar' while serving 'en'; that must not be a no-op.
check('hasTwin(en) is trivially true', BilingualRecord::hasTwin($blank, $FIELDS, 'en', $OPTIONAL), true);
check('hasTwin(ar) on the same row is false', BilingualRecord::hasTwin($blank, $FIELDS, 'ar', $OPTIONAL), false);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
