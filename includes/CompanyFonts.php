<?php
/**
 * Cardify font registry.
 *
 * Two scopes:
 *   1. Library  - global pool under /uploads/fonts/library/.
 *      Built from Ali's installed Mac fonts; every tenant can use them.
 *   2. Company  - per-tenant uploads under /uploads/fonts/companies/<id>/.
 *      What a company admin uploads from the onboarding "missing fonts"
 *      review pane. Take precedence over library when family + weight
 *      collide.
 *
 * The library is enormous (thousands of faces), so we don't emit a
 * @font-face rule for every file. Instead the portal + editor look at
 * which families a template actually uses (settings.fonts_used or by
 * scanning fields_json) and only declare the matching faces.
 *
 * Library index lives at /uploads/fonts/library/index.json. It maps
 * family -> [ { weight, style, file } ]. Built by build-library-index.php
 * once (and re-built whenever new fonts are uploaded).
 */
class CompanyFonts
{
    public const LIBRARY_DIR  = '/uploads/fonts/library';
    public const COMPANY_DIR  = '/uploads/fonts/companies';

    /**
     * Map weight keyword to numeric weight. Mirrors the parser's
     * font_to_family_and_weight() logic so files dropped onto disk
     * with the same naming convention sort cleanly.
     */
    private const WEIGHT_MAP = [
        'thin' => 100, 'hairline' => 100,
        'extralight' => 200, 'ultralight' => 200,
        'light' => 300,
        'regular' => 400, 'normal' => 400, 'book' => 400,
        'medium' => 500,
        'semibold' => 600, 'demibold' => 600,
        'bold' => 700,
        'extrabold' => 800, 'ultrabold' => 800, 'heavy' => 800,
        'black' => 900,
    ];

