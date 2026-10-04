<?php
declare(strict_types=1);

/*
 * The Cardify IQ engine: question generators and scoring. Pure functions, no database.
 *
 * Ported from the ITHCA internship journal (projects/jaifar-internship/app/iq.php, 4 Oct 2026),
 * where it was built and tested first. Thirty questions in five kinds, each generated from a seed
 * so there is no bank to look up. Adaptive: the server re-estimates ability after every answer and
 * serves the next question at the most informative level. Scoring is item response theory (3PL,
 * EAP); IQ = 100 + 15 x theta, with a 90% range and a percentile. Timing follows Raven's Advanced
 * Progressive Matrices: one clock for the whole test, 33 minutes for 30 questions.
 *
 * Storage, sessions and payments live in IqStore.php and IqPay.php.
 */

const IQE_COUNT = 30;
const IQE_GRACE_SECONDS = 3;
/**
 * One clock for the whole test, as Raven's Advanced Progressive Matrices does (40 minutes for 36
 * items): the same pace gives 33 minutes for 30. No question has its own limit. When the time is
 * up, every question not yet answered counts as wrong. (Ali, 4 Oct 2026: "do the same as the
 * official IQ test".) The first version kept a clock per question; its attempts keep that rule.
 */
const IQE_TEST_SECONDS = 33 * 60;


/** The kind of each of the thirty questions. The level is chosen live. */
const IQE_SEQ = [
    'matrix', 'series', 'rotate', 'odd', 'matrix', 'logic', 'series', 'rotate', 'matrix', 'series',
    'odd', 'rotate', 'matrix', 'logic', 'series', 'rotate', 'matrix', 'odd', 'series', 'rotate',
    'logic', 'matrix', 'series', 'rotate', 'odd', 'matrix', 'logic', 'series', 'rotate', 'matrix',
];

const IQE_LEVELS = ['matrix' => [1, 2, 3, 4], 'series' => [1, 2, 3, 4], 'rotate' => [1, 2, 3, 4], 'odd' => [1, 2, 3], 'logic' => [1, 2, 3, 4]];
const IQE_SECONDS = ['series' => 45, 'matrix' => 60, 'odd' => 40, 'logic' => 60, 'rotate' => 45];
/** A level-4 question gets more time: it has more to read or to turn. */
const IQE_HARD_EXTRA = 15;

/* Item parameters by design: difficulty b per level, discrimination a per kind, guessing c = 1/options. */
const IQE_B = [1 => -1.6, 2 => -0.6, 3 => 0.4, 4 => 1.4];
const IQE_A = ['matrix' => 1.4, 'series' => 1.3, 'rotate' => 1.3, 'odd' => 1.1, 'logic' => 1.0];
const IQE_OPTIONS = ['matrix' => 6, 'series' => 4, 'rotate' => 4, 'odd' => 5, 'logic' => 4];

const IQE_DOMAINS = [
    'matrix' => ['الأنماط', 'Patterns'],
    'series' => ['الأرقام', 'Numbers'],
    'rotate' => ['التصور المكاني', 'Spatial'],
    'odd' => ['الملاحظة', 'Observation'],
    'logic' => ['المنطق', 'Logic'],
];

/** The usual descriptive bands for an IQ score. */
const IQE_BANDS = [
    [130, 'مرتفع جداً', 'Very high'],
    [120, 'مرتفع', 'High'],
    [110, 'فوق المتوسط', 'Above average'],
    [90, 'متوسط', 'Average'],
    [80, 'دون المتوسط', 'Below average'],
    [0, 'منخفض', 'Low'],
];
const IQE_MIN = 55;
const IQE_MAX = 145;

