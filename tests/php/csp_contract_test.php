<?php
/**
 * Content-Security-Policy, pinned.
 *
 * SecurityHeaders::send() had been in the tree for months, called from six
 * standalone entry points, and every page that goes through ui-header.php sent
 * no CSP at all. Measured zero Content-Security-Policy headers on / and /login
 * on 5 Sep 2026.
 *
 * The policy that first shipped blanked the interactive layer on 16 of 22
 * pages: Alpine evaluates every x- binding as a string, so without
 * 'unsafe-eval' it throws "Alpine Expression Error: Evaluating a string as
 * JavaScript violates the following Content Security Policy directive" and no
 * dropdown, toggle or mobile menu works.
 *
 * script-src dropped 'unsafe-inline' for a per-request nonce on 5 Sep 2026.
 * That needed two sweeps first: 224 on* attribute handlers across 76 files
 * moved to data attributes served by assets/js/cardify-actions.js, and 117
 * executable inline <script> blocks now carry cspNonceAttr(). A nonce disables
 * 'unsafe-inline' for every browser that understands it, so either sweep left
 * half-done would blank the site. All three facts are asserted here.
 */
$root = dirname(__DIR__, 2);
$sec  = file_get_contents($root . '/includes/SecurityHeaders.php');
$hdr  = file_get_contents($root . '/includes/ui-header.php');

$failures = 0;
function cspCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

cspCheck(
    str_contains($hdr, 'SecurityHeaders::send();'),
    'the shared header sends the security headers, so every page gets them'
);
cspCheck(
    strpos($hdr, 'SecurityHeaders::send();') < strpos($hdr, '<!DOCTYPE html>'),
    'it runs before any output, or headers_sent() would refuse it'
);
cspCheck(
    !preg_match('/\?>\s*\n\s*<\?php/', substr($hdr, 0, 2000)),
    'the top of the header emits no whitespace between PHP blocks'
);

// Alpine 3.15.12 compiles every x- binding with new Function, so this one
// stays until the 828 non-trivial expressions are rewritten for @alpinejs/csp.
cspCheck(
    preg_match("/'script' => \[.*?'unsafe-eval'/s", $sec) === 1,
    "script-src keeps 'unsafe-eval', which Alpine needs"
);
cspCheck(
    preg_match("/'script' => \[.*?nonce-/s", $sec) === 1,
    'script-src carries the per-request nonce'
);
cspCheck(
    preg_match("/'script' => \[[^\]]*'unsafe-inline'/s", $sec) !== 1,
    "script-src no longer allows inline script"
);

// The directives that do the work even with inline allowed.
foreach ([
    "default-src 'self'"        => 'a default that refuses unknown origins',
    "base-uri 'self'"           => 'no injected <base>',
    "form-action 'self'"        => 'no cross-origin form post',
    "frame-ancestors 'self'"    => 'clickjacking closed',
    "object-src '"              => 'object-src is set',
    'upgrade-insecure-requests' => 'no mixed content',
] as $needle => $why) {
    cspCheck(str_contains($sec, $needle), $why);
}
cspCheck(
    preg_match("/'object'\s*=> \[\"'none'\"\]/", $sec) === 1,
    'object-src is none, not merely present'
);

// The house font rule, enforced by the policy rather than by habit.
// Match a policy ENTRY, not the comment that explains why the entry is absent.
$googleFontEntry = preg_match("#'https://fonts\\.(googleapis|gstatic)\\.com'#", $sec) === 1;
$wildcardGoogle  = preg_match("#'https://\\*\\.(googleapis|gstatic)\\.com'#", $sec) === 1;
cspCheck(
    !$googleFontEntry,
    "the policy does not allow Google's font hosts"
);
cspCheck(
    !$wildcardGoogle,
    'no googleapis or gstatic wildcard, which would re-admit the font hosts'
);
cspCheck(
    str_contains($sec, 'https://fonts.bhd.om'),
    'the policy allows the font host the house actually uses'
);

