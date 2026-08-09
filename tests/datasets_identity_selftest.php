<?php
require_once __DIR__ . "/../includes/Datasets.php";
$fail = 0;
function ok($cond, $msg) { global $fail; if ($cond) { echo "PASS  $msg\n"; } else { echo "FAIL  $msg\n"; $fail++; } }

$obi = Datasets::node('obi', ['description' => 'x', 'dateModified' => '2026-08-04']);
ok(($obi['@id'] ?? '') === 'https://cardify.om/oman-business-index#dataset', 'obi node carries the canonical @id');
ok(($obi['@type'] ?? '') === 'Dataset', 'obi node is a Dataset');
ok(count($obi['distribution']) === 2, 'obi declares 2 measured distributions, got ' . count($obi['distribution']));
// r154 / llm153-2: publisher and creator are REFERENCES to the one owner, not
// bodies. This assertion used to demand `publisher.logo`, i.e. it demanded the
// rival body: an ImageObject at cardify-logo.png while the owner publishes
// logo.svg, on the same page that also referenced the owner.
$ownerRef = ['@id' => 'https://cardify.om/#organization'];
ok($obi['publisher'] === $ownerRef, 'obi publisher is the owner @id and nothing else');
ok($obi['creator'] === $ownerRef, 'obi creator is the owner @id and nothing else');
ok(!isset($obi['publisher']['name']) && !isset($obi['publisher']['logo']),
   'obi publisher carries no rival name or logo');

// r154: this assertion was ALREADY FAILING on the merge commit, before any
// r154 edit, and it is the test that drifted rather than the code. r148 moved
// the sentence into Datasets::describe() and made `description` a class-owned
// key precisely so two pages could not word one dataset two ways; node()'s own
// array_diff_key has listed 'description' as un-ownable since then. A page
// supplies the COUNT, never the wording.
ok($obi['dateModified'] === '2026-08-04', 'a page-owned key survives');
ok(!array_key_exists('description', $obi),
   'a page cannot supply the wording (r148: description is describe()\'s)');
$counted = Datasets::node('obi', [], 4321);
ok(str_contains($counted['description'] ?? '', '4321'),
   'the page supplies the COUNT and describe() supplies the sentence');

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
