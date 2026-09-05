<?php
/**
 * The white plate that shipped empty.
 *
 * Every preset that holds a logo drew a white rounded rect first and then the
 * image on top. IMG() returns '' when there is no logo, but chip() had no such
 * guard, so a company that had not uploaded a logo got a blank white box
 * printed on its cards. It was found on the card back during the 5 Sep 2026
 * gauntlet; four front presets had the same shape and the same defect.
 *
 * The plate now collapses and a brand mark takes the space: the company name
 * on the wide plates, initials in a ring on the square ones and on the back.
 * One renderer covers every surface, because scripts/render-preset.py bakes
 * both the template background the Fabric editor loads and the employee card
 * PNG that the digital card, the wallet strip, the og:image and the print
 * preview all read.
 *
 * This test drives the renderer's own functions, so it needs python3 but not
 * rsvg-convert.
 */
$root = dirname(__DIR__, 2);
$failures = 0;
function plateCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$py = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($py === '') {
    echo "SKIP  python3 not available\n\nALL PASS\n";
    exit(0);
}

// 1. no unconditional plate is left in the source
$src = file_get_contents($root . '/scripts/render-preset.py');
plateCheck(
    !preg_match('/chip\([^)]*\),\s*IMG\(L/', $src),
    'no preset still draws chip() and IMG(L) as separate unconditional calls'
);
$callSites = preg_match_all('/(?<!def )logo_plate\(L,/', $src);
plateCheck(
    $callSites === 5,
    'all five plates go through logo_plate()',
    (string) $callSites
);

// 2. drive the renderer. Two brands, identical but for the logo.
$driver = <<<'PY'
import json, sys, importlib.util
spec = importlib.util.spec_from_file_location("rp", sys.argv[1])
rp = importlib.util.module_from_spec(spec); spec.loader.exec_module(rp)
brand = {"logo": "", "primary": "#204080", "secondary": "#00b060",
         "name_en": "Fatima Al Balushi", "name_ar": "فاطمة البلوشي",
         "title_en": "Operations Manager", "title_ar": "مديرة العمليات",
         "org_en": "Muscat Marine Services", "org_ar": "خدمات مسقط البحرية",
         "phone": "+968 9111 7795", "email": "fatima@mms.om"}
L = "data:image/png;base64,iVBORw0KGgo="
out = {}
for pid, label, bil, fn in rp.PRESETS:
    out[pid + ":front:nologo"] = fn(brand, "", rp.safe_accent(brand["primary"]), rp.safe_accent(brand["secondary"]), bil)
    out[pid + ":front:logo"]   = fn(brand, L,  rp.safe_accent(brand["primary"]), rp.safe_accent(brand["secondary"]), bil)
    out[pid + ":back:nologo"]  = rp.back_panel(brand, "", rp.safe_accent(brand["primary"]), rp.safe_accent(brand["secondary"]), bil)
    out[pid + ":back:logo"]    = rp.back_panel(brand, L,  rp.safe_accent(brand["primary"]), rp.safe_accent(brand["secondary"]), bil)
# a company with no name at all, so no monogram is possible either
blank = dict(brand); blank["org_en"] = ""; blank["org_ar"] = ""; blank["name_en"] = ""
out["blank:back:nologo"] = rp.back_panel(blank, "", "#204080", "#00b060", False)
print(json.dumps(out))
PY;
$tmp = tempnam(sys_get_temp_dir(), 'plate') . '.py';
file_put_contents($tmp, $driver);
$json = (string) shell_exec(escapeshellarg($py) . ' ' . escapeshellarg($tmp) . ' '
    . escapeshellarg($root . '/scripts/render-preset.py') . ' 2>&1');
@unlink($tmp);
$bodies = json_decode($json, true);
plateCheck(is_array($bodies) && $bodies !== [], 'the renderer produced SVG for every preset',
    substr($json, 0, 200));
if (!is_array($bodies)) { echo "\n1 FAILED\n"; exit(1); }

// A white rounded rect is the plate. rx="16" is unique to chip().
$isPlate = static fn(string $svg): bool => (bool) preg_match('/rx="16" fill="#ffffff"/', $svg);

$emptyPlates = [];
foreach ($bodies as $key => $svg) {
    if (!str_contains($key, ':nologo')) continue;
    if ($isPlate($svg)) $emptyPlates[] = $key;
}
plateCheck($emptyPlates === [], 'no card without a logo draws an empty white plate',
    implode(', ', array_slice($emptyPlates, 0, 6)));

$missingPlates = [];
foreach ($bodies as $key => $svg) {
    if (!str_contains($key, ':logo')) continue;
    if (!str_contains($svg, '<image ')) $missingPlates[] = $key;
}
plateCheck($missingPlates === [], 'every card WITH a logo still renders the logo',
    implode(', ', array_slice($missingPlates, 0, 6)));

// 3. the space is not simply left blank on the surfaces where the plate was
//    the focal element.
plateCheck(
    str_contains($bodies['bold_band:back:nologo'], '<circle')
        && str_contains($bodies['bold_band:back:nologo'], '>MM<'),
    'the back falls back to the company monogram'
);
plateCheck(
    str_contains($bodies['bold_band:front:nologo'], 'Muscat Marine Services'),
    'a wide front plate falls back to the company wordmark'
);
plateCheck(
    str_contains($bodies['split_v:front:nologo'], '<circle')
        && str_contains($bodies['split_v:front:nologo'], '>MM<'),
    'a square front plate falls back to the monogram'
);
plateCheck(
    !str_contains($bodies['blank:back:nologo'], '<circle'),
    'a company with no name and no logo gets no half-drawn mark either'
);

// 4. bilingual presets keep their Arabic, with and without a logo
foreach (['biling_corp', 'gov_formal', 'biling_split'] as $pid) {
    plateCheck(
        str_contains($bodies[$pid . ':front:nologo'], 'فاطمة')
            || str_contains($bodies[$pid . ':front:nologo'], 'خدمات'),
        "{$pid} still renders Arabic with no logo"
    );
}

$emDash = "\xE2\x80\x94";
plateCheck(!str_contains($src, $emDash), 'scripts/render-preset.py contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
