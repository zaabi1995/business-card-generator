<?php
/**
 * GET|POST /api/scan/my-card.php, the logged-in employee's OWN digital card.
 *
 * Bearer-authenticated (ScanAuth). The Cardify Scan mobile app uses this so an
 * employee can read and edit their own Cardify card (contact fields + brand
 * design) and grab the Apple Wallet pass URL, without the web admin.
 *
 * GET  -> {success, card:{ ...canonical parsed shape, design:{...},
 *          public_url, qr_url, wallet_pass_url }}
 * POST -> {success} after a partial update of the editable fields + design.
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

$ctx = ScanAuth::requireEmployee();
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
        $cap = function ($v, int $max = 255): string {
            return substr(trim((string) $v), 0, $max);
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
            $canBrand = scanCanEditBrand($db, $employeeId);
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

        echo json_encode(['success' => true, 'brand_locked' => $brandLocked]);
        exit;
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
        'can_edit_brand'   => scanCanEditBrand($db, $employeeId),
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

    echo json_encode(['success' => true, 'card' => $card], JSON_UNESCAPED_UNICODE);
    exit;

} catch (\Throwable $e) {
    error_log('[scan/my-card] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
