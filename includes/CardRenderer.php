<?php
/**
 * CardRenderer, single-source-of-truth lookup for an employee's canonical card.
 *
 * Cardify's rasterization pipeline is browser-side (Fabric.js exports PNG +
 * uploads via save_card_*.php). This class does NOT re-rasterize. It exposes
 * the canonical front/back PNG paths that EVERY consumer (digital_card.php,
 * card-pdf.php, wallet_apple/google.php, og-image, print-shop preview) MUST
 * use, plus invalidation + freshness helpers so the cache stays in sync with
 * the admin-designed template + brand theme.
 *
 * Source of truth, in order of authority:
 *   1. templates.fields_json + .background_image_path + .current_version
 *   2. company_themes (primary/secondary/logo)
 *   3. employees (name, role, contacts, photo)
 *   4. generated_cards.* (DERIVED CACHE, may be NULL when invalidated)
 */
class CardRenderer
{
    /**
     * Canonical lookup for an employee.
     *
     * @param string $employeeId UUID
     * @return array|null  ['employee'=>row, 'company'=>row, 'theme'=>row|null,
     *                      'card'=>row|null,
     *                      'front_fs'=>?string absolute filesystem path,
     *                      'back_fs'=>?string absolute filesystem path,
     *                      'front_url'=>?string public URL,
     *                      'back_url'=>?string public URL,
     *                      'is_fresh'=>bool, 'signature'=>string sha1]
     */
    public static function forEmployee(string $employeeId): ?array
    {
        if ($employeeId === '') return null;

        $db = Database::getInstance();

        // Database::fetchOne returns false (PDO default) when no row is found,
        // not null. Normalize to null/array so the strict type hints below hold.
        $employee = $db->fetchOne(
            'SELECT * FROM employees WHERE id = :id LIMIT 1',
            ['id' => $employeeId]
        );
        if (!is_array($employee)) return null;

        $company = $db->fetchOne(
            'SELECT * FROM companies WHERE id = :id LIMIT 1',
            ['id' => $employee['company_id']]
        );
        if (!is_array($company)) return null;

        $theme = null;
        try {
            $row = $db->fetchOne(
                'SELECT * FROM company_themes WHERE company_id = :cid LIMIT 1',
                ['cid' => $company['id']]
            );
            $theme = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('CardRenderer theme lookup: ' . $e->getMessage());
        }

        $cardRow = $db->fetchOne(
            'SELECT * FROM generated_cards
              WHERE employee_id = :eid AND company_id = :cid
              ORDER BY generated_at DESC LIMIT 1',
            ['eid' => $employee['id'], 'cid' => $company['id']]
        );
        $card = is_array($cardRow) ? $cardRow : null;

        // Pull the active template's design dimensions so consumers can
        // honour the admin-uploaded card size (front side wins; falls back
        // to back if no front). settings_json carries customWidth/Height in
        // mm. Default = standard business card 92.62 x 59.93 mm (1.545:1).
        $tplWidth = 92.62;
        $tplHeight = 59.93;
        try {
            $tplRow = $db->fetchOne(
                "SELECT settings_json FROM templates
                  WHERE company_id = :cid AND is_active = 1
                  ORDER BY (side = 'front') DESC, created_at DESC
                  LIMIT 1",
                ['cid' => $company['id']]
            );
            if (is_array($tplRow) && !empty($tplRow['settings_json'])) {
                $s = json_decode($tplRow['settings_json'], true);
                if (is_array($s)) {
                    if (!empty($s['customWidth']))  $tplWidth  = (float)$s['customWidth'];
                    if (!empty($s['customHeight'])) $tplHeight = (float)$s['customHeight'];
                }
            }
        } catch (Throwable $e) {
            error_log('CardRenderer template dims: ' . $e->getMessage());
        }
        $aspect = $tplHeight > 0 ? round($tplWidth / $tplHeight, 4) : 1.545;

        $frontStored = ($card['front_web_path'] ?? '') !== '' ? $card['front_web_path'] : ($card['front_file_path'] ?? null);
        $backStored  = ($card['back_web_path']  ?? '') !== '' ? $card['back_web_path']  : ($card['back_file_path']  ?? null);

        // Stored path may be a bare filename like "card_front_...png" (older
        // rows) OR a webroot-relative path like "/uploads/companies/.../cards/...png".
        // digital_card.php normalizes the same way (line 222-224).
        $cardBaseUrl = '/uploads/companies/' . $company['id'] . '/cards/';
        $frontStoredFull = self::expandStored($frontStored, $cardBaseUrl);
        $backStoredFull  = self::expandStored($backStored,  $cardBaseUrl);

        $frontFs = $frontStoredFull ? self::resolveFs($frontStoredFull) : null;
        $backFs  = $backStoredFull  ? self::resolveFs($backStoredFull)  : null;
        if ($frontFs && !is_file($frontFs)) $frontFs = null;
        if ($backFs  && !is_file($backFs))  $backFs  = null;

        $frontUrl = ($frontFs && $frontStoredFull) ? imageUrl($frontStoredFull) : null;
        $backUrl  = ($backFs  && $backStoredFull)  ? imageUrl($backStoredFull)  : null;

        // Freshness signature: rolls forward when template/theme/employee changes
        // OR when the cached PNG no longer exists on disk.
        $sig = self::signature($employee, $company, $theme, $card, $frontFs, $backFs);

        // A card is "fresh" when:
        //   - both PNGs exist on disk
        //   - the version columns on generated_cards match templates.current_version
        $isFresh = ($frontFs !== null && $backFs !== null)
            && self::matchesTemplateVersion($db, $company, $card);

        return [
            'employee'      => $employee,
            'company'       => $company,
            'theme'         => $theme,
            'card'          => $card,
            'front_fs'      => $frontFs,
            'back_fs'       => $backFs,
            'front_url'     => $frontUrl,
            'back_url'      => $backUrl,
            'is_fresh'      => $isFresh,
            'signature'     => $sig,
            'card_width_mm' => $tplWidth,
            'card_height_mm'=> $tplHeight,
            'aspect_ratio'  => $aspect,
        ];
    }

