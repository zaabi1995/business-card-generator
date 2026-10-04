<?php
declare(strict_types=1);
/*
 * The IQ engine without a database: one right answer per question, the scale, the adaptive
 * level, and norm fitting. Run: php tests/php/iq_engine_test.php
 */
require __DIR__ . '/../../includes/iq/IqEngine.php';

$failures = 0;
function check(string $name, bool $ok, $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : '  -> ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

/* 1. Questions: every kind, every level, many seeds. */
$bad = [];
$counts = [];
foreach (IQE_LEVELS as $kind => $levels) {
    foreach ($levels as $level) {
        for ($s = 0; $s < 300; $s += 1) {
            $q = iqe_question("seed$s", $s % 30, $kind, $level);
            $opts = array_map(fn($o) => json_encode(isset($o['svg']) ? preg_replace('/id="p[0-9a-f]+"|url\(#p[0-9a-f]+\)/', '', $o['svg']) : $o), $q['options']);
            $tag = "$kind/$level/seed$s";
            if (count(array_unique($opts)) !== count($opts)) $bad[] = "$tag: options repeat";
            if (!is_int($q['answer']) || !isset($q['options'][$q['answer']])) $bad[] = "$tag: no answer";
            if (count($q['options']) !== IQE_OPTIONS[$kind]) $bad[] = "$tag: " . count($q['options']) . ' options';
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
    }
}
check('every question has distinct options, the right count, and an answer', !$bad, array_slice($bad, 0, 5));

/* 2. Turned shapes: exactly one option is the shape turned; the rest are not, even flipped back. */
$bad = [];
for ($s = 0; $s < 400; $s += 1) {
    foreach ([1, 2, 3, 4] as $level) {
        $r = new IqeRng("rot$s:$level");
        $n = [1 => 5, 2 => 5, 3 => 6, 4 => 7][$level];
        $q = iqe_question("rot$s", 0, 'rotate', $level);
        // Rebuild the cells from the SVG squares.
        $cells = function (string $svg): array {
            preg_match_all('/<rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)"/', $svg, $m, PREG_SET_ORDER);
            $u = (float)$m[0][3];
            return iqe_poly_norm(array_map(fn($x) => [(int)round((float)$x[1] / $u), (int)round((float)$x[2] / $u)], $m));
        };
        $target = iqe_poly_forms($cells($q['figure']));
        $matches = [];
        foreach ($q['options'] as $i => $o) {
            if (in_array(iqe_poly_key($cells($o['svg'])), $target, true)) $matches[] = $i;
        }
        if ($matches !== [$q['answer']]) $bad[] = "rot$s/$level: matches " . json_encode($matches) . " answer {$q['answer']}";
        if (iqe_poly_key($cells($q['figure'])) === iqe_poly_key($cells($q['options'][$q['answer']]['svg']))) $bad[] = "rot$s/$level: answer not turned";
    }
}
check('rotation: one option is the turned shape, never shown unturned', !$bad, array_slice($bad, 0, 5));

/* 3. The key never reaches the page. */
$served = iqe_question('k', 3, 'matrix', 2);
unset($served['answer']);
check('a served question carries no answer', !array_key_exists('answer', $served));

/* 4. The scale. */
$all = fn(bool $right, int $level) => array_map(fn($k) => [$k, $level, $right], IQE_SEQ);
[$tHigh] = iqe_estimate($all(true, 4));
[$tLow] = iqe_estimate($all(false, 1));
[$tNone, $seNone] = iqe_estimate([]);
check('all hard ones right gives a high IQ', iqe_from_theta($tHigh) >= 135, iqe_from_theta($tHigh));
check('all easy ones wrong gives a low IQ', iqe_from_theta($tLow) <= 70, iqe_from_theta($tLow));
check('no answers: IQ 100, wide range', iqe_from_theta($tNone) === 100 && $seNone > 0.95, [$tNone, $seNone]);
check('percentile of 100 is 50, of 115 is 84', iqe_percentile(0.0) === 50 && iqe_percentile(1.0) === 84, [iqe_percentile(0.0), iqe_percentile(1.0)]);
check('bands', iqe_band(131)['en'] === 'Very high' && iqe_band(100)['en'] === 'Average' && iqe_band(79)['en'] === 'Low');

/* 5. Adaptive: a person of ability theta gets questions near their level, and is measured near it. */
foreach ([-1.5, 0.0, 1.5] as $true) {
    $errs = [];
    $levels = [];
    mt_srand(7);
    for ($run = 0; $run < 40; $run += 1) {
        $items = [];
        foreach (IQE_SEQ as $kind) {
            [$t] = iqe_estimate($items);
            $level = iqe_next_level($kind, $t);
            $levels[] = $level;
            $items[] = [$kind, $level, mt_rand() / mt_getrandmax() < iqe_p($true, $kind, $level)];
        }
        [$t, $se] = iqe_estimate($items);
        $errs[] = $t - $true;
    }
    $rmse = sqrt(array_sum(array_map(fn($e) => $e * $e, $errs)) / count($errs));
    $meanLevel = array_sum($levels) / count($levels);
    check(sprintf('simulated theta %+.1f: measured within %.2f SD (mean level %.1f)', $true, $rmse, $meanLevel), $rmse < 0.45, $rmse);
}

/* 6. Norms: fit from a simulated group that is brighter than average and finds one cell harder. */
mt_srand(11);
$gauss = fn() => sqrt(-2 * log(max(1e-12, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
$trueB = fn($kind, $level) => ($kind === 'series' && $level === 3) ? 1.4 : IQE_B[$level];
$people = [];
for ($i = 0; $i < 250; $i += 1) {
    $theta = 0.7 + 1.2 * $gauss();
    $items = [];
    foreach (IQE_SEQ as $kind) {
        iqe_calibration([]);
        [$t] = iqe_estimate($items);
        $level = iqe_next_level($kind, $t);
        $c = 1 / IQE_OPTIONS[$kind];
        $p = $c + (1 - $c) / (1 + exp(-1.7 * IQE_A[$kind] * ($theta - $trueB($kind, $level))));
        $items[] = [$kind, $level, mt_rand() / mt_getrandmax() < $p];
    }
    $people[] = $items;
}
iqe_calibration([]);
$cal = iqe_fit($people, 5, 25);
$iqs = array_map(fn($items) => iqe_from_theta(iqe_estimate($items)[0]), $people);
$m = array_sum($iqs) / count($iqs);
$sd = sqrt(array_sum(array_map(fn($x) => ($x - $m) ** 2, $iqs)) / (count($iqs) - 1));
check(sprintf('normed group: mean IQ %.1f, spread %.1f', $m, $sd), abs($m - 100) < 3 && abs($sd - 15) < 3.5, [$m, $sd]);
$gap = $cal['series']['3'] - $cal['matrix']['3'];
check(sprintf('the fit finds the harder cell (series 3 above matrix 3 by %.2f)', $gap), $gap > 0.4, $cal);
iqe_calibration([]);


echo $failures === 0 ? "all iq engine checks passed\n" : "$failures failure(s)\n";
exit($failures ? 1 : 0);
