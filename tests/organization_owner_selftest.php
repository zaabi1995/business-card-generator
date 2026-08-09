<?php
/**
 * r153 / llm148-1: ONE owner for https://cardify.om/#organization.
 *
 * The defect this exists to stop, measured on the live bytes 10 Aug 2026
 * (bhd-seo-award/evidence/probe_r153_context_phantom.py): FIVE surfaces
 * published a body for Cardify's identity and three of them disagreed with the
 * owner.
 *
 *   includes/Seo.php          24 keys   THE OWNER
 *   press.php                 12 keys   @type Organization only, "for the GCC"
 *   blog.php                   5 keys   logo as an ImageObject, url with no /
 *   gcc-business-index.php    10 keys   NO @id at all, a different logo file
 *   /solutions/*              23 keys   the owner, correct (in a @graph)
 *
 * The @id is the estate's only join key, so a resolver reading the press kit
 * and the home page was told two different things about one company. The
 * anonymous body on gcc-business-index.php was the worst of them precisely
 * because it carried no @id: entity_graph_gate's divergent-@id arm could not
 * see it however wide its population grew.
 *
 * This is a SOURCE test, not a fetch test, and deliberately so: a fetch test
 * only sees the pages someone remembered to list, and the recurrence mechanic
 * on this family has always been a surface nobody enumerated. Here the
 * population is every .php file in the tree.
 *
 * RED BY CONSTRUCTION: the fixture at the bottom re-creates the press.php
 * shape and the test must fail on it. A guard that has never been shown
 * failing is a comment.
 */
$root = dirname(__DIR__);
$fails = 0;
$checked = 0;

function ok(bool $cond, string $label): void
{
    global $fails;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$cond) $fails++;
}

/**
 * Does this source text author a BODY (not a reference) under the org @id?
 *
 * A reference is `['@id' => ...]` alone, or a call into the owner. A body is
 * an array literal that carries the @id AND at least one descriptive key.
 * Also flags an ANONYMOUS Organization body naming Cardify: no @id is not a
 * smaller claim, it is the same claim with nothing to join it to.
 */
function authorsOrgBody(string $src): array
{
    $found = [];
    // 1. a literal '@id' => 'https://cardify.om/#organization' sitting in the
    //    same array literal as a descriptive key.
    if (preg_match_all('~\[[^\[\]]*?#organization\'[^\[\]]*?\]~s', $src, $m)) {
        foreach ($m[0] as $blk) {
            foreach (["'name'", "'logo'", "'description'", "'url'", "'@type'"] as $k) {
                if (str_contains($blk, $k)) { $found[] = 'body under the org @id'; break; }
            }
        }
    }
    // 2. an anonymous Organization array literal that names Cardify.
    if (preg_match_all("~'@type'\s*=>\s*'Organization'~", $src, $m2)) {
        foreach ($m2[0] as $_) {
            if (preg_match("~'@type'\s*=>\s*'Organization',\s*\n\s*'name'\s*=>\s*'Cardify'~", $src)) {
                $found[] = 'anonymous Organization body naming Cardify';
                break;
            }
        }
    }
    return $found;
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$offenders = [];
foreach ($rii as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php')) continue;
    $rel = substr($path, strlen($root) + 1);
    // The OWNER may author it. Vendor, tests and any nested checkout may not
    // be judged: a second working tree is another session's business (llm117-1).
    if (str_starts_with($rel, 'includes/Seo.php')) continue;
    foreach (['vendor/', 'tests/', 'node_modules/', 'origin/', '.worktrees/', 'worktrees/'] as $skip) {
        if (str_contains($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($path);
    if ($src === false) continue;
    $checked++;
    foreach (authorsOrgBody($src) as $why) {
        $offenders[] = "$rel: $why";
    }
}

ok($offenders === [],
   'no file outside includes/Seo.php authors a body for the Cardify identity'
   . ($offenders ? " (got:\n    " . implode("\n    ", array_unique($offenders)) . ')' : ''));
ok($checked > 50, "the population is the whole tree, not a list (checked $checked file(s))");

// RED BY CONSTRUCTION: the exact shape press.php carried until r153.
$fixture = <<<'PHP'
$orgLd = [
    '@type'    => 'Organization',
    'name'     => 'Cardify',
    'url'      => 'https://cardify.om/',
    '@id' => 'https://cardify.om/#organization',
];
PHP;
ok(authorsOrgBody($fixture) !== [],
   'RED-BY-CONSTRUCTION: the press.php shape is detected as a rival body');

$anon = "\$orgLd = [\n    '@type' => 'Organization',\n    'name' => 'Cardify',\n];";
ok(authorsOrgBody($anon) !== [],
   'RED-BY-CONSTRUCTION: an anonymous Organization naming Cardify is detected');

// ...and the negative control, so the test is not simply always red.
$ref = "'publisher' => ['@id' => 'https://cardify.om/#organization'],";
ok(authorsOrgBody($ref) === [],
   'a bare @id reference is NOT a body (the fix must be allowed to pass)');

$owner = "\$orgLd = ['@context' => 'https://schema.org'] + Seo::organizationNode();";
ok(authorsOrgBody($owner) === [],
   'rendering the owner is NOT a body of its own');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