    /**
     * Mark every employee in a company as needing re-render. Called from
     * admin/save_template.php and admin/save_theme.php so the next view of
     * any consumer surface picks up the design change.
     *
     * Sets *_file_path / *_web_path to NULL on all generated_cards rows for
     * the company. Does NOT delete files, so the previous render survives
     * on disk for forensic comparison until the next save_card_both.php run.
     */
    public static function invalidateForCompany(string $companyId, ?string $reason = null): int
    {
        if ($companyId === '') return 0;
        $db = Database::getInstance();
        $stmt = $db->query(
            'UPDATE generated_cards
                SET front_file_path = NULL,
                    back_file_path  = NULL,
                    front_web_path  = NULL,
                    back_web_path   = NULL
              WHERE company_id = :cid',
            ['cid' => $companyId]
        );
        $n = $stmt->rowCount();
        if ($n > 0) {
            error_log(sprintf(
                'CardRenderer::invalidateForCompany(%s) cleared %d rows. reason=%s',
                $companyId, $n, $reason ?? 'unspecified'
            ));
        }
        return $n;
    }

    /**
     * Invalidate one employee's cached card.
     */
    public static function invalidateForEmployee(string $employeeId, ?string $reason = null): int
    {
        if ($employeeId === '') return 0;
        $db = Database::getInstance();
        $stmt = $db->query(
            'UPDATE generated_cards
                SET front_file_path = NULL,
                    back_file_path  = NULL,
                    front_web_path  = NULL,
                    back_web_path   = NULL
              WHERE employee_id = :eid',
            ['eid' => $employeeId]
        );
        $n = $stmt->rowCount();
        if ($n > 0) {
            error_log(sprintf(
                'CardRenderer::invalidateForEmployee(%s) cleared %d rows. reason=%s',
                $employeeId, $n, $reason ?? 'unspecified'
            ));
        }
        return $n;
    }

