<?php
/**
 * Cardify PDF template importer.
 *
 * Splits the work in two:
 *  1. parser-stage output (run by /printshop/import_pdf.php)
 *  2. user-confirmed persistence (run by /printshop/persist_template.php)
 *
 * The parser auto-detects text spans and emits a flat array of blocks with
 * detected text + bbox + font + colour + a *suggested* field binding. The
 * onboarding UI then lets the user accept or override each suggestion (e.g.
 * keep "An Omantel Company" as static decoration but bind the detected
 * "M +968 ..." span to Mobile EN). Only after the user confirms do we
 * write the templates rows.
 *
 * This file is the single source of truth for both:
 *   - the "blocks-for-review" shape returned to the wizard
 *   - the "fields_json" + "settings_json" shape that the editor + portal read
 */

class CardifyTemplateImporter
{
    /**
     * Translate a parser page (array of detected text spans + qr_area) into
     * the dict-shaped fields_json the editor + portal expect, given a set
     * of user-confirmed bindings.
     *
     * @param array $page    one entry from parser output['pages'][]
     * @param array $bindings  ['<block_id>' => 'static' | 'skip' | '<typed_key>']
     *                         where typed_key ∈ {name_en, name_ar, position_en,
     *                         position_ar, company_en, company_ar, mobile,
     *                         mobile_ar, phone, phone_ar, fax, email,
     *                         website, website_ar, address, address_en,
     *                         address_2_en, address_ar, address_2_ar, social}.
     *                         A missing binding for a block defaults to
     *                         'static' (preserved verbatim).
     * @return array  fields_dict ready for json_encode into fields_json
     */
    public static function translatePage(array $page, array $bindings = []): array
    {
        $out = [];
        $usedKeys = [];
        $staticIdx = 0;

        // Detect text alignment once, up front, by clustering lines that
        // share a common edge. Alignment is a property of how STACKED lines
        // relate to each other, NOT where the block sits on the card. A
        // right-aligned block can live entirely in the card's left half (e.g.
        // text beside a right-side QR), so a card-center heuristic mis-reads
        // it as left-aligned and dynamic per-employee text then drifts off the
        // shared edge. detectAlignments() returns 'left'|'right'|'center'|null
        // per field index (null = undecided, fall back to script default).
        $cardWidthPx = (float)(($page['width_pt'] ?? 262.55) * 300 / 72);
        $alignByIdx  = self::detectAlignments($page['fields'] ?? [], $cardWidthPx);

        foreach (($page['fields'] ?? []) as $idx => $f) {
            $blockId = 'block_' . $idx;
            $binding = $bindings[$blockId] ?? 'static';

            if ($binding === 'skip') {
                continue;
            }

            $isStatic = ($binding === 'static');
            if ($isStatic) {
                $staticIdx++;
                $key = 'static_' . $staticIdx;
            } else {
                $key = $binding;
            }

            // Disambiguate duplicate typed keys (two M-phones, two emails).
            if (isset($usedKeys[$key])) {
                $usedKeys[$key]++;
                $key = $key . '_' . $usedKeys[$key];
            } else {
                $usedKeys[$key] = 1;
            }

            $label = null;
            if ($isStatic) {
                $sample = trim((string)($f['detected_text'] ?? ''));
                if ($sample !== '') {
                    $short = mb_strimwidth($sample, 0, 22, '…');
                    $label = 'Decoration: ' . $short;
                }
            }

            // Keep the numeric weight Fabric accepts so Lato-Medium (500),
            // Sora-Regular (400), Inter-Bold (700) etc each pick the right
            // font face. Mapping everything to 'normal' / 'bold' strings
            // collapses Medium / SemiBold / Light into Regular and the
            // rendered text reads lighter or heavier than the source PDF.
            $weight = isset($f['font_weight']) ? (int)$f['font_weight'] : 400;
            if ($weight < 100 || $weight > 900) $weight = 400;

            // Alignment: explicit parser hint > clustered shared-edge result >
            // script default (Arabic/RTL = right, Latin = left). The clustered
            // result is what fixes right-aligned blocks that sit off-center
            // (see detectAlignments + the pre-pass above).
            // NOTE: `x` stays the bbox LEFT edge (x_px). Every renderer derives
            // the visual anchor from (x, width, originX): the RTL bitmap path
            // in card-editor.js uses (x + width) for right-align, and the LTR
            // IText path does the same. Storing x as the right edge here would
            // double-count the width on the RTL path (Arabic shoots off-card).
            $scriptDefault = preg_match('/[\x{0590}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string)($f['detected_text'] ?? ''))
                ? 'right' : 'left';
            $textAlign = $f['align'] ?? ($alignByIdx[$idx] ?? $scriptDefault);
            if (!in_array($textAlign, ['left', 'center', 'right'], true)) $textAlign = 'left';
            $originX   = $textAlign;  // origin tracks alignment

            $out[$key] = [
                'enabled'       => true,
                'is_static'     => $isStatic,
                // Mirror is_static onto render_in_bg so render-card-pdf.py
                // and the Fabric renderers skip baked-in decorations
                // instead of double-striking on top of the bg PNG/SVG.
                'render_in_bg'  => $isStatic,
                'label'         => $label,
                'detected_text' => $f['detected_text'] ?? '',
                'x'             => (int)($f['x_px'] ?? 0),
                'y'             => (int)($f['y_px'] ?? 0),
                'width'         => (int)($f['w_px'] ?? 0),
                'height'        => (int)($f['h_px'] ?? 0),
                'fontSize'      => (int)($f['font_size_px'] ?? 14),
                'fontFamily'    => $f['font_family'] ?? 'Inter',
                'fontWeight'    => $weight,
                'italic'        => !empty($f['italic']),
                'fontStyle'     => !empty($f['italic']) ? 'italic' : 'normal',
                'fill'          => $f['color'] ?? '#222222',
                'color'         => $f['color'] ?? '#222222',
                'textAlign'     => $textAlign,
                'originX'       => $originX,
                'originY'       => 'top',
            ];
        }

        // QR field: always inject one, centred + 90% inside any detected
        // white square, otherwise dropped in the bottom-right corner
        // disabled by default.
        $scale = 300.0 / 72.0;
        // Canvas bounds in px (used to clamp the QR field so it can NEVER land
        // off-canvas, even if the parser hands back a bad qr_area).
        $pageWpx = (int)round((float)($page['width_pt'] ?? 255) * $scale);
        $pageHpx = (int)round((float)($page['height_pt'] ?? 165) * $scale);
        if (!empty($page['qr_area'])) {
            $qa = $page['qr_area'];
            $qWpx = (float)$qa['w_pt'] * $scale;
            $qHpx = (float)$qa['h_pt'] * $scale;
            $qXpx = (float)$qa['x_pt'] * $scale;
            $qYpx = (float)$qa['y_pt'] * $scale;
            $qrSize = (int)round(min($qWpx, $qHpx) * 0.90);
            if ($qrSize <= 0) { $qrSize = 140; }
            // Defense in depth: cap size at 45% of the smaller side and clamp
            // x/y so the whole QR stays inside the card. Without this, an
            // off-canvas qr_area (negative y / x+w past the trim) produced a
            // QR field hanging off the top-right edge on every affected import.
            $maxSize = (int)floor(min($pageWpx, $pageHpx) * 0.45);
            if ($maxSize > 40) { $qrSize = min($qrSize, $maxSize); }
            $qx = (int)round($qXpx + ($qWpx - $qrSize) / 2);
            $qy = (int)round($qYpx + ($qHpx - $qrSize) / 2);
            $qx = max(0, min($qx, $pageWpx - $qrSize));
            $qy = max(0, min($qy, $pageHpx - $qrSize));
            $out['qr_code'] = [
                'enabled' => true,
                'x'       => $qx,
                'y'       => $qy,
                'size'    => $qrSize,
            ];
            // Carry through the visual style sampled from the original PDF
            // QR (fg/bg/border) so the dynamic vCard QR matches the design
            // language instead of dropping in as plain black-on-white.
            if (!empty($qa['style']) && is_array($qa['style'])) {
                $out['qr_code']['qr_style'] = $qa['style'];
            }
        } else {
            $defaultPx = (int)round(18 / 25.4 * 300);
            $marginPx = (int)round(6 / 25.4 * 300);
            $out['qr_code'] = [
                'enabled' => false,
                'x'       => max(0, $pageWpx - $defaultPx - $marginPx),
                'y'       => max(0, $pageHpx - $defaultPx - $marginPx),
                'size'    => $defaultPx,
            ];
        }

        return $out;
    }

    /**
     * Detect per-field text alignment by shared-edge clustering.
     *
     * Groups fields whose horizontal spans overlap into columns, then for each
     * column picks the edge (left / right / center) whose positions vary the
     * least across the stacked lines. That tightest-shared edge is the design's
     * true alignment. Returns ['left'|'right'|'center'|null] keyed by the SAME
     * field index used by the caller; null = undecided (single line or no
     * clear shared edge), so the caller falls back to the script default.
     *
     * @param array $fields      parser page fields (each with x_px / w_px / y_px / h_px)
     * @param float $cardWidthPx canvas width in px (for tolerance scaling)
     * @return array<int,?string>
     */
    private static function detectAlignments(array $fields, float $cardWidthPx): array
    {
        $items = [];
        foreach ($fields as $idx => $f) {
            $x = (float)($f['x_px'] ?? 0);
            $w = (float)($f['w_px'] ?? 0);
            if ($w <= 0) { continue; } // zero-width boxes can't anchor a column
            $items[$idx] = ['l' => $x, 'r' => $x + $w, 'c' => $x + $w / 2, 'w' => $w];
        }

        $align = [];
        foreach (array_keys($items) as $i) { $align[$i] = null; }
        if (count($items) < 2) { return $align; }

        // Union-find: link fields whose horizontal spans meaningfully overlap.
        $parent = [];
        foreach (array_keys($items) as $i) { $parent[$i] = $i; }
        $find = function ($a) use (&$parent, &$find) {
            while ($parent[$a] !== $a) { $parent[$a] = $parent[$parent[$a]]; $a = $parent[$a]; }
            return $a;
        };
        $keys = array_keys($items);
        foreach ($keys as $i) {
            foreach ($keys as $j) {
                if ($j <= $i) { continue; }
                $overlap = min($items[$i]['r'], $items[$j]['r']) - max($items[$i]['l'], $items[$j]['l']);
                $minW    = max(1.0, min($items[$i]['w'], $items[$j]['w']));
                if ($overlap > 0.35 * $minW) { $parent[$find($i)] = $find($j); }
            }
        }

        $groups = [];
        foreach ($keys as $i) { $groups[$find($i)][] = $i; }

        $tol = max(6.0, $cardWidthPx * 0.012); // ~1.2% of card width
        foreach ($groups as $members) {
            if (count($members) < 2) { continue; } // single line stays null
            $ls = array_map(fn($m) => $items[$m]['l'], $members);
            $rs = array_map(fn($m) => $items[$m]['r'], $members);
            $cs = array_map(fn($m) => $items[$m]['c'], $members);
            $spread = fn($a) => max($a) - min($a);
            $sl = $spread($ls); $sr = $spread($rs); $sc = $spread($cs);
            $min = min($sl, $sr, $sc);
            if ($min > $tol) { continue; } // no edge is tight enough -> undecided
            $a = ($min === $sr) ? 'right' : (($min === $sc) ? 'center' : 'left');
            foreach ($members as $m) { $align[$m] = $a; }
        }

        return $align;
    }

    /**
     * Build the settings_json for a parser page. Always uses customUnit=mm
     * + cardSize=custom + dpi=300 so the editor renders the canvas at the
     * card's real physical size (parser coordinates are in 300 DPI px).
     */
    public static function buildSettings(array $page, string $token, array $fontFamilies): array
    {
        $widthPt    = (float)($page['width_pt']  ?? 255);
        $heightPt   = (float)($page['height_pt'] ?? 165);
        $widthMm    = round($widthPt  * 25.4 / 72, 2);
        $heightMm   = round($heightPt * 25.4 / 72, 2);

        return [
            'cardSize'      => 'custom',
            'customWidth'   => $widthMm,
            'customHeight'  => $heightMm,
            'customUnit'    => 'mm',
            'dpi'           => 300,
            'width_pt'      => $widthPt,
            'height_pt'     => $heightPt,
            'qr_area'       => $page['qr_area'] ?? null,
            'fonts_used'    => array_values($fontFamilies),
            'imported_from' => 'pdf',
            'import_token'  => $token,
        ];
    }

    /**
     * Suggest the most likely binding for each detected block so the
     * review UI can pre-fill its dropdowns (user accepts or overrides).
     *
     * Suggestions are heuristic, rules of thumb based on the parser's
     * field_key classification + detected text shape:
     *   - "M +968 ..."  → mobile      (M prefix from PDF designer convention)
     *   - "T +968 ..."  → phone
     *   - "F +968 ..."  → fax
     *   - "E foo@..."   → email
     *   - "+968 ..."    → mobile
     *   - "foo@bar.x"   → email
     *   - "https?://..", "www.."  → website
     *   - largest text in upper half (multi-word, no digits)  → name_en
     *   - text containing a position keyword                  → position_en
     *   - "@otech"-style social handles, "An Omantel Company"
     *     style brand taglines, anything inside the QR square → static
     *
     * If the parser's classification matches a typed key, suggest that
     * key, otherwise default to 'static'.
     */
    public static function suggestBinding(array $f): string
    {
        if (!empty($f['is_static'])) return 'static';
        $base = $f['field_key'] ?? 'custom';
        $t = ltrim((string)($f['detected_text'] ?? ''));

        if ($base === 'mobile' && strlen($t) >= 2) {
            $first = strtoupper(substr($t, 0, 1));
            if ($first === 'T') return 'phone';
            if ($first === 'F') return 'fax';
            return 'mobile';
        }

        $typed = ['name_en','position_en','mobile','email','website','address'];
        if (in_array($base, $typed, true)) {
            return $base;
        }
        return 'static';
    }

    /**
     * Write or replace the templates rows for a company's import_token.
     * Returns ['front' => $tplId, 'back' => $tplId, 'pair_id' => $pairId].
     *
     * Drops any previous templates rows attached to this token first so
     * re-running the importer is idempotent (the user can edit bindings
     * in the review UI and re-confirm without leaving stale rows).
     */
    public static function persist(
        Database $db,
        string $companyId,
        string $token,
        string $outRel,
        string $origName,
        array $pages,
        array $bindingsByPage,
        ?string $fontsDirRel = null
    ): array {
        // Drop any previous templates that point at this token's source PDF.
        $sourcePath = $outRel . '/source.pdf';
        $existing = $db->fetchAll(
            "SELECT id FROM templates WHERE company_id = :cid AND original_pdf_path = :p",
            ['cid' => $companyId, 'p' => $sourcePath]
        );
        foreach ($existing as $row) {
            $db->delete('templates', 'id = :id', ['id' => $row['id']]);
        }

        // Re-redact bg PNGs for any block the parser baked-as-static that
        // AI later reclassified to a dynamic binding. Without this the
        // dynamic text gets drawn on top of its own baked-in copy.
        self::rebakeBackgroundIfNeeded($pages, $bindingsByPage, $outRel);

        $pairId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
        $createdTemplateIds = [];

        foreach ($pages as $page) {
            $tplId = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $pageNum = (int)($page['page_number'] ?? 1);
            $side = $pageNum === 1 ? 'front' : 'back';
            $bgPath = !empty($page['background_url']) ? $page['background_url'] : null;

            $bindings = $bindingsByPage[$pageNum] ?? [];
            $fieldsDict = self::translatePage($page, $bindings);

            $fontFamilies = [];
            foreach ($fieldsDict as $f) {
                if (!empty($f['fontFamily'])) {
                    $fontFamilies[$f['fontFamily']] = true;
                }
            }

            $settings = self::buildSettings($page, $token, array_keys($fontFamilies));

            $rowData = [
                'id'                    => $tplId,
                'company_id'            => $companyId,
                'pair_id'               => $pairId,
                'name'                  => trim('Imported ' . preg_replace('/\.pdf$/i', '', $origName)) ?: 'Imported design',
                'side'                  => $side,
                'background_image_path' => $bgPath,
                'original_pdf_path'     => $sourcePath,
                'original_pdf_page'     => $pageNum,
                'fields_json'           => json_encode($fieldsDict, JSON_UNESCAPED_UNICODE),
                'settings_json'         => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'is_active'             => 1,
                'description'           => 'Auto-imported from ' . $origName,
            ];
            if ($fontsDirRel !== null) {
                $rowData['has_vector_source'] = 1;
                $rowData['fonts_dir'] = '/' . trim($fontsDirRel, '/');
            }
            $db->insert('templates', $rowData);
            $createdTemplateIds[$side] = $tplId;
        }

        if (isset($createdTemplateIds['front'], $createdTemplateIds['back'])) {
            $db->update('templates', ['paired_template_id' => $createdTemplateIds['back']],  'id = :id', ['id' => $createdTemplateIds['front']]);
            $db->update('templates', ['paired_template_id' => $createdTemplateIds['front']], 'id = :id', ['id' => $createdTemplateIds['back']]);
        }

        return [
            'pair_id'      => $pairId,
            'template_ids' => $createdTemplateIds,
        ];
    }

    /**
     * For each page, find blocks the parser marked is_static=true but
     * whose final AI binding is dynamic (name_en, mobile, email, ...).
     * The parser already baked those into bg-page-N.png; we white-rect
     * them with sampled bg color so the runtime renderer's typed-field
     * draw doesn't double-strike.
     *
     * Safe-noop when there's nothing to redact, when the bg PNG is
     * missing, or when the python helper is unavailable. Logs but does
     * NOT fail persist: a slightly-uglier card is better than no card.
     */
    public static function rebakeBackgroundIfNeeded(array $pages, array $bindingsByPage, string $outRel): void
    {
        $appRoot = realpath(__DIR__ . '/..');
        if (!$appRoot) return;
        $importDirAbs = $appRoot . $outRel;
        $script = $appRoot . '/scripts/redact_bg_post_persist.py';
        if (!is_file($script)) return;

        foreach ($pages as $page) {
            $pageNum = (int)($page['page_number'] ?? 1);
            $bindings = $bindingsByPage[$pageNum] ?? [];
            $bboxes = [];

            foreach (($page['fields'] ?? []) as $idx => $f) {
                if (empty($f['is_static'])) continue; // parser kept it dynamic, already redacted
                $blockId = 'block_' . $idx;
                $finalBinding = $bindings[$blockId] ?? 'static';
                if ($finalBinding === 'static' || $finalBinding === 'skip') continue;
                $bboxes[] = [
                    'x' => (int)($f['x_px'] ?? 0),
                    'y' => (int)($f['y_px'] ?? 0),
                    'w' => (int)($f['w_px'] ?? 0),
                    'h' => (int)($f['h_px'] ?? 0),
                ];
            }
            if (!$bboxes) continue;

            // Background PNG filename mirrors parser convention.
            $bgPath = $importDirAbs . '/bg-page-' . $pageNum . '.png';
            if (!is_file($bgPath)) {
                error_log('[rebakeBackground] missing bg: ' . $bgPath);
                continue;
            }

            $cmd = sprintf(
                'timeout 30 python3 %s %s %s 2>&1',
                escapeshellarg($script),
                escapeshellarg($bgPath),
                escapeshellarg(json_encode($bboxes))
            );
            $out = shell_exec($cmd);
            $decoded = json_decode((string)$out, true);
            if (!is_array($decoded) || empty($decoded['ok'])) {
                error_log('[rebakeBackground] page ' . $pageNum . ' failed: ' . substr((string)$out, 0, 500));
                continue;
            }

            // PIL overwrites in place, so ownership + perms carry over from
            // the parser-written file (already www:www 0644). chown/chgrp
            // are disabled by php.ini on this host; chmod is safe and idempotent.
            @chmod($bgPath, 0644);
        }
    }
}