// The two sweeps the nonce depends on. Either one regressing blanks the site,
// and neither is visible in the header itself.
$root = dirname(__DIR__, 2);
$handlers = [];
$inlineNoNonce = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/', 'web-react/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false) continue;
    if (preg_match('/\son(click|change|submit|input|load|error|keyup|keydown|focus|blur|mouseover|mouseout|toggle|select|paste)="/', $src)) {
        $handlers[] = $rel;
    }
    // A bare executable <script> with no nonce would simply not run.
    foreach (preg_split('/\R/', $src) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '*' || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) continue;
        if (preg_match('/(?<![\'"])<script>/', $line)) { $inlineNoNonce[] = $rel; break; }
    }
}
cspCheck($handlers === [], 'no on* attribute handler is left anywhere in the tree',
    implode(', ', array_slice($handlers, 0, 6)));
cspCheck($inlineNoNonce === [], 'every executable inline script carries the nonce',
    implode(', ', array_slice($inlineNoNonce, 0, 6)));
cspCheck(
    is_file($root . '/assets/js/cardify-actions.js'),
    'the delegated behaviour module that replaced the handlers is in the tree'
);
cspCheck(
    str_contains($hdr, 'cardify-actions.js'),
    'the shared header loads it, so every public page gets the behaviours'
);
$actions = (string) @file_get_contents($root . '/assets/js/cardify-actions.js');
foreach (['eval(', 'new Function(', 'setTimeout("', 'innerHTML = raw'] as $smell) {
    cspCheck(!str_contains($actions, $smell),
        "cardify-actions.js does not reintroduce {$smell} to replace the handlers");
}


// A PHP tag inside a heredoc or a nowdoc is literal text.
//
// The nonce sweep wrote a short-echo cspNonceAttr() tag into three of them.
// The tag printed as text, the element stopped being a script, and what was
// inside it never ran: the mobile menu toggle on the home page, the
// country/currency helper and the phone-country picker on
// /print-shops/register and /company/register.php. Every page still returned
// 200, so the deploy smoke saw nothing; the responsive crawl found it as
// 4,409px of horizontal overflow at 320px.
$heredocLeaks = [];
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it2 as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/', 'web-react/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false || !str_contains($src, '<?')) continue;
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) continue;
    $depth = 0;
    foreach ($tokens as $t) {
        if (!is_array($t)) continue;
        if ($t[0] === T_START_HEREDOC) { $depth++; continue; }
        if ($t[0] === T_END_HEREDOC)   { $depth = max(0, $depth - 1); continue; }
        // A short-echo tag inside a heredoc is always a mistake. A full
        // "<?php" opener can be deliberate: install/index.php builds config.php
        // that way, and that heredoc IS a PHP file.
        if ($depth > 0 && in_array($t[0], [T_ENCAPSED_AND_WHITESPACE, T_STRING], true)
            && str_contains((string) $t[1], '<' . '?=')) {
            $heredocLeaks[] = $rel . ':' . $t[2];
        }
    }
}
cspCheck(
    $heredocLeaks === [],
    'no PHP tag is written inside a heredoc or nowdoc, where it would print as text',
    implode(', ', array_slice($heredocLeaks, 0, 6))
);

// The same trap one level down: a closing tag inside a // comment ends the
// PHP block, and everything after it becomes output.
$commentLeaks = [];
$it3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it3 as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/', 'web-react/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false) continue;
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) continue;
    foreach ($tokens as $t) {
        if (!is_array($t) || $t[0] !== T_COMMENT) continue;
        $text = (string) $t[1];
        if (str_starts_with(ltrim($text), '//') && str_contains($text, '?>')
            && !str_ends_with(rtrim($text), '?>')) {
            $commentLeaks[] = $rel . ':' . $t[2];
        }
    }
}
cspCheck($commentLeaks === [],
    'no // comment hides a closing PHP tag mid-line',
    implode(', ', array_slice($commentLeaks, 0, 5)));

$emDash = "\xE2\x80\x94";
cspCheck(!str_contains($sec, $emDash), 'includes/SecurityHeaders.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
