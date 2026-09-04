<?php
/**
 * JSON-LD encoding, and the load-order mistake that came with fixing it.
 *
 * The defect: an employee named "<script>alert(1)</script>" executed on their
 * public card. An HTML parser ends a script element at the first "</script>"
 * byte sequence and does not care that it sits inside a JSON string, so the
 * name closed the ld+json block and the rest of the page parsed as HTML. The
 * title and meta on the same page escaped it correctly; only the JSON-LD did
 * not. Reproduced on cardify.om, 5 Sep 2026.
 *
 * The mistake while fixing it: the sweep that added JsonLd::SAFE to 50 files
 * inserted one require_once INSIDE Seo::hreflang() instead of at file scope.
 * php -l passed, because load order is not a syntax error. ui-header.php calls
 * Seo::organizationScriptOnce(), which does not call hreflang(), so the class
 * was missing and every page died a third of the way through: /login.php came
 * back 10,924 bytes against the healthy 30,328 with no form in it. The deploy
 * script's post-flight smoke caught it and rolled production back untouched.
 *
 * So this file checks two things: that the encoding is safe, and that the class
 * is actually loadable everywhere it is named.
 */
require_once dirname(__DIR__, 2) . '/includes/JsonLd.php';

$root = dirname(__DIR__, 2);
$failures = 0;
function jlCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

// 1. the encoder neutralises the payload and keeps the data intact
$payload = ['name' => '<script>alert(1)</script>', 'title' => "O'Brien & Sons", 'ar' => 'شركة'];
$encoded = JsonLd::encode($payload);
jlCheck(!str_contains($encoded, '</script>'), 'the encoded body cannot close a script element');
jlCheck(!str_contains($encoded, '<script>'), 'the encoded body carries no raw tag');
jlCheck(json_decode($encoded, true) === $payload, 'the data survives the encoding unchanged');
jlCheck(str_contains($encoded, 'شركة'), 'Arabic stays readable, not \\u escaped');

$block = JsonLd::block($payload);
jlCheck(
    substr_count($block, '<script') === 1 && substr_count($block, '</script>') === 1,
    'block() emits exactly one script element'
);
jlCheck(
    str_contains(JsonLd::value('</script><img src=x>'), '\\u003C'),
    'value() escapes a tag for a plain script block too'
);

// 2. the digital card, the one with user data in it
$card = file_get_contents($root . '/digital_card.php');
jlCheck(
    !preg_match('/<\?=\s*json_encode\(/', $card) && str_contains($card, 'JsonLd::encode('),
    'digital_card.php encodes its ld+json through the shared encoder'
);
jlCheck(
    !preg_match('/echo json_encode\(\$(name|employee|position)/', $card),
    'digital_card.php encodes its inline script values through JsonLd::value'
);

// 3. every file naming the constant can load the class at file scope.
//    Grepping for the filename is not enough: that is exactly what passed while
//    the require sat inside a method.
$bad = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false || !str_contains($src, 'JsonLd::')) continue;
    if ($rel === 'includes/JsonLd.php') continue;

    $found = false;
    if (preg_match('#^\s*require_once (?:INCLUDES_DIR|__DIR__)[^;]*JsonLd\.php\';#m', $src, $m, PREG_OFFSET_CAPTURE)) {
        $before = substr($src, 0, $m[0][1]);
        // What breaks is a require inside a FUNCTION body: the class then loads
        // only if that function runs, which is how Seo::hreflang() hid a missing
        // class from php -l and killed every page. A top-level try block always
        // runs, so it is fine. Count function openings that are still unclosed.
        $depth = 0; $inFunction = false; $len = strlen($before);
        $tokens = @token_get_all($before . '}');
        $fnStack = [];
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_FUNCTION) { $fnStack[] = 'pending'; continue; }
            if ($t === '{') { $depth++; if ($fnStack && end($fnStack) === 'pending') { array_pop($fnStack); $fnStack[] = $depth; } }
            elseif ($t === '}') { if ($fnStack && end($fnStack) === $depth) array_pop($fnStack); $depth--; }
        }
        $inFunction = $fnStack !== [];
        if (!$inFunction) $found = true;
    }
    if (!$found) $bad[] = $rel;
}
jlCheck(
    $bad === [],
    'every file naming JsonLd requires it at file scope, not inside a function',
    implode(', ', array_slice($bad, 0, 6))
);

// 4. no ld+json emitter is left on the unsafe flags
$unsafe = [];
foreach ($rii as $file) {}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $file->getPathname());
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/', '.worktrees/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($file->getPathname());
    if ($src === false || !str_contains($src, 'application/ld+json')) continue;
    if (preg_match('/json_encode\([^;]*?JSON_UNESCAPED_SLASHES/s', $src)
        && !str_contains($src, 'JsonLd::')) {
        $unsafe[] = $rel;
    }
}
jlCheck(
    $unsafe === [],
    'no ld+json emitter still encodes without the safe flags',
    implode(', ', array_slice($unsafe, 0, 6))
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
