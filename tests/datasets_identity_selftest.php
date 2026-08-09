<?php
require_once __DIR__ . "/../includes/Datasets.php";
$fail = 0;
function ok($cond, $msg) { global $fail; if ($cond) { echo "PASS  $msg\n"; } else { echo "FAIL  $msg\n"; $fail++; } }

$obi = Datasets::node('obi', ['description' => 'x', 'dateModified' => '2026-08-04']);
ok(($obi['@id'] ?? '') === 'https://cardify.om/oman-business-index#dataset', 'obi node carries the canonical @id');
ok(($obi['@type'] ?? '') === 'Dataset', 'obi node is a Dataset');
ok(count($obi['distribution']) === 2, 'obi declares 2 measured distributions, got ' . count($obi['distribution']));
ok(isset($obi['publisher']['logo']), 'obi node keeps the publisher logo');
ok($obi['dateModified'] === '2026-08-04', 'page-owned keys survive');
// r148 / bhd-r6-99. description is OWNED here now. These three arms were
// confirmed RED against the previous Datasets.php before the change landed:
// the old node() returned the caller's 'x', and node()/brief() returned two
// different sentences for one @id, which is what /press-kit was serving live.
ok(!isset($obi['description']) || $obi['description'] !== 'x',
   'a page cannot supply its own description');
$obiN = Datasets::node('obi', [], 2502);
$obiB = Datasets::brief('obi', [], 2502);
ok(($obiN['description'] ?? null) === ($obiB['description'] ?? null)
   && str_contains($obiN['description'] ?? '', '2502'),
   'node and brief serve ONE description, count included');
ok(!isset(Datasets::brief('obi')['description']),
   'no count means NO description, never a second wording');
$gccN = Datasets::node('gcc');
$gccB = Datasets::brief('gcc');
ok(($gccN['description'] ?? null) === ($gccB['description'] ?? null)
   && ($gccN['description'] ?? '') !== '',
   'a countless dataset still serves ONE description everywhere');

$logos = Datasets::node('logos');
$brief = Datasets::brief('logos', ['description' => 'y']);
ok($brief['@id'] === $logos['@id'], 'brief and node are ONE entity (same @id)');
ok($brief['name'] === $logos['name'], 'brief and node publish ONE name');
ok(count($brief['distribution']) === 4, 'brief carries the distribution so a promise resolves, got ' . count($brief['distribution']));
ok(in_array('DataCatalog', (array)$logos['@type'], true) && in_array('Dataset', (array)$logos['@type'], true), 'logo library is one node typed both ways');

// the control arm: a page must NOT be able to re-mint a second name or licence
$hijack = Datasets::node('obi', ['name' => 'Oman Business Index', 'license' => 'https://example.com/other', '@id' => 'https://cardify.om/press#dataset']);
ok($hijack['name'] === 'Oman Business Index 2026', 'a page cannot override the owned name');
ok($hijack['license'] === 'https://creativecommons.org/licenses/by/4.0/', 'a page cannot override the owned licence');
ok($hijack['@id'] === 'https://cardify.om/oman-business-index#dataset', 'a page cannot override the owned @id');

$threw = false;
try { Datasets::id('nope'); } catch (Throwable $e) { $threw = true; }
ok($threw, 'an unknown dataset key throws instead of inventing an identity');

// every id is distinct
ok(count(array_unique(array_values(Datasets::IDS))) === count(Datasets::IDS), 'no two datasets share an @id');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