    /**
     * List employees in a company that need re-render. Used by the audit
     * script + admin "regenerate all" UI.
     *
     * @return array<int, array{employee_id:string, slug:string, name:string,
     *                          missing_front:bool, missing_back:bool,
     *                          version_drift:bool}>
     */
    public static function staleForCompany(string $companyId): array
    {
        if ($companyId === '') return [];
        $db = Database::getInstance();
        // employees.id is the URL-facing slug (e.g. "muhammed.ali"), there is
        // no separate `slug` column. See `DESCRIBE employees`.
        $rows = $db->fetchAll(
            "SELECT e.id, e.name_en,
                    gc.front_file_path, gc.back_file_path,
                    gc.front_web_path,  gc.back_web_path,
                    gc.front_template_version, gc.back_template_version
               FROM employees e
               LEFT JOIN generated_cards gc
                 ON gc.employee_id = e.id AND gc.company_id = e.company_id
              WHERE e.company_id = :cid AND e.status = 'active'
              ORDER BY e.created_at DESC",
            ['cid' => $companyId]
        );
        $tplVersions = self::templateVersionsForCompany($db, $companyId);
        $cardBaseUrl = '/uploads/companies/' . $companyId . '/cards/';
        $stale = [];
        foreach ($rows as $r) {
            $frontStored = self::expandStored($r['front_web_path'] ?: $r['front_file_path'], $cardBaseUrl);
            $backStored  = self::expandStored($r['back_web_path']  ?: $r['back_file_path'],  $cardBaseUrl);
            $missingFront = $frontStored === null || !is_file(self::resolveFs($frontStored));
            $missingBack  = $backStored  === null || !is_file(self::resolveFs($backStored));
            $drift = false;
            if ($tplVersions['front'] !== null && (int)($r['front_template_version'] ?? 0) !== $tplVersions['front']) $drift = true;
            if ($tplVersions['back']  !== null && (int)($r['back_template_version']  ?? 0) !== $tplVersions['back'])  $drift = true;
            if ($missingFront || $missingBack || $drift) {
                $stale[] = [
                    'employee_id'   => (string)$r['id'],
                    'slug'          => (string)$r['id'], // employees.id is the URL slug
                    'name'          => (string)($r['name_en'] ?? ''),
                    'missing_front' => $missingFront,
                    'missing_back'  => $missingBack,
                    'version_drift' => $drift,
                ];
            }
        }
        return $stale;
    }

    // ---- internals ---------------------------------------------------------

    private static function resolveFs(string $stored): string
    {
        if ($stored === '') return '';
        if ($stored[0] === '/' || preg_match('#^[a-zA-Z]+://#', $stored)) {
            return getFilePath($stored);
        }
        return BASE_DIR . '/' . ltrim($stored, '/');
    }

    /**
     * Match digital_card.php's normalization, a bare filename gets prefixed
     * with the company cards URL, an already-absolute path is left alone.
     */
    private static function expandStored(?string $stored, string $cardBaseUrl): ?string
    {
        if ($stored === null || $stored === '') return null;
        if (strpos($stored, '/') === false) {
            return $cardBaseUrl . $stored;
        }
        return $stored;
    }

    private static function matchesTemplateVersion($db, array $company, ?array $card): bool
    {
        if (!$card) return false;
        $v = self::templateVersionsForCompany($db, $company['id']);
        $okFront = ($v['front'] === null) || ((int)($card['front_template_version'] ?? 0) === $v['front']);
        $okBack  = ($v['back']  === null) || ((int)($card['back_template_version']  ?? 0) === $v['back']);
        return $okFront && $okBack;
    }

    /** @return array{front:?int, back:?int} */
    private static function templateVersionsForCompany($db, string $companyId): array
    {
        static $cache = [];
        if (isset($cache[$companyId])) return $cache[$companyId];
        $front = null; $back = null;
        try {
            $row = $db->fetchOne(
                "SELECT
                    (SELECT current_version FROM templates
                       WHERE company_id = :cf AND side = 'front' AND is_active = 1
                       ORDER BY created_at DESC LIMIT 1) AS fv,
                    (SELECT current_version FROM templates
                       WHERE company_id = :cb AND side = 'back'  AND is_active = 1
                       ORDER BY created_at DESC LIMIT 1) AS bv",
                ['cf' => $companyId, 'cb' => $companyId]
            );
            if (is_array($row)) {
                $front = isset($row['fv']) ? (int)$row['fv'] : null;
                $back  = isset($row['bv']) ? (int)$row['bv'] : null;
            }
        } catch (Throwable $e) {
            error_log('CardRenderer::templateVersionsForCompany: ' . $e->getMessage());
        }
        return $cache[$companyId] = ['front' => $front, 'back' => $back];
    }

    private static function signature(array $employee, array $company, ?array $theme, ?array $card, ?string $frontFs, ?string $backFs): string
    {
        $parts = [
            $employee['id']         ?? '',
            $employee['updated_at'] ?? '',
            $company['id']          ?? '',
            $company['updated_at']  ?? '',
            $theme['updated_at']    ?? '',
            $card['generated_at']   ?? '',
            $card['front_template_version'] ?? '',
            $card['back_template_version']  ?? '',
            $frontFs ? @filemtime($frontFs) : '',
            $backFs  ? @filemtime($backFs)  : '',
        ];
        return sha1(implode('|', array_map('strval', $parts)));
    }
}
