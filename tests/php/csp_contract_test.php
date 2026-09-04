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
 * dropdown, toggle or mobile menu works. Both facts are asserted here so
 * neither can come back quietly.
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

// Alpine needs both. Losing either one takes the site's interactivity with it.
foreach (["'unsafe-inline'", "'unsafe-eval'"] as $token) {
    cspCheck(
        preg_match("/'script' => \[.*?" . preg_quote($token, '/') . "/s", $sec) === 1,
        "script-src keeps {$token}, which Alpine needs"
    );
}

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

// A nonce would silently disable 'unsafe-inline' and blank the site.
cspCheck(
    !preg_match("/'script' => \[.*?nonce-/s", $sec),
    'script-src carries no nonce while 212 inline scripts remain'
);

$emDash = "\xE2\x80\x94";
cspCheck(!str_contains($sec, $emDash), 'includes/SecurityHeaders.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