    /**
     * Parse a font filename like "Lato-Medium.ttf" into a structured
     * face descriptor: family, weight, style. Returns null if the
     * filename doesn't look like a font we can serve.
     */
    public static function describeFile(string $filename): ?array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'], true)) {
            return null;
        }
        // strip subset prefix (Adobe + Google subsetters use ABCDEF+Family)
        $base = preg_replace('/^[A-Z]{6}\+/', '', $base);

        $parts  = preg_split('/[-_\s]+/', $base);
        $family = $parts[0] ?? $base;
        $weight = 400;
        $style  = 'normal';

        for ($i = 1; $i < count($parts); $i++) {
            $tok = strtolower($parts[$i]);
            $tokNoIt = str_replace(['italic', 'oblique', 'it'], '', $tok);

            if (str_contains($tok, 'italic') || str_contains($tok, 'oblique')) {
                $style = 'italic';
            }
            if (isset(self::WEIGHT_MAP[$tokNoIt]) && $tokNoIt !== '') {
                $weight = self::WEIGHT_MAP[$tokNoIt];
            } elseif (isset(self::WEIGHT_MAP[$tok])) {
                $weight = self::WEIGHT_MAP[$tok];
            } else {
                // Multi-token family names (Plex Sans, Work Sans, ...).
                // If we haven't yet matched a weight or italic keyword,
                // append to the family.
                if ($style === 'normal' && $weight === 400 && !preg_match('/italic|oblique/i', $tok)) {
                    $family .= ' ' . ucfirst($tok);
                }
            }
        }

        return [
            'family' => $family,
            'weight' => $weight,
            'style'  => $style,
            'ext'    => $ext,
        ];
    }

    /**
     * Build (or rebuild) the library index by walking the library dir.
     * Cheap enough to run synchronously after an upload batch.
     */
    public static function rebuildLibraryIndex(string $appRoot): array
    {
        $libAbs = $appRoot . self::LIBRARY_DIR;
        if (!is_dir($libAbs)) return [];

        $byFamily = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libAbs, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            $desc = self::describeFile($name);
            if (!$desc) continue;
            $rel = ltrim(str_replace($appRoot, '', $f->getPathname()), '/');
            $family = $desc['family'];
            $byFamily[$family][] = [
                'weight' => $desc['weight'],
                'style'  => $desc['style'],
                'file'   => '/' . $rel,
                'ext'    => $desc['ext'],
            ];
        }
        // Sort weights ascending per family for stable output.
        foreach ($byFamily as $family => &$faces) {
            usort($faces, function ($a, $b) {
                if ($a['weight'] !== $b['weight']) return $a['weight'] <=> $b['weight'];
                return strcmp($a['style'], $b['style']);
            });
        }
        unset($faces);

        ksort($byFamily, SORT_NATURAL | SORT_FLAG_CASE);

        $indexPath = $libAbs . '/index.json';
        file_put_contents($indexPath, json_encode($byFamily, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $byFamily;
    }

    /**
     * Read the library index, building it on demand if missing.
     * Returns an array keyed by family name.
     */
    public static function libraryIndex(string $appRoot): array
    {
        $indexPath = $appRoot . self::LIBRARY_DIR . '/index.json';
        if (is_file($indexPath)) {
            $raw = json_decode(file_get_contents($indexPath), true);
            if (is_array($raw)) return $raw;
        }
        return self::rebuildLibraryIndex($appRoot);
    }

    /**
     * Per-company uploaded fonts (admin sideloads in the onboarding
     * review pane). Same shape as library index: family => faces[].
     */
    public static function companyIndex(string $appRoot, string $companyId): array
    {
        $compAbs = $appRoot . self::COMPANY_DIR . '/' . $companyId;
        if (!is_dir($compAbs)) return [];

        $byFamily = [];
        foreach (scandir($compAbs) as $name) {
            if ($name === '.' || $name === '..') continue;
            $desc = self::describeFile($name);
            if (!$desc) continue;
            $rel = self::COMPANY_DIR . '/' . $companyId . '/' . $name;
            $byFamily[$desc['family']][] = [
                'weight' => $desc['weight'],
                'style'  => $desc['style'],
                'file'   => $rel,
                'ext'    => $desc['ext'],
            ];
        }
        return $byFamily;
    }

    /**
     * Filter a parser-emitted missing_fonts list to drop entries that the
     * company has already uploaded via the dashboard / onboarding wizard.
     * Match is case-insensitive on the basename (raw_name), so re-importing
     * the same PDF after uploading DINNextLTArabic-Medium.ttf will no longer
     * re-prompt for that file.
     */
    public static function filterMissingByCompanyUploads(string $appRoot, ?string $companyId, array $missing): array
    {
        if (!$companyId) return $missing;
        $compAbs = $appRoot . self::COMPANY_DIR . '/' . $companyId;
        if (!is_dir($compAbs)) return $missing;

        $haveBaseNames = [];
        foreach (scandir($compAbs) as $name) {
            if ($name === '.' || $name === '..') continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) continue;
            $haveBaseNames[strtolower(pathinfo($name, PATHINFO_FILENAME))] = true;
        }
        if (!$haveBaseNames) return $missing;

        $out = [];
        foreach ($missing as $entry) {
            $raw = strtolower((string)($entry['raw_name'] ?? ''));
            if ($raw !== '' && isset($haveBaseNames[$raw])) continue;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Per-template imported fonts (the PDF importer extracts every embedded
     * font into uploads/templates/imports/<token>/fonts/). These take top
     * priority because the template was designed against THESE exact files.
     */
    public static function templateImportsIndex(string $appRoot, array $tokens): array
    {
        $byFamily = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[^a-z0-9_-]/i', '', (string)$token);
            if ($token === '') continue;
            $dir = $appRoot . '/uploads/templates/imports/' . $token . '/fonts';
            if (!is_dir($dir)) continue;
            // Manifest is the source of truth for family names (the importer
            // wrote it from the actual PDF font tables). Filename parsing
            // would lose hyphens that the design relies on (Arsenica-Antiqua
            // vs "Arsenica Antiqua").
            $manifest = [];
            $manifestPath = $dir . '/manifest.json';
            if (is_file($manifestPath)) {
                $m = json_decode(file_get_contents($manifestPath), true);
                if (is_array($m)) {
                    foreach ($m as $entry) {
                        if (!is_array($entry) || empty($entry['file']) || empty($entry['family'])) continue;
                        $manifest[$entry['file']] = $entry;
                    }
                }
            }
            foreach (scandir($dir) as $name) {
                if ($name === '.' || $name === '..' || $name === 'manifest.json') continue;
                $desc = self::describeFile($name);
                if (!$desc) continue;
                $rel = '/uploads/templates/imports/' . $token . '/fonts/' . $name;
                // Prefer manifest's family (canonical PDF name); use describeFile's
                // weight/style detection. Register under BOTH the canonical name
                // (e.g. "Arsenica-Antiqua") AND the bare base ("Arsenica") so
                // legacy fields_json that stored only the base resolves too.
                $ment      = $manifest[$name] ?? [];
                $canonical = $ment['family'] ?? $desc['family'];
                // Trust the manifest's weight/style when present: filename
                // parsing mis-reads faces like "FrutigerLTArabic-55Roman"
                // (digit-prefixed weight tokens) and "FrutigerLTStd-Roman"
                // ("Roman" is not a weight keyword), so describeFile flattens
                // them all to 400 and mangles the family. The manifest is the
                // source of truth the importer wrote from the real font tables.
                $weight = isset($ment['weight']) ? (int)$ment['weight'] : $desc['weight'];
                $style  = $ment['style'] ?? $desc['style'];
                // The raw filename stem (minus any subset prefix) is a valid
                // family alias: a field may reference a specific face by its
                // exact file name (e.g. fontFamily="FrutigerLTStd-Black").
                $stem = preg_replace('/^[A-Z]{6}\+/', '', pathinfo($name, PATHINFO_FILENAME));
                $baseFamily = preg_replace('/[-_\s](Antiqua|Regular|Roman|Medium|Bold|Light|Thin|Book|Black|SemiBold|ExtraBold|ExtraLight|Italic|Oblique|\d+\w+).*$/i', '', $canonical);
                foreach (array_unique([$canonical, $stem, $baseFamily, $desc['family']]) as $fam) {
                    if (!$fam) continue;
                    $byFamily[$fam][] = [
                        'weight' => $weight,
                        'style'  => $style,
                        'file'   => $rel,
                        'ext'    => $desc['ext'],
                    ];
                }
            }
        }
        return $byFamily;
    }

    /**
     * Emit @font-face declarations for the given family list. Resolution
     * order: template imports (PDF-extracted) > company uploads > library.
     * The template imports always win because they are the exact files the
     * design was built against.
     */
    public static function fontFaceCss(string $appRoot, string $companyId, array $familiesNeeded, array $templateTokens = []): string
    {
        if (empty($familiesNeeded)) return '';
        $library  = self::libraryIndex($appRoot);
        $company  = self::companyIndex($appRoot, $companyId);
        $template = self::templateImportsIndex($appRoot, $templateTokens);
        $css = '';
        $emitted = [];

        foreach ($familiesNeeded as $family) {
            $library_faces  = $library[$family]  ?? [];
            $company_faces  = $company[$family]  ?? [];
            $template_faces = $template[$family] ?? [];
            // Template imports first, then company, then library
            $faces = array_merge($template_faces, $company_faces, $library_faces);
            foreach ($faces as $face) {
                $key = $family . '|' . $face['weight'] . '|' . $face['style'];
                if (isset($emitted[$key])) continue;
                $emitted[$key] = true;
                $format = $face['ext'] === 'woff2' ? 'woff2'
                        : ($face['ext'] === 'woff' ? 'woff'
                        : ($face['ext'] === 'otf'  ? 'opentype' : 'truetype'));
                // Cache-bust by file mtime so swapping a TTF on disk invalidates
                // both the browser cache and Cloudflare edge cache without a
                // manual purge (CF API token has no Cache Purge scope).
                $absPath = $appRoot . '/' . ltrim($face['file'], '/');
                $mtime = is_file($absPath) ? @filemtime($absPath) : 0;
                $url = $face['file'] . '?v=' . ($mtime ?: 1);
                $css .= sprintf(
                    "@font-face { font-family: '%s'; font-weight: %d; font-style: %s; src: url('%s') format('%s'); font-display: swap; }\n",
                    addslashes($family),
                    (int)$face['weight'],
                    $face['style'],
                    htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                    $format
                );
            }
        }
        return $css;
    }

    /**
     * Compute the set of families a fields_json dict references so the
     * caller can ask fontFaceCss for just those.
     */
    public static function familiesUsed(array $fieldsDict): array
    {
        $set = [];
        foreach ($fieldsDict as $f) {
            if (!empty($f['fontFamily'])) $set[$f['fontFamily']] = true;
        }
        return array_keys($set);
    }
}
