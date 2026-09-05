<?php
/**
 * A generated card must never print the person whose design was imported.
 *
 * `detected_text` is the sample lifted out of the source PDF when a template is
 * imported: the words that were on the card the design came from. The PNG
 * renderer used it as the fallback for ANY field the employee had left empty,
 * per-person fields included.
 *
 * It was live. Ahmed Al-Siyabi at aedoman.cardify.om has no Arabic name, and
 * his generated card back carried "علي محمد المجيني", the Arabic name of the
 * person whose card the design was imported from. Read off the baked PNG on
 * 5 Sep 2026.
 *
 * scripts/render-card-pdf.py had already been fixed for exactly this in the
 * vector path, with a comment naming the case ("Al Maha's single-line job
 * titles were inheriting the three-line designation of the employee the design
 * came from"). The PNG path, which is what the digital card, the wallet strip,
 * the og:image and the print preview all read, was left as it was. This test
 * holds both renderers to the same rule.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function dtCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$py = trim((string) @shell_exec('command -v python3 2>/dev/null'));

// Both renderers draw the same distinction, from the same list.
foreach (['scripts/render-card-images.py', 'scripts/render-card-pdf.py'] as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    dtCheck(str_contains($src, '_TENANT_CONSTANT_BASES'), "{$rel} names the tenant-constant fields");
    dtCheck(
        preg_match("/_TENANT_CONSTANT_BASES\s*=\s*\(\s*'website',\s*'company',\s*'address',\s*'fax',\s*'social'\s*\)/", $src)
        || preg_match('/_TENANT_CONSTANT_BASES\s*=\s*\(\s*"website",\s*"company",\s*"address",\s*"fax",\s*"social"\s*\)/', $src),
        "{$rel} lists the same five constants"
    );
}

if ($py === '') {
    echo "SKIP  python3 not available for the behavioural half\n";
    echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
    exit($failures === 0 ? 0 : 1);
}

// Drive the resolver itself.
$driver = <<<'PY'
import json, sys, importlib.util
spec = importlib.util.spec_from_file_location("rci", sys.argv[1])
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
emp = {"name_en": "Ahmed Al-Siyabi", "name_ar": None, "position_en": "Senior Engineer"}
payload = {"employee": emp, "company": {"name_en": "AED Oman", "default_website": "www.aedoman.com",
                                        "default_address_en": "Muscat, Oman"}}
cases = [
  ["name_ar",     {"detected_text": "علي", "is_static": False}],
  ["name_en",     {"detected_text": "Ali Mohamed Almujaini", "is_static": False}],
  ["position_ar", {"detected_text": "مدير", "is_static": False}],
  ["position_en", {"detected_text": "General Manager", "is_static": False}],
  ["phone",       {"detected_text": "+968 9999 9999", "is_static": False}],
  ["mobile",      {"detected_text": "+968 9999 9999", "is_static": False}],
  ["email",       {"detected_text": "someone@else.com", "is_static": False}],
  ["website",     {"detected_text": "www.aedoman.com", "is_static": False}],
  ["company_en",  {"detected_text": "AED Oman", "is_static": False}],
  ["address_en",  {"detected_text": "Muscat, Oman", "is_static": False}],
  ["fax",         {"detected_text": "+968 2400 0000", "is_static": False}],
  ["name_en",     {"detected_text": "Ali Mohamed Almujaini", "is_static": True}],
]
print(json.dumps([[k, bool(f["is_static"]), m._employee_value(k, payload, f)] for k, f in cases]))
PY;
$tmp = tempnam(sys_get_temp_dir(), 'dt') . '.py';
file_put_contents($tmp, $driver);
$json = (string) shell_exec(escapeshellarg($py) . ' ' . escapeshellarg($tmp) . ' '
    . escapeshellarg($root . '/scripts/render-card-images.py') . ' 2>&1');
@unlink($tmp);
$rows = json_decode($json, true);
dtCheck(is_array($rows), 'the resolver ran', substr($json, 0, 160));
if (!is_array($rows)) { echo "\n1 FAILED\n"; exit(1); }

$byKey = [];
foreach ($rows as [$k, $static, $v]) $byKey[$k . ($static ? ':static' : '')] = $v;

// Per-person fields: empty stays empty.
foreach (['name_ar', 'position_ar', 'phone', 'mobile', 'email'] as $k) {
    dtCheck(($byKey[$k] ?? 'x') === '',
        "an empty {$k} renders nothing, not the imported person's", (string) ($byKey[$k] ?? ''));
}
// The employee's own value still wins.
dtCheck(($byKey['name_en'] ?? '') === 'Ahmed Al-Siyabi',
    "the employee's own name is what renders", (string) ($byKey['name_en'] ?? ''));
dtCheck(($byKey['position_en'] ?? '') === 'Senior Engineer',
    "the employee's own position is what renders", (string) ($byKey['position_en'] ?? ''));
// Tenant constants keep the design's text.
foreach (['website' => 'www.aedoman.com', 'company_en' => 'AED Oman',
          'address_en' => 'Muscat, Oman', 'fax' => '+968 2400 0000'] as $k => $expect) {
    dtCheck(($byKey[$k] ?? '') === $expect,
        "{$k} still falls back to the design, it is the same for everyone",
        (string) ($byKey[$k] ?? ''));
}
// A field the designer marked static is a literal, and stays one.
dtCheck(($byKey['name_en:static'] ?? '') === 'Ali Mohamed Almujaini',
    'an explicitly static field still prints its literal');

$emDash = "\xE2\x80\x94";
dtCheck(!str_contains(file_get_contents($root . '/scripts/render-card-images.py'), $emDash),
    'scripts/render-card-images.py contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
