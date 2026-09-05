<?php
/**
 * getBasePath(), and the two public routes it was rendering unstyled.
 *
 * It guessed the app root by walking the script path up through a hardcoded
 * list of "known" directories. The list had not kept up. `compare`, `glossary`,
 * `portal`, `cron` and `data` were all missing, so every page under /compare
 * and /glossary computed a base of "/compare/" and asked the browser for
 * /compare/assets/css/cardify-tokens.css. Four of the seven stylesheets, the
 * ambient script and three images 404'd on both routes. Measured live on
 * 5 Sep 2026: the pages returned 200 and rendered without their brand layer.
 *
 * It now derives the base from BASE_DIR against DOCUMENT_ROOT, which is exact.
 * The list survives as a fallback for a host that does not set DOCUMENT_ROOT,
 * and this test keeps it in step with the directories that actually exist.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function bpCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$src = file_get_contents($root . '/includes/functions.php');

bpCheck(
    str_contains($src, "\$_SERVER['DOCUMENT_ROOT']") && str_contains($src, 'BASE_DIR'),
    'getBasePath derives the base from BASE_DIR against DOCUMENT_ROOT'
);

// The fallback list has to name every directory that holds an entry point,
// or a page in it computes a base one level too deep.
preg_match('/\$appDirs = \[(.*?)\];/s', $src, $m);
bpCheck(!empty($m[1]), 'the fallback list is still present and parseable');
$listed = [];
if (!empty($m[1])) {
    preg_match_all("/'([^']+)'/", $m[1], $names);
    $listed = $names[1];
}

$skip = ['assets', 'node_modules', 'vendor', 'tests', 'docs', 'storage', 'uploads',
         'logs', 'tmp', 'lang', 'web-react', '.git', '.worktrees', '.github'];
$missing = [];
foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if (in_array($name, $skip, true)) continue;
    if (glob($dir . '/*.php') === []) continue;
    if (!in_array($name, $listed, true)) $missing[] = $name;
}
bpCheck($missing === [], 'every directory holding a PHP entry point is in the fallback list',
    implode(', ', $missing));

// The exact behaviour, both ways round.
$run = static function (string $docRoot, string $appRoot, string $scriptName): string {
    $code = sprintf(
        '<?php define("BASE_DIR", %s); define("UPLOADS_DIR", BASE_DIR . "/uploads");'
        . ' define("INCLUDES_DIR", BASE_DIR . "/includes"); define("SITE_NAME", "Cardify");'
        . ' $_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["SCRIPT_NAME"] = %s;'
        . ' require %s; echo getBasePath();',
        var_export($appRoot, true), var_export($docRoot, true), var_export($scriptName, true),
        var_export(dirname(__DIR__, 2) . '/includes/functions.php', true)
    );
    $tmp = tempnam(sys_get_temp_dir(), 'bp') . '.php';
    file_put_contents($tmp, $code);
    $out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    return trim($out);
};

$appRoot = $root;
foreach (['/compare/index.php', '/glossary/index.php', '/portal.php', '/index.php',
          '/admin/employees.php', '/tools/vcard-qr-generator.php'] as $script) {
    bpCheck(
        $run($appRoot, $appRoot, $script) === '/',
        "a site served at the domain root computes / for {$script}",
        $run($appRoot, $appRoot, $script)
    );
}

// An install in a subdirectory still resolves to that subdirectory.
bpCheck(
    $run(dirname($appRoot), $appRoot, '/' . basename($appRoot) . '/compare/index.php')
        === '/' . basename($appRoot) . '/',
    'an install one level down still computes its own prefix',
    $run(dirname($appRoot), $appRoot, '/' . basename($appRoot) . '/compare/index.php')
);

$emDash = "\xE2\x80\x94";
bpCheck(!str_contains($src, $emDash), 'includes/functions.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
