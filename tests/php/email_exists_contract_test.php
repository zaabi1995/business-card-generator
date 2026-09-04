<?php
/**
 * Auth::emailExists() returns an array and never a bool, so every caller has to
 * read ['exists']. printshop/register.php tested the return value directly, and
 * a non-empty array is truthy, so the branch fired for every address ever
 * submitted: print-shop registration answered "This email is already
 * registered" to an email present in no table. Reproduced live on 4 Sep 2026
 * with press@gauntlet-press-sep2026.com, absent from users, print_shops,
 * companies and employees. One print shop exists in production, seeded by a
 * migration in January 2026; no signup had ever succeeded.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function eeCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$auth = file_get_contents($root . '/includes/Auth.php');
eeCheck(
    str_contains($auth, "return ['exists' => false, 'type' => null];"),
    'emailExists still returns an array on the not-found path'
);

$callers = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    // Match on the path RELATIVE to the repo root. Matching the absolute path
    // skipped everything, because this checkout lives under a .worktrees
    // directory and that was on the skip list.
    $rel = str_replace($root . '/', '', $path);
    foreach (['tests/', 'vendor/', 'node_modules/', '.git/'] as $skip) {
        if (str_starts_with($rel, $skip)) continue 2;
    }
    $src = @file_get_contents($path);
    if ($src === false || !str_contains($src, 'emailExists(')) continue;
    foreach (explode("\n", $src) as $n => $line) {
        $trimmed = trim($line);
        if (!preg_match('/emailExists\s*\(/', $trimmed)) continue;
        if (str_contains($trimmed, 'function emailExists')) continue;
        // Comments name the function while explaining it. They are not calls.
        if (preg_match('#^(//|\*|/\*|\#)#', $trimmed)) continue;
        $callers[] = [$rel, $n + 1, $trimmed];
    }
}
eeCheck($callers !== [], 'the sweep found the call sites', count($callers) . ' found');

$bare = [];
foreach ($callers as [$rel, $line, $code]) {
    // Acceptable: indexed with ['exists'] on the spot, or assigned to a variable
    // that the next lines index.
    $indexed  = preg_match("/emailExists\([^)]*\)\s*\[\s*'exists'\s*\]/", $code) === 1;
    $assigned = preg_match('/\$\w+\s*=\s*(?:\\\\?\w+::)?emailExists\(/', $code) === 1;
    if (!$indexed && !$assigned) $bare[] = "{$rel}:{$line}";
}
eeCheck(
    $bare === [],
    'no caller treats the array return value as a boolean',
    implode(', ', $bare)
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
