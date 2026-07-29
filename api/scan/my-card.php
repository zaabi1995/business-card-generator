<?php
/**
 * GET|POST /api/scan/my-card.php, the logged-in employee's OWN digital card.
 *
 * Bearer-authenticated (ScanAuth). The Cardify Scan mobile app uses this so an
 * employee can read and edit their own Cardify card (contact fields + brand
 * design) and grab the Apple Wallet pass URL, without the web admin.
 *
 * GET  -> {success, card:{ ...canonical parsed shape, design:{...},
 *          render?:{front_url,back_url,aspect_ratio,signature},
 *          public_url, qr_url, wallet_pass_url }}
 * POST -> {success, brand_locked, card:{...}} after a partial update.
 *
 * The canonical card shape is CardifyConvention::employeeToScanCard() (the same
 * mapping resolve-card.php / vcf return). Persistence reuses the exact web-editor
 * path: DatabaseAdapter::updateEmployee() for contact fields (fed a row MERGED
 * over the current values so a partial POST never blanks an untouched column),
 * the company_themes update mirror of admin/theme.php for the brand colour, and
 * CardRenderer cache invalidation. Any accent colour is passed through
 * ColorContrast::safeAccent() before it is stored, never trusted raw.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';
require_once INCLUDES_DIR . '/ColorContrast.php';
require_once INCLUDES_DIR . '/AppleWalletPass.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET or POST only']);
    exit;
}

$ctx = $method === 'POST'
    ? ScanAuth::requireEmployeeMutation()
    : ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'my_card', 600);
require_once __DIR__ . '/_brand_guard.php';

try {
    $db = Database::getInstance();
    $employeeId = $ctx['employee_id'];
    $companyId  = $ctx['company_id'];

    $employee = $db->fetchOne(
        "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
        ['id' => $employeeId, 'cid' => $companyId]
    );
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }
    $company = $db->fetchOne("SELECT * FROM companies WHERE id = :cid", ['cid' => $companyId]);
    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'company_not_found']);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        // Cap a free-text string to a sane column length. TEXT columns
        // (addresses) get more room than VARCHAR ones.
        // mb_substr, NOT substr: byte truncation splits a multi-byte character
        // in half, and the resulting invalid UTF-8 makes json_encode() return
        // false further down, which this endpoint echoes as an EMPTY 200 body.
        // That bricks the employee's own My Card and My details screens. A
        // mixed title like CEO - الرئيس التنفيذي lands on the boundary easily.
        $cap = function ($v, int $max = 255): string {
            return mb_substr(trim((string) $v), 0, $max, 'UTF-8');
        };

        // Contact fields go through the SAME helper the web editor uses
        // (DatabaseAdapter::updateEmployee). That helper overwrites EVERY
        // column it knows about, so start from the current row and overlay
        // only the keys the request actually sent; an absent key keeps its
        // stored value instead of being blanked.
        $merged = [
            'email'                 => $employee['email'] ?? '',
            'department_id'         => $employee['department_id'] ?? null,
            'name_en'               => $employee['name_en'] ?? '',
            'name_ar'               => $employee['name_ar'] ?? '',
            'position_en'           => $employee['position_en'] ?? '',
            'position_ar'           => $employee['position_ar'] ?? '',
            'phone'                 => $employee['phone'] ?? '',
            'phone_ar'              => $employee['phone_ar'] ?? '',
            'mobile'                => $employee['mobile'] ?? '',
            'mobile_ar'             => $employee['mobile_ar'] ?? '',
            'company_en'            => $employee['company_en'] ?? '',
            'company_ar'            => $employee['company_ar'] ?? '',
            'website'               => $employee['website'] ?? '',
            'website_ar'            => $employee['website_ar'] ?? '',
            'address_en'            => $employee['address_en'] ?? '',
            'address_2_en'          => $employee['address_2_en'] ?? '',
            'address_ar'            => $employee['address_ar'] ?? '',
            'address_2_ar'          => $employee['address_2_ar'] ?? '',
            'qr_redirect_url'       => $employee['qr_redirect_url'] ?? null,
            'card_dark_mode_toggle' => $employee['card_dark_mode_toggle'] ?? 1,
            'hide_cardify_branding' => $employee['hide_cardify_branding'] ?? 0,
            // Seeded like every other column: updateEmployee() writes
            // trim($data['fax'] ?? ''), so omitting it BLANKED the fax on
            // every save from the app that did not happen to send one.
            'fax'                   => $employee['fax'] ?? '',
        ];

        // Editable contact fields. title_* map onto the position_* columns
        // (the canonical card shape calls the job title "title").
        $strMap = [
            'name_en'    => ['name_en', 120],
            'name_ar'    => ['name_ar', 120],
            'title_en'   => ['position_en', 150],
            'title_ar'   => ['position_ar', 150],
            'company_en' => ['company_en', 150],
            'company_ar' => ['company_ar', 150],
            'mobile'     => ['mobile', 50],
            'phone'      => ['phone', 50],
            'email'      => ['email', 255],
            'website'    => ['website', 255],
            'address_en' => ['address_en', 1000],
            'address_ar' => ['address_ar', 1000],
        ];
        foreach ($strMap as $in => [$col, $max]) {
            if (array_key_exists($in, $body)) {
                $merged[$col] = $cap($body[$in], $max);
            }
        }

        // dark_mode -> card_dark_mode_toggle (1/0). Accepts bool, 0/1, "true".
        if (array_key_exists('dark_mode', $body)) {
            $dm = $body['dark_mode'];
            $merged['card_dark_mode_toggle'] =
                ($dm === true || $dm === 1 || $dm === '1' || strtolower((string) $dm) === 'true') ? 1 : 0;
        }

        $result = updateEmployee($employeeId, $merged, $companyId);
        if (!($result['success'] ?? false)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'update_failed']);
            exit;
        }

        // fax + card_template_id are real employees columns the web-editor
        // helper does not touch, so persist them with a scoped UPDATE when sent.
        if (array_key_exists('fax', $body)) {
            $db->update('employees', ['fax' => $cap($body['fax'], 50)], 'id = :id AND company_id = :cid',
                ['id' => $employeeId, 'cid' => $companyId]);
        }
        if (array_key_exists('card_template_id', $body)) {
            $tpl = $cap($body['card_template_id'], 100);
            $db->update('employees', ['card_template_id' => ($tpl !== '' ? $tpl : null)],
                'id = :id AND company_id = :cid', ['id' => $employeeId, 'cid' => $companyId]);
        }

        // Brand colour lives in company_themes (company-scoped), same store the
        // web theme editor writes. NEVER trust the posted hex: run it through
        // ColorContrast::safeAccent() first (unparseable -> platform teal;
        // too-light -> darkened to a readable accent).
        $brandLocked = false;
        if (array_key_exists('primary_color', $body)) {
            require_once __DIR__ . '/_brand_guard.php';
            $canBrand = scanCanEditBrand(
                $db,
                (string) $ctx['account_id'],
                $employeeId
            );
            $brandLocked = !$canBrand;
            if ($canBrand) {
            $safe = ColorContrast::safeAccent((string) $body['primary_color']);
            $existingTheme = $db->fetchOne(
                "SELECT id FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]
            );
            if ($existingTheme) {
                $db->update('company_themes', ['primary_color' => $safe],
                    'company_id = :cid', ['cid' => $companyId]);
            } else {
                $db->insert('company_themes', [
                    'id'            => generateUUID(),
                    'company_id'    => $companyId,
                    'primary_color' => $safe,
                ]);
            }
            // Brand colour changed: every cached card PNG for the company is now
            // stale (same invalidation admin/theme.php runs).
            require_once INCLUDES_DIR . '/CardRenderer.php';
            try { CardRenderer::invalidateForCompany((string) $companyId, 'scan-my-card-theme'); }
            catch (\Throwable $e) { error_log('[scan/my-card] invalidate: ' . $e->getMessage()); }
            }
        }

        // The employee's own details just changed, so the baked card PNG is stale.
        //
        // Only the brand-colour branch above invalidated anything, and only for
        // the whole company. An employee on a classic web-designed card who
        // fixed their job title in the app saw the TEXT update everywhere while
        // the pictured card kept the old title, because CardRenderer served the
        // previously baked PNG until someone re-opened the web editor. Employees
        // with an app preset escaped it only because applyForEmployee re-renders
        // as a side effect.
        // ONLY when something will actually re-bake afterwards.
        //
        // invalidateForEmployee NULLs generated_cards.*_path. It does not delete
        // the PNG and it does not re-render. digital_card.php reads those columns
        // directly and gates the whole card block on `if ($frontImage)`, so a
        // NULLed row does not show a STALE card, it shows NO card. For a classic
        // Fabric-designed employee nothing can re-bake server-side, so invalidating
        // them turns a slightly-out-of-date image into a blank one, which is worse
        // than the problem it was meant to fix. Measured: only 7 of 45 active
        // template companies have has_vector_source, so 84% had no safety net.
        //
        // Employees on an app preset are already handled: CardPresets::
        // applyForEmployee re-bakes in this same request. Vector companies
        // self-heal via scripts/warm-card-previews.php on cron. Everyone else
        // keeps the image they have until the Fabric editor regenerates it.
        require_once INCLUDES_DIR . '/CardRenderer.php';
        try {
            $canRebake = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM templates
                 WHERE company_id = :cid
                   AND has_vector_source = 1
                   AND is_active = 1
                   AND deleted_at IS NULL
                 LIMIT 1",
                ['cid' => $companyId]
            );
            if (!empty($canRebake)) {
                CardRenderer::invalidateForEmployee((string) $employeeId, 'scan-my-card-details');
            }
        } catch (\Throwable $e) {
            error_log('[scan/my-card] invalidate employee: ' . $e->getMessage());
        }

        // Wallet pass auto-update: the card changed, so bump the pass version
        // and notify every Wallet device registered to it (empty push -> Wallet
        // pulls the new pass). Best-effort; never fails the save.
        try {
            require_once INCLUDES_DIR . '/ScanPassService.php';
            require_once INCLUDES_DIR . '/ApnsProvider.php';
            $regs = ScanPassService::onCardChanged((string) $employeeId);
            if ($regs) {
                // Empty-payload push per device; Wallet then pulls the new pass.
                apnsProvider()->pushPassUpdates(APPLE_WALLET_PASS_TYPE_ID, $regs);
            }
        } catch (\Throwable $e) { error_log('[scan/my-card] wallet push: ' . $e->getMessage()); }

        // Bake the app-chosen preset into the shared render cache so the public
        // cardify.om card, Apple Wallet pass and OG image all show the same design
        // (and follow any name/phone/colour edit). No-op unless a named preset.
        require_once INCLUDES_DIR . '/CardPresets.php';
        $freshEmp = $db->fetchOne('SELECT * FROM employees WHERE id = :id', ['id' => $employeeId]);
        if (is_array($freshEmp) && CardPresets::exists(trim((string)($freshEmp['card_template_id'] ?? '')))) {
            $freshTheme = $db->fetchOne('SELECT * FROM company_themes WHERE company_id = :cid LIMIT 1', ['cid' => $companyId]);
            try {
                CardPresets::applyForEmployee($company, is_array($freshTheme) ? $freshTheme : null,
                    $freshEmp, trim((string) $freshEmp['card_template_id']));
            } catch (Throwable $e) {
                error_log('my-card applyForEmployee: ' . $e->getMessage());
            }
        }

        $employee = $db->fetchOne(
            "SELECT * FROM employees WHERE id = :id AND company_id = :cid",
            ['id' => $employeeId, 'cid' => $companyId]
        );
        if (!$employee) {
            throw new RuntimeException('Updated employee could not be refetched');
        }
    }

    // ---- GET ----
    // Re-read after nothing; build the canonical parsed shape + design + urls.
    $card = CardifyConvention::employeeToScanCard($employee, $company);

    $theme = loadCompanyTheme($companyId);
    $logoUrl = null;
    if ($theme && !empty($theme['logo_path'])) {
        // logo_path is stored inconsistently (with/without the uploads/ prefix);
        // normalise to an absolute apex URL under /uploads/.
        $lp = ltrim((string) $theme['logo_path'], '/');
        if (strpos($lp, 'uploads/') !== 0) { $lp = 'uploads/' . $lp; }
        $logoUrl = 'https://' . cardifyApexHost() . '/' . $lp;
    }
    $card['design'] = [
        'card_template_id' => $employee['card_template_id'] ?? null,
        'primary_color'    => $theme['primary_color'] ?? null,
        'secondary_color'  => $theme['secondary_color'] ?? null,
        'logo_url'         => $logoUrl,
        'dark_mode'        => (int) ($employee['card_dark_mode_toggle'] ?? 1) === 1,
        // Lets the app grey out the colour/logo editors for a managed-tenant
        // employee instead of only failing on save.
        'can_edit_brand'   => scanCanEditBrand(
            $db,
            (string) $ctx['account_id'],
            $employeeId
        ),
    ];

    $slug = (string) ($company['slug'] ?? '');
    $card['public_url'] = CardifyConvention::employeeShareUrl($slug, $employee);
    $card['qr_url']     = 'https://' . cardifyApexHost() . '/qr.php?i=' . rawurlencode((string) $employee['id']);

    // Apple Wallet pass. wallet_apple.php serves the .pkpass; resolve the tenant
    // via ?c=<slug> so it works from the apex too. Null when the install has no
    // Apple Wallet certs configured (the endpoint would 503), so the app can hide
    // the button instead of offering a dead link.
    $card['wallet_pass_url'] = AppleWalletPass::isEnabled()
        ? getTenantUrl($slug, '/wallet_apple.php') . '?i=' . rawurlencode((string) $employee['id'])
            . '&c=' . rawurlencode($slug) . '&lang=en'
        : null;

    try {
        require_once INCLUDES_DIR . '/CardRenderer.php';
        $renderContext = CardRenderer::forEmployee((string) $employeeId);
        if (is_array($renderContext)) {
            $absoluteCardifyUrl = static function ($filePath, $candidate): ?string {
                $rootPath = realpath(BASE_DIR);
                $resolvedPath = is_string($filePath) && $filePath !== ''
                    ? realpath($filePath)
                    : false;
                if ($rootPath !== false && $resolvedPath !== false) {
                    $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
                    $resolvedPath = str_replace('\\', '/', $resolvedPath);
                    if (strpos($resolvedPath, $rootPath . '/') === 0) {
                        $relativePath = ltrim(substr($resolvedPath, strlen($rootPath)), '/');
                        $segments = array_map('rawurlencode', explode('/', $relativePath));
                        return 'https://' . cardifyApexHost() . '/' . implode('/', $segments);
                    }
                }

                $candidate = trim((string) $candidate);
                $parts = $candidate !== '' ? parse_url($candidate) : false;
                if (!is_array($parts)
                    || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                    || empty($parts['host'])
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || isset($parts['port'])
                    || isset($parts['fragment'])
                ) {
                    return null;
                }
                $host = strtolower(rtrim((string) $parts['host'], '.'));
                $apex = strtolower(rtrim(cardifyApexHost(), '.'));
                $suffix = '.' . $apex;
                if ($host !== $apex
                    && (strlen($host) <= strlen($suffix)
                        || substr($host, -strlen($suffix)) !== $suffix)
                ) {
                    return null;
                }
                return $candidate;
            };

            $frontUrl = $absoluteCardifyUrl(
                $renderContext['front_fs'] ?? null,
                $renderContext['front_url'] ?? null
            );
            $backUrl = $absoluteCardifyUrl(
                $renderContext['back_fs'] ?? null,
                $renderContext['back_url'] ?? null
            );
            $aspect = $renderContext['aspect_ratio'] ?? null;
            $signature = trim((string) ($renderContext['signature'] ?? ''));
            if ($frontUrl !== null
                && (is_int($aspect) || is_float($aspect))
                && is_finite((float) $aspect)
                && (float) $aspect > 0
                && (float) $aspect <= 10
                && $signature !== ''
                && strlen($signature) <= 256
            ) {
                $card['render'] = [
                    'front_url'    => $frontUrl,
                    'back_url'     => $backUrl,
                    'aspect_ratio' => (float) $aspect,
                    'signature'    => $signature,
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('[scan/my-card] render lookup: ' . $e->getMessage());
    }

    $response = ['success' => true, 'card' => $card];
    if ($method === 'POST') {
        $response['brand_locked'] = $brandLocked;
    }
    // json_encode returns FALSE on invalid UTF-8, and echoing that emits an
    // empty body with HTTP 200, which the app cannot parse and cannot recover
    // from. $cap() no longer produces such values, but a row corrupted before
    // that fix would still hit this. Fail loudly instead of silently.
    // Can this person actually use the web admin?
    //
    // Settings offers a "Manage on the web" row that lands on the tenant's
    // login.php -> tenant_login.php OTP flow, and that flow authenticates
    // strictly against the `users` table. Measured on live data: 382 of 399
    // active employees have no users row, so 96% of the people the button
    // targets cannot get in. Worse, the OTP page returns the same "code sent"
    // screen either way (deliberate anti-enumeration), so the tap LOOKS like it
    // worked and then nothing arrives.
    //
    // The app cannot know this on its own, so the server says. Same lesson as
    // the apex password wall: a link out is only done when the destination
    // accepts that population.
    $response['can_manage_web'] = false;
    try {
        // Mirror tenant_login.php's ACTUAL predicate rather than approximating
        // it. That file matches a user by email (line 61) OR by normalised phone
        // (line 68), and BOTH are scoped by company_id. Joining on email alone
        // was wrong twice over: it hid the row from anyone whose web login is
        // matched by phone, and it would have claimed access for a same-email
        // user belonging to a DIFFERENT company.
        $norm = "REPLACE(REPLACE(REPLACE(u.phone, '+', ''), ' ', ''), '-', '')";
        $webUser = $db->fetchOne(
            "SELECT u.id
             FROM employees e
             JOIN users u
               ON u.company_id = e.company_id
              AND (
                    (e.email <> '' AND LOWER(u.email) = LOWER(e.email))
                 OR (e.mobile <> '' AND {$norm} = REPLACE(REPLACE(REPLACE(e.mobile, '+', ''), ' ', ''), '-', ''))
                 OR (e.phone  <> '' AND {$norm} = REPLACE(REPLACE(REPLACE(e.phone,  '+', ''), ' ', ''), '-', ''))
              )
             WHERE e.id = :eid LIMIT 1",
            ['eid' => $employeeId]
        );
        $response['can_manage_web'] = !empty($webUser);
    } catch (\Throwable $e) {
        error_log('[scan/my-card] can_manage_web: ' . $e->getMessage());
    }

    $encoded = json_encode($response, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'card_encoding_failed',
            'detail'  => json_last_error_msg(),
        ]);
        exit;
    }
    echo $encoded;
    exit;

} catch (\Throwable $e) {
    error_log('[scan/my-card] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
