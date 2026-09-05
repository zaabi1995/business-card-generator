<?php
/**
 * Every delegated handler must be able to find what it calls.
 *
 * assets/js/cardify-actions.js resolves a data-fn name on `window`, because a
 * name is a property lookup and never an evaluated string. That works for a
 * `function foo()` declaration, which is hoisted onto window, and for anything
 * explicitly assigned to it. It does NOT work for a top-level `const`: a
 * const in a classic script creates a script-scope binding and no window
 * property at all.
 *
 * The conversion of the 224 on* handlers hit exactly one of those.
 * /print-shops/register carried
 * onchange="CardifyGeo.updateCurrencyFromCountry(this.value, 'register-currency')"
 * and Currency.php declared `const CardifyGeo = {...}`, so the delegated
 * handler looked up window.CardifyGeo, found nothing, and the currency field
 * quietly stopped filling itself in. Read off the live page on 5 Sep 2026:
 * typeof CardifyGeo was "object" and typeof window.CardifyGeo "undefined".
 *
 * This walks every data-fn in the tree and checks the name is reachable.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function actCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$names = [];
$blob = '';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if (!in_array($file->getExtension(), ['php', 'js'], true)) continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['vendor/', 'node_modules/', '.git/', '.worktrees/', 'web-react/', 'tests/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false) continue;
    $blob .= $src . "\n";
    if (!str_ends_with($rel, '.php')) continue;
    if (preg_match_all('/data-(?:fn|cardify-(?:change|blur|keyup)-fn)="([^"]+)"/', $src, $m)) {
        foreach ($m[1] as $n) $names[$n] = $rel;
    }
    if (preg_match_all('/data-(?:fns|cardify-change-fns)="([^"]+)"/', $src, $m2)) {
        foreach ($m2[1] as $raw) {
            if (preg_match_all('/&quot;([^&]+)&quot;|"([^"]+)"/', $raw, $m3)) {
                foreach ($m3[1] as $i => $a) {
                    $n = $a !== '' ? $a : $m3[2][$i];
                    if ($n !== '') $names[$n] = $rel;
                }
            }
        }
    }
}

actCheck(count($names) > 40, 'the tree carries the converted handler targets', (string) count($names));

$unreachable = [];
foreach ($names as $name => $where) {
    $base = explode('.', $name)[0];
    $q = preg_quote($base, '/');
    $isFunction = (bool) preg_match('/\bfunction\s+' . $q . '\s*\(/', $blob);
    $onWindow   = (bool) preg_match('/\bwindow\.' . $q . '\s*=/', $blob);
    if ($isFunction || $onWindow) continue;
    $unreachable[] = $name . ' (' . $where . ')';
}
actCheck($unreachable === [],
    'every data-fn name is a function declaration or is assigned to window',
    implode(', ', array_slice($unreachable, 0, 6)));

// The specific one that broke, pinned by name.
$currency = file_get_contents($root . '/includes/Currency.php');
actCheck(str_contains($currency, 'window.CardifyGeo = {'),
    'CardifyGeo is on window, not a lexical const');
actCheck(str_contains($currency, 'window.CardifyPhoneInput = {'),
    'CardifyPhoneInput is on window too');
actCheck(!preg_match('/^const CardifyGeo/m', $currency),
    'the const declaration is gone, so there is one handle not two');

// The resolver itself must stay a lookup, never an evaluation.
$actions = file_get_contents($root . '/assets/js/cardify-actions.js');
actCheck(str_contains($actions, 'var fn = window[name];'),
    'the resolver reads the name off window');
actCheck((bool) preg_match('/\/\^\[A-Za-z_\$\]\[\\\\w\$\]\*\(\\\\\.\[A-Za-z_\$\]\[\\\\w\$\]\*\)\?\$\//', $actions),
    'the name is validated as one identifier, or two joined by a single dot');

// A block built before ui-header.php runs has to load SecurityHeaders itself,
// or cspNonceAttr() does not exist yet and the script ships with no nonce.
$reg = file_get_contents($root . '/company/register.php');
actCheck(
    strpos($reg, "require_once INCLUDES_DIR . '/SecurityHeaders.php';") < strpos($reg, '$extraHead = <<<HTML'),
    'company/register.php loads SecurityHeaders before it builds its head block'
);


// A dotted name has to name a method that exists, not just an object that
// does. The handler on /print-shops/register called
// CardifyGeo.updateCurrencyFromCountry, which is in no file in the tree: the
// method is updateCurrency, and it takes the select element rather than its
// value. The currency field on that form had never auto-filled.
$dotted = [];
foreach ($names as $name => $where) {
    if (!str_contains($name, '.')) continue;
    [$obj, $method] = explode('.', $name, 2);
    if (!preg_match('/\b' . preg_quote($method, '/') . '\s*:\s*function/', $blob)
        && !preg_match('/\b' . preg_quote($method, '/') . '\s*\(/', $blob)) {
        $dotted[] = $name . ' (' . $where . ')';
    }
}
actCheck($dotted === [], 'every dotted data-fn names a method that exists',
    implode(', ', $dotted));

$actionsSrc = file_get_contents($root . '/assets/js/cardify-actions.js');
actCheck(str_contains($actionsSrc, "'__SELF_EL__'"),
    'a mixed argument list can carry the element itself, not only its value');

$emDash = "\xE2\x80\x94";
actCheck(!str_contains($actions, $emDash), 'assets/js/cardify-actions.js contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