final class IqeRng
{
    private int $s;
    public function __construct(string $seed)
    {
        $this->s = crc32($seed) ?: 1;
    }
    public function next(): float
    {
        // xorshift32
        $x = $this->s;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->s = $x & 0xFFFFFFFF;
        return $this->s / 4294967296;
    }
    public function int(int $min, int $max): int
    {
        return $min + (int)floor($this->next() * ($max - $min + 1));
    }
    public function pick(array $a): mixed
    {
        return $a[$this->int(0, count($a) - 1)];
    }
    public function shuffle(array $a): array
    {
        for ($i = count($a) - 1; $i > 0; $i -= 1) {
            $j = $this->int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }
}

/** Puts the correct option among the distractors at a random place. Returns [options, answer]. */
function iqe_place(IqeRng $r, mixed $correct, array $wrong): array
{
    $all = $r->shuffle(array_merge([$correct], $wrong));
    return [$all, array_search($correct, $all, true)];
}

function iqe_seconds(string $kind, int $level): int
{
    return IQE_SECONDS[$kind] + ($level >= 4 ? IQE_HARD_EXTRA : 0);
}

function iqe_question(string $seed, int $idx, string $kind, int $level): array
{
    $r = new IqeRng($seed . ':' . $idx);
    $q = match ($kind) {
        'series' => iqe_series($r, $level),
        'matrix' => iqe_matrix($r, $level),
        'odd' => iqe_odd($r, $level),
        'logic' => iqe_logic($r, $level),
        'rotate' => iqe_rotate($r, $level),
    };
    return $q + ['kind' => $kind, 'level' => $level, 'seconds' => iqe_seconds($kind, $level)];
}

/* ---------- number series ---------- */

function iqe_series(IqeRng $r, int $d): array
{
    $terms = [];
    if ($d === 1) {
        if ($r->next() < 0.5) {
            $a = $r->int(2, 20);
            $k = $r->int(3, 9);
            for ($i = 0; $i < 6; $i += 1) $terms[] = $a + $k * $i;
        } else {
            $a = $r->int(1, 5);
            $k = $r->pick([2, 3]);
            for ($i = 0; $i < 6; $i += 1) $terms[] = $a * $k ** $i;
        }
    } elseif ($d === 2) {
        $type = $r->int(0, 2);
        if ($type === 0) {
            // Growing steps: +k, +k+1, +k+2 ...
            $t = $r->int(1, 10);
            $k = $r->int(1, 4);
            for ($i = 0; $i < 6; $i += 1) { $terms[] = $t; $t += $k + $i; }
        } elseif ($type === 1) {
            // Two sequences interleaved.
            $a = $r->int(1, 9); $ka = $r->int(2, 5);
            $b = $r->int(20, 40); $kb = -$r->int(1, 4);
            for ($i = 0; $i < 6; $i += 1) $terms[] = $i % 2 === 0 ? $a + $ka * intdiv($i, 2) : $b + $kb * intdiv($i, 2);
        } else {
            $c = $r->int(-3, 5);
            $s = $r->int(1, 3);
            for ($i = 0; $i < 6; $i += 1) $terms[] = ($i + $s) ** 2 + $c;
        }
    } elseif ($d === 3) {
        $type = $r->int(0, 2);
        if ($type === 0) {
            $a = $r->int(1, 4); $b = $r->int(2, 6);
            $terms = [$a, $b];
            for ($i = 2; $i < 6; $i += 1) $terms[] = $terms[$i - 1] + $terms[$i - 2];
        } elseif ($type === 1) {
            $t = $r->int(1, 4); $m = $r->pick([2, 3]); $p = $r->int(1, 3);
            for ($i = 0; $i < 6; $i += 1) { $terms[] = $t; $t = $t * $m + $p; }
        } else {
            // Alternating operations: +k, x2, +k, x2 ...
            $t = $r->int(1, 6); $k = $r->int(2, 5);
            for ($i = 0; $i < 6; $i += 1) { $terms[] = $t; $t = $i % 2 === 0 ? $t + $k : $t * 2; }
        }
    } else {
        $type = $r->int(0, 2);
        if ($type === 0) {
            // The step doubles: +k, +2k, +4k, +8k ...
            $t = $r->int(1, 9); $k = $r->int(1, 3);
            for ($i = 0; $i < 7; $i += 1) { $terms[] = $t; $t += $k * 2 ** $i; }
        } elseif ($type === 1) {
            // Cubes, shifted.
            $c = $r->int(-2, 4); $s = $r->int(1, 2);
            for ($i = 0; $i < 6; $i += 1) $terms[] = ($i + $s) ** 3 + $c;
        } else {
            // Alternating operations: x3, -k, x3, -k ...
            $t = $r->int(2, 4); $k = $r->int(1, 4);
            for ($i = 0; $i < 7; $i += 1) { $terms[] = $t; $t = $i % 2 === 0 ? $t * 3 : $t - $k; }
        }
    }
    $answer = array_pop($terms);
    $step = max(1, abs($answer - end($terms)));
    $wrong = [];
    foreach ([$answer + 1, $answer - 1, $answer + $step, $answer - $step, $answer + 2, $answer * 2, $answer - 2, $answer + 3] as $w) {
        if ($w !== $answer && !in_array($w, $wrong, true) && count($wrong) < 3) $wrong[] = $w;
    }
    [$options, $key] = iqe_place($r, $answer, $wrong);
    return [
        'prompt_ar' => 'ما الرقم التالي في السلسلة؟',
        'prompt_en' => 'What number comes next?',
        'series' => $terms,
        'options' => array_map(fn($o) => ['text' => (string)$o], $options),
        'answer' => $key,
    ];
}

/* ---------- figures ---------- */

const IQE_SHAPES = ['circle', 'square', 'triangle', 'diamond'];
const IQE_FILLS = ['empty', 'solid', 'half'];

/** One figure as SVG: `count` copies of a shape with a fill, in a 100x100 box. */
function iqe_svg(array $c, int $size = 100): string
{
    $n = $c['count'];
    $pos = [1 => [[50, 50]], 2 => [[30, 50], [70, 50]], 3 => [[50, 28], [28, 70], [72, 70]], 4 => [[30, 30], [70, 30], [30, 70], [70, 70]]][$n];
    $s = $n === 1 ? 26 : 15;
    // Stripes need a pattern id that is unique on the page, because many figures share one screen.
    $id = 'p' . substr(md5(json_encode($c) . random_bytes(4)), 0, 8);
    $fill = match ($c['fill']) { 'solid' => 'currentColor', 'half' => "url(#$id)", default => 'none' };
    $defs = $c['fill'] === 'half'
        ? '<defs><pattern id="' . $id . '" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
            . '<rect width="3.5" height="8" fill="currentColor"/></pattern></defs>'
        : '';
    $out = '';
    foreach ($pos as [$x, $y]) {
        $out .= match ($c['shape']) {
            'circle' => "<circle cx=\"$x\" cy=\"$y\" r=\"$s\"/>",
            'square' => '<rect x="' . ($x - $s) . '" y="' . ($y - $s) . '" width="' . (2 * $s) . '" height="' . (2 * $s) . '"/>',
            'triangle' => '<polygon points="' . $x . ',' . ($y - $s) . ' ' . ($x + $s) . ',' . ($y + $s) . ' ' . ($x - $s) . ',' . ($y + $s) . '"/>',
            'diamond' => '<polygon points="' . $x . ',' . ($y - $s) . ' ' . ($x + $s) . ',' . $y . ' ' . $x . ',' . ($y + $s) . ' ' . ($x - $s) . ',' . $y . '"/>',
        };
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="' . $size . '" height="' . $size . '">' . $defs
        . '<g fill="' . $fill . '" stroke="currentColor" stroke-width="3" stroke-linejoin="round">' . $out . '</g></svg>';
}

/* ---------- 3x3 pattern grids ---------- */

function iqe_matrix(IqeRng $r, int $d): array
{
    $shapes = $r->shuffle(IQE_SHAPES);
    $fills = $r->shuffle(IQE_FILLS);
    $cells = [];
    $same = $r->int(1, 3);
    // Level 4: the third figure in each row holds as many shapes as the first two together.
    $pairs = array_slice($r->shuffle([[1, 1], [1, 2], [2, 1], [2, 2]]), 0, 3);
    for ($row = 0; $row < 3; $row += 1) {
        for ($col = 0; $col < 3; $col += 1) {
            if ($d === 1) {
                // Each row keeps its shape; the count grows across the row.
                $cells[] = ['shape' => $shapes[$row], 'count' => $col + 1, 'fill' => $fills[0]];
            } elseif ($d === 2) {
                // Shape changes across the columns, fill changes down the rows.
                $cells[] = ['shape' => $shapes[$col], 'count' => $same, 'fill' => $fills[$row]];
            } elseif ($d === 3) {
                // Three Latin squares on different offsets.
                $cells[] = ['shape' => $shapes[($row + $col) % 3], 'count' => 1 + ($row * 2 + $col) % 3, 'fill' => $fills[($row + 2 * $col) % 3]];
            } else {
                [$a, $b] = $pairs[$row];
                $cells[] = ['shape' => $shapes[($row + $col) % 3], 'count' => [$a, $b, $a + $b][$col], 'fill' => $fills[($row + 2 * $col) % 3]];
            }
        }
    }
    $answer = array_pop($cells);
    $wrong = iqe_variants($r, $answer, 5, $d >= 4);
    [$options, $key] = iqe_place($r, $answer, $wrong);
    return [
        'prompt_ar' => 'أي شكل يكمل النمط في الخانة الفارغة؟',
        'prompt_en' => 'Which figure completes the pattern?',
        'grid' => array_map(fn($c) => iqe_svg($c), $cells),
        'options' => array_map(fn($c) => ['svg' => iqe_svg($c)], $options),
        'answer' => $key,
    ];
}

/** Figures that differ from $c in one or two features (exactly one when $close). */
function iqe_variants(IqeRng $r, array $c, int $n, bool $close = false): array
{
    $out = [];
    $guard = 0;
    while (count($out) < $n && $guard++ < 400) {
        $v = $c;
        $changes = $close ? 1 : $r->int(1, 2);
        for ($i = 0; $i < $changes; $i += 1) {
            $what = $r->int(0, 2);
            if ($what === 0) $v['shape'] = $r->pick(IQE_SHAPES);
            elseif ($what === 1) $v['count'] = $r->int(1, 4);
            else $v['fill'] = $r->pick(IQE_FILLS);
        }
        if ($v != $c && !in_array($v, $out)) $out[] = $v;
    }
    return $out;
}

/* ---------- odd one out ---------- */

function iqe_odd(IqeRng $r, int $d): array
{
    // Four figures share one feature; one does not. Harder levels hide it in a subtler feature.
    // The other two features come in pairs (each value twice), and the odd figure reuses one of
    // those values, so no other figure is the only one of its kind: one answer, not two.
    $feature = $d === 1 ? 'shape' : ($d === 2 ? 'count' : 'fill');
    $pool = ['shape' => IQE_SHAPES, 'count' => [1, 2, 3, 4], 'fill' => IQE_FILLS];
    $values = $r->shuffle($pool[$feature]);
    $same = $values[0];
    $oddValue = $values[1];
    $figs = [];
    $oddFig = [];
    for ($guard = 0; $guard < 50; $guard += 1) {
        $figs = array_fill(0, 4, [$feature => $same]);
        $oddFig = [$feature => $oddValue];
        foreach (array_diff(['shape', 'count', 'fill'], [$feature]) as $other) {
            $two = array_slice($r->shuffle($pool[$other]), 0, 2);
            $col = $r->shuffle([$two[0], $two[0], $two[1], $two[1]]);
            foreach ($figs as $k => $f) $figs[$k][$other] = $col[$k];
            $oddFig[$other] = $r->pick($two);
        }
        $norm = fn(array $f) => ['shape' => $f['shape'], 'count' => $f['count'], 'fill' => $f['fill']];
        $figs = array_map($norm, $figs);
        if (count(array_unique(array_map('json_encode', $figs))) === 4) break;
    }
    $odd = ['shape' => $oddFig['shape'], 'count' => $oddFig['count'], 'fill' => $oddFig['fill']];
    [$options, $key] = iqe_place($r, $odd, $figs);
    return [
        'prompt_ar' => 'أي شكل مختلف عن البقية؟',
        'prompt_en' => 'Which figure is the odd one out?',
        'options' => array_map(fn($c) => ['svg' => iqe_svg($c)], $options),
        'answer' => $key,
    ];
}

/* ---------- shapes turned in space ---------- */

/** Moves a set of grid cells to the origin and sorts it, so equal shapes compare equal. */
function iqe_poly_norm(array $cells): array
{
    $minX = min(array_column($cells, 0));
    $minY = min(array_column($cells, 1));
    $out = array_map(fn($c) => [$c[0] - $minX, $c[1] - $minY], $cells);
    usort($out, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    return $out;
}

function iqe_poly_key(array $cells): string
{
    return json_encode(iqe_poly_norm($cells));
}

/** Turns a shape a quarter turn clockwise $k times. */
function iqe_poly_rot(array $cells, int $k): array
{
    for ($i = 0; $i < (($k % 4) + 4) % 4; $i += 1) {
        $cells = array_map(fn($c) => [-$c[1], $c[0]], $cells);
    }
    return iqe_poly_norm($cells);
}

function iqe_poly_mirror(array $cells): array
{
    return iqe_poly_norm(array_map(fn($c) => [-$c[0], $c[1]], $cells));
}

/** Every way the shape can lie when only turned, as keys. */
function iqe_poly_forms(array $cells): array
{
    $out = [];
    for ($k = 0; $k < 4; $k += 1) $out[] = iqe_poly_key(iqe_poly_rot($cells, $k));
    return array_values(array_unique($out));
}

/** A connected shape of $n squares, grown one square at a time. */
function iqe_poly_grow(IqeRng $r, int $n): array
{
    $cells = [[0, 0]];
    $guard = 0;
    while (count($cells) < $n && $guard++ < 500) {
        [$x, $y] = $r->pick($cells);
        [$dx, $dy] = $r->pick([[1, 0], [-1, 0], [0, 1], [0, -1]]);
        $next = [$x + $dx, $y + $dy];
        if (!in_array($next, $cells, true)) $cells[] = $next;
    }
    return iqe_poly_norm($cells);
}

function iqe_poly_connected(array $cells): bool
{
    if (!$cells) return false;
    $seen = [json_encode($cells[0]) => true];
    $stack = [$cells[0]];
    $set = array_flip(array_map('json_encode', $cells));
    while ($stack) {
        [$x, $y] = array_pop($stack);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $k = json_encode([$x + $dx, $y + $dy]);
            if (isset($set[$k]) && !isset($seen[$k])) { $seen[$k] = true; $stack[] = [$x + $dx, $y + $dy]; }
        }
    }
    return count($seen) === count($cells);
}

/** The shape as SVG squares, scaled to fit a 100x100 box. */
function iqe_poly_svg(array $cells): string
{
    $w = max(array_column($cells, 0)) + 1;
    $h = max(array_column($cells, 1)) + 1;
    $unit = min(84 / $w, 84 / $h);
    $ox = (100 - $unit * $w) / 2;
    $oy = (100 - $unit * $h) / 2;
    $out = '';
    foreach ($cells as [$x, $y]) {
        $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>', $ox + $x * $unit, $oy + $y * $unit, $unit, $unit);
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
        . '<g fill="currentColor" fill-opacity="0.18" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round">' . $out . '</g></svg>';
}

function iqe_rotate(IqeRng $r, int $d): array
{
    $n = [1 => 5, 2 => 5, 3 => 6, 4 => 7][$d];
    // A shape that differs from its mirror image and has no turning symmetry, so the mirror is
    // never a turned copy, and three turned mirrors are three different pictures.
    $shape = [];
    for ($guard = 0; $guard < 300; $guard += 1) {
        $shape = iqe_poly_grow($r, $n);
        $forms = iqe_poly_forms($shape);
        if (count($forms) === 4 && !in_array(iqe_poly_key(iqe_poly_mirror($shape)), $forms, true)) break;
    }
    $forms = iqe_poly_forms($shape);
    $mirror = iqe_poly_mirror($shape);
    $taken = array_merge($forms, iqe_poly_forms($mirror));

    $shown = $r->int(0, 3);
    $turn = $shown + $r->int(1, 3);
    $correct = iqe_poly_rot($shape, $turn);
    $wrong = [];
    if ($d === 1) {
        // Easy: the wrong options are other shapes, not mirror images.
        for ($guard = 0; count($wrong) < 3 && $guard < 300; $guard += 1) {
            $other = iqe_poly_grow($r, $n);
            $otherForms = array_merge(iqe_poly_forms($other), iqe_poly_forms(iqe_poly_mirror($other)));
            if (array_intersect($otherForms, $taken)) continue;
            $taken = array_merge($taken, $otherForms);
            $wrong[] = iqe_poly_rot($other, $r->int(0, 3));
        }
    } else {
        $turns = $r->shuffle([0, 1, 2, 3]);
        $mirrors = $d === 4 ? 2 : 3;
        for ($i = 0; $i < $mirrors; $i += 1) $wrong[] = iqe_poly_rot($mirror, $turns[$i]);
        if ($d === 4) {
            // Hard: one option is the turned shape with a single square moved.
            for ($guard = 0; count($wrong) < 3 && $guard < 300; $guard += 1) {
                $cells = $shape;
                array_splice($cells, $r->int(0, count($cells) - 1), 1);
                if (!iqe_poly_connected($cells)) continue;
                [$x, $y] = $r->pick($cells);
                [$dx, $dy] = $r->pick([[1, 0], [-1, 0], [0, 1], [0, -1]]);
                $add = [$x + $dx, $y + $dy];
                if (in_array($add, $cells, true)) continue;
                $cells[] = $add;
                $near = iqe_poly_norm($cells);
                if (in_array(iqe_poly_key($near), $taken, true)) continue;
                $wrong[] = iqe_poly_rot($near, $turn + $r->int(0, 3));
            }
        }
    }
    [$options, $key] = iqe_place($r, $correct, $wrong);
    return [
        'prompt_ar' => 'أي شكل هو نفس الشكل الأول بعد تدويره فقط (بلا قلب)؟',
        'prompt_en' => 'Which shape is the first shape, only turned (not flipped)?',
        'figure' => iqe_poly_svg(iqe_poly_rot($shape, $shown)),
        'options' => array_map(fn($c) => ['svg' => iqe_poly_svg($c)], $options),
        'answer' => $key,
    ];
}

/* ---------- logic ---------- */

/** Made-up words, so a syllogism is solved by its form and not by what the reader already knows. */
const IQE_WORDS = [['زِمار', 'zims'], ['تُوك', 'toks'], ['بِلان', 'blans'], ['قُرف', 'gorps'], ['دَكْس', 'daxes'], ['فِنْد', 'fends']];

function iqe_statement(string $form, array $x, array $y): array
{
    return match ($form) {
        'all' => ["كل «{$x[0]}» هي «{$y[0]}»", "All {$x[1]} are {$y[1]}"],
        'some' => ["بعض «{$x[0]}» هي «{$y[0]}»", "Some {$x[1]} are {$y[1]}"],
        'no' => ["لا شيء من «{$x[0]}» هو «{$y[0]}»", "No {$x[1]} are {$y[1]}"],
    };
}

function iqe_logic(IqeRng $r, int $d): array
{
    $names = $r->shuffle([['سالم', 'Salim'], ['مريم', 'Maryam'], ['خالد', 'Khalid'], ['فاطمة', 'Fatma'], ['أحمد', 'Ahmed']]);
    $days = [['الأحد', 'Sunday'], ['الاثنين', 'Monday'], ['الثلاثاء', 'Tuesday'], ['الأربعاء', 'Wednesday'], ['الخميس', 'Thursday'], ['الجمعة', 'Friday'], ['السبت', 'Saturday']];
    $type = match ($d) {
        1 => $r->pick(['order', 'days']),
        2 => $r->pick(['machines', 'percent']),
        3 => 'syllogism',
        default => $r->pick(['percent2', 'work']),
    };
    switch ($type) {
        case 'order':
            [$a, $b, $c] = $names;
            $q = ['ar' => "{$a[0]} أطول من {$b[0]}، و{$c[0]} أقصر من {$b[0]}. من الأقصر؟", 'en' => "{$a[1]} is taller than {$b[1]}, and {$c[1]} is shorter than {$b[1]}. Who is the shortest?"];
            $opts = [[$c[0], $c[1]], [$a[0], $a[1]], [$b[0], $b[1]], ['لا يمكن معرفة ذلك', 'Cannot tell']];
            break;
        case 'days':
            $start = $r->int(0, 6);
            $n = $r->int(20, 120);
            $q = ['ar' => "إذا كان اليوم {$days[$start][0]}، فما اليوم بعد {$n} يوماً؟", 'en' => "If today is {$days[$start][1]}, what day is it {$n} days from now?"];
            $right = ($start + $n) % 7;
            $opts = [$days[$right], $days[($right + 1) % 7], $days[($right + 6) % 7], $days[($right + 3) % 7]];
            break;
        case 'machines':
            $k = $r->int(3, 7);
            $m = $k * $r->int(2, 4);
            $q = ['ar' => "{$k} آلات تصنع {$k} أكواب في {$k} دقائق. كم دقيقة تحتاج {$m} آلة لصنع {$m} كوباً؟", 'en' => "{$k} machines make {$k} cups in {$k} minutes. How many minutes do {$m} machines need to make {$m} cups?"];
            $opts = [[(string)$k, (string)$k], [(string)$m, (string)$m], [(string)($k * $m), (string)($k * $m)], ['1', '1']];
            break;
        case 'percent':
            $p = $r->pick([10, 20, 25, 50]);
            $q = ['ar' => "ارتفع سعر بنسبة {$p}% ثم انخفض بنسبة {$p}%. ما التغيّر النهائي؟", 'en' => "A price rises by {$p}% and then falls by {$p}%. What is the overall change?"];
            $loss = rtrim(rtrim(number_format($p * $p / 100, 2), '0'), '.');
            $opts = [["انخفاض {$loss}%", "Down {$loss}%"], ['لا تغيير', 'No change'], ["ارتفاع {$loss}%", "Up {$loss}%"], ["انخفاض {$p}%", "Down {$p}%"]];
            break;
        case 'syllogism':
            [$wa, $wb, $wc] = $r->shuffle(IQE_WORDS);
            $none = ['لا شيء مما سبق يلزم حتماً', 'None of these must be true'];
            $form = $r->int(0, 3);
            // [premise 1, premise 2, the conclusion that must follow (or null), wrong conclusions]
            [$p1, $p2, $right, $bad] = match ($form) {
                0 => [iqe_statement('all', $wa, $wb), iqe_statement('all', $wb, $wc), iqe_statement('all', $wa, $wc),
                    [iqe_statement('all', $wc, $wa), iqe_statement('no', $wa, $wc)]],
                1 => [iqe_statement('no', $wa, $wb), iqe_statement('all', $wc, $wa), iqe_statement('no', $wc, $wb),
                    [iqe_statement('some', $wc, $wb), iqe_statement('all', $wb, $wc)]],
                2 => [iqe_statement('some', $wa, $wb), iqe_statement('all', $wb, $wc), iqe_statement('some', $wa, $wc),
                    [iqe_statement('all', $wa, $wc), iqe_statement('no', $wa, $wc)]],
                default => [iqe_statement('all', $wa, $wb), iqe_statement('some', $wb, $wc), null,
                    [iqe_statement('some', $wa, $wc), iqe_statement('all', $wc, $wb), iqe_statement('no', $wa, $wc)]],
            };
            $q = [
                'ar' => "إذا صحّت العبارتان: {$p1[0]}، و{$p2[0]}. أيّ عبارة تلزم حتماً؟",
                'en' => "If both are true: {$p1[1]}, and {$p2[1]}. Which statement must also be true?",
            ];
            $opts = $right ? array_merge([$right], $bad, [$none]) : array_merge([$none], $bad);
            break;
        case 'work':
            [$x, $y, $both] = $r->pick([[3, 6, 2], [4, 12, 3], [6, 12, 4], [10, 15, 6], [12, 24, 8], [20, 30, 12]]);
            $fmt = fn($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
            $q = ['ar' => "ينجز سالم عملاً في {$x} ساعات، وتنجزه مريم في {$y} ساعة. كم ساعة يحتاجان إذا عملا معاً؟", 'en' => "Salim finishes a job in {$x} hours and Maryam in {$y} hours. How many hours do they need working together?"];
            $opts = array_map(fn($v) => [$fmt($v), $fmt($v)], [$both, ($x + $y) / 2, $x + $y, $both / 2]);
            break;
        default: // percent2
            $a = $r->pick([20, 30, 40]);
            $b = $r->pick([10, 20, 50]);
            $total = round((1 + $a / 100) * (1 + $b / 100) * 100 - 100, 1);
            $t = rtrim(rtrim(number_format($total, 1), '0'), '.');
            $q = ['ar' => "زادت المبيعات {$a}% في سنة، ثم {$b}% في السنة التالية. كم زادت في السنتين معاً؟", 'en' => "Sales grew {$a}% in one year, then {$b}% the next. What is the total growth over the two years?"];
            $sum = $a + $b;
            $opts = [["{$t}%", "{$t}%"], ["{$sum}%", "{$sum}%"], [($sum * 2) . '%', ($sum * 2) . '%'], [round($total / 2) . '%', round($total / 2) . '%']];
    }
    $correct = $opts[0];
    [$options, $key] = iqe_place($r, $correct, array_slice($opts, 1));
    return [
        'prompt_ar' => $q['ar'],
        'prompt_en' => $q['en'],
        'options' => array_map(fn($o) => ['text_ar' => $o[0], 'text_en' => $o[1]], $options),
        'answer' => $key,
    ];
}

/* ---------- scoring: item response theory ---------- */

/** The fitted difficulties, read once per request. $replace swaps them (the calibration script). */
function iqe_calibration(?array $replace = null): array
{
    static $cal = null;
    if ($replace !== null) {
        $cal = $replace;
    }
    if ($cal === null) {
        $raw = class_exists('IqStore') ? IqStore::setting('calibration') : null;
        $cal = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $cal;
}

/** Difficulty of a (kind, level). A calibration fitted from real attempts overrides the design value. */
function iqe_item_b(string $kind, int $level): float
{
    $cal = iqe_calibration();
    return (float)($cal[$kind][(string)$level] ?? IQE_B[$level]);
}

/** Discrimination of a kind; the calibration rescales them all with the norm group's spread. */
function iqe_item_a(string $kind): float
{
    return IQE_A[$kind] * (float)(iqe_calibration()['_a'] ?? 1.0);
}

/**
 * Fits the norms from finished attempts. $people is a list of item lists ([kind, level, right]).
 * It alternates two steps: each person's ability from the current difficulties, then each
 * difficulty from those abilities (maximum likelihood over a grid). Cells with too few answers
 * keep their current value. Last, the scale is anchored on the group: its mean becomes IQ 100
 * and its spread 15 points, which is what "normed" means.
 */
function iqe_fit(array $people, int $iterations = 12, int $cellMin = 30): array
{
    $cal = iqe_calibration();
    $cells = [];
    foreach ($people as $p => $items) {
        foreach ($items as [$kind, $level, $right]) {
            $cells["$kind|$level"][] = [$p, $right];
        }
    }
    for ($it = 0; $it < $iterations; $it += 1) {
        iqe_calibration($cal);
        $thetas = array_map(fn($items) => iqe_estimate($items)[0], $people);
        foreach ($cells as $key => $answers) {
            if (count($answers) < $cellMin) continue;
            [$kind, $level] = explode('|', $key);
            $a = iqe_item_a($kind);
            $c = 1 / IQE_OPTIONS[$kind];
            $best = 0.0;
            $bestLl = -INF;
            for ($b = -3.5; $b <= 3.5001; $b += 0.02) {
                $ll = 0.0;
                foreach ($answers as [$p, $right]) {
                    $pr = $c + (1 - $c) / (1 + exp(-1.7 * $a * ($thetas[$p] - $b)));
                    $ll += log($right ? $pr : 1 - $pr);
                }
                if ($ll > $bestLl) { $bestLl = $ll; $best = $b; }
            }
            $cal[$kind][$level] = round($best, 3);
        }
    }
    // Anchor: the group's mean and spread define the scale.
    iqe_calibration($cal);
    $thetas = array_map(fn($items) => iqe_estimate($items)[0], $people);
    $n = count($thetas);
    $mean = $n ? array_sum($thetas) / $n : 0.0;
    $sd = $n > 1 ? sqrt(array_sum(array_map(fn($t) => ($t - $mean) ** 2, $thetas)) / ($n - 1)) : 1.0;
    $sd = max(0.4, $sd);
    foreach (IQE_LEVELS as $kind => $levels) {
        foreach ($levels as $level) {
            $b = (float)($cal[$kind][(string)$level] ?? IQE_B[$level]);
            $cal[$kind][(string)$level] = round(($b - $mean) / $sd, 3);
        }
    }
    $cal['_a'] = round((float)($cal['_a'] ?? 1.0) * $sd, 4);
    $cal['_n'] = $n;
    iqe_calibration($cal);
    return $cal;
}

/** Chance of a right answer at ability $theta (3PL). */
function iqe_p(float $theta, string $kind, int $level): float
{
    $c = 1 / IQE_OPTIONS[$kind];
    return $c + (1 - $c) / (1 + exp(-1.7 * iqe_item_a($kind) * ($theta - iqe_item_b($kind, $level))));
}

/** How much one more item of this kind and level would tell us at $theta. */
function iqe_info(float $theta, string $kind, int $level): float
{
    $c = 1 / IQE_OPTIONS[$kind];
    $p = iqe_p($theta, $kind, $level);
    return (1.7 * iqe_item_a($kind)) ** 2 * (($p - $c) ** 2 / (1 - $c) ** 2) * ((1 - $p) / $p);
}

/**
 * Ability from the answers so far: the mean and spread of the posterior over a grid, with a
 * standard normal prior (expected a posteriori). $items is a list of [kind, level, right].
 */
function iqe_estimate(array $items): array
{
    $sum = 0.0; $mean = 0.0; $sq = 0.0;
    for ($t = -4.0; $t <= 4.0001; $t += 0.05) {
        $w = exp(-$t * $t / 2);
        foreach ($items as [$kind, $level, $right]) {
            $p = iqe_p($t, $kind, (int)$level);
            $w *= $right ? $p : 1 - $p;
        }
        $sum += $w; $mean += $w * $t; $sq += $w * $t * $t;
    }
    $mean /= $sum;
    return [$mean, sqrt(max(0.0, $sq / $sum - $mean * $mean))];
}

/** The level of the next question: the one that tells the most, a little below the estimate. */
function iqe_next_level(string $kind, float $theta): int
{
    $best = IQE_LEVELS[$kind][0];
    $bestInfo = -1.0;
    foreach (IQE_LEVELS[$kind] as $level) {
        $info = iqe_info($theta - 0.3, $kind, $level);
        if ($info > $bestInfo) { $best = $level; $bestInfo = $info; }
    }
    return $best;
}

function iqe_from_theta(float $theta): int
{
    return (int)max(IQE_MIN, min(IQE_MAX, round(100 + 15 * $theta)));
}

/** Share of people below $theta (normal curve). */
function iqe_percentile(float $theta): int
{
    $z = $theta / M_SQRT2;
    // Abramowitz and Stegun 7.1.26
    $t = 1 / (1 + 0.3275911 * abs($z));
    $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$z * $z);
    $erf = $z >= 0 ? $y : -$y;
    return (int)max(1, min(99, round(50 * (1 + $erf))));
}

function iqe_band(int $iq): array
{
    foreach (IQE_BANDS as [$min, $ar, $en]) {
        if ($iq >= $min) return ['ar' => $ar, 'en' => $en];
    }
    return ['ar' => IQE_BANDS[5][1], 'en' => IQE_BANDS[5][2]];
}
