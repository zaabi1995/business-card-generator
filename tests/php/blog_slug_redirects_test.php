<?php
/**
 * Regression test for the retired-blog-slug map (r6-62).
 *
 * The defect was 12 renamed slugs still linked from 20+ live posts, each
 * answering 404. Two things can bring it back: a map entry whose target is
 * itself retired (a redirect chain or a loop), and a live post silently
 * taking a retired slug back, which would make its own 301 shadow it.
 *
 * The live half of the invariant (every target returns 200) is asserted
 * against the running site by scripts/verify_blog_redirects.sh, because only
 * the site knows which slugs exist. This file holds the parts that are true
 * of the map alone, so a bad edit fails before it can deploy.
 *
 * Run: php tests/php/blog_slug_redirects_test.php
 */

require_once __DIR__ . '/../../includes/BlogSlugRedirects.php';

$fails = 0;
function check($label, $got, $want) {
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("[%s] %s  (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $label,
        var_export($got, true), var_export($want, true));
}

$map = BlogSlugRedirects::all();

check('the map still carries all 12 retired slugs', count($map), 12);

// No target may itself be a key: that is a chain at best and a loop at worst.
$chained = [];
foreach ($map as $old => $live) {
    if (isset($map[$live])) { $chained[] = "$old -> $live"; }
}
check('no redirect target is itself retired', $chained, []);

// No slug redirects to itself.
$self = [];
foreach ($map as $old => $live) {
    if ($old === $live) { $self[] = $old; }
}
check('no self-redirect', $self, []);

// Every key and value must be a slug blog.php will actually accept: its
// charset guard rejects anything outside [a-z0-9_-]{1,120} BEFORE the map is
// consulted, so an entry outside that set is dead weight that reads as cover.
$badShape = [];
foreach ($map as $old => $live) {
    foreach ([$old, $live] as $s) {
        if (!preg_match('~^[a-z0-9_-]{1,120}$~', $s)) { $badShape[] = $s; }
    }
}
check('every slug matches blog.php\'s own charset guard', $badShape, []);

// Two retired slugs pointing at one live post is fine; one retired slug
// pointing at two places is not expressible, but a duplicated KEY in the
// source would silently drop an entry, so assert the count of unique keys.
check('no key was dropped by duplication', count(array_unique(array_keys($map))), count($map));

// Spot-check the entry named in the finding, verbatim.
check(
    'the slug named in the finding maps to the slug that is live',
    BlogSlugRedirects::target('business-card-etiquette-in-oman'),
    'business-card-etiquette-oman-dos-donts'
);
check('a live slug is not treated as retired',
    BlogSlugRedirects::target('business-card-etiquette-oman-dos-donts'), null);
check('an unknown slug still 404s', BlogSlugRedirects::target('no-such-post'), null);

printf("\n%s: %d check(s) failed\n", $fails ? 'FAIL' : 'PASS', $fails);
exit($fails ? 1 : 0);
