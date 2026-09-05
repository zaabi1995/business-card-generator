<?php
/**
 * Claim form for a company's logo. Requires login.
 *
 * GET /logo-claim?company=NNN  → form
 * POST                         → submit
 *
 * Auto-verifies when proof_type=domain_email AND user's email domain
 * exactly matches om_companies.website_domain_cache AND only one
 * company has that domain. Otherwise queues for manual admin review.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';
require_once INCLUDES_DIR . '/LogoClaimService.php';

$db = Database::getInstance();
$companyId = (int) ($_GET['company'] ?? $_POST['company'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    die(t('logoclaim.missing_company'));
}

$company = $db->fetchOne(
    "SELECT * FROM om_companies WHERE id = :id",
    [':id' => $companyId]
);
if (!$company) {
    http_response_code(404);
    die(t('logoclaim.company_not_found'));
}

// Require login
$user = Auth::getCurrentUser();
if (!$user) {
    $_SESSION['redirect_after_login'] = "/logo-claim?company=$companyId";
    header('Location: /login.php?reason=logo_claim');
    exit;
}

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('logoclaim.invalid_csrf');
    } else {
        $proofType = $_POST['proof_type'] ?? 'domain_email';
        if (!in_array($proofType, ['domain_email', 'cr_document', 'domain_dns', 'other'], true)) {
            $error = t('logoclaim.invalid_proof');
        } else {
            $proofUrl = null;

            if ($proofType === 'cr_document') {
                if (empty($_FILES['proof_file']['tmp_name'])) {
                    $error = t('logoclaim.upload_cr');
                } else {
                    $mimeToExt = [
                        'application/pdf' => 'pdf',
                        'image/jpeg'      => 'jpg',
                        'image/png'       => 'png',
                    ];
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($_FILES['proof_file']['tmp_name']);
                    if (!isset($mimeToExt[$mime]) || $_FILES['proof_file']['size'] > 5 * 1024 * 1024) {
                        $error = t('logoclaim.bad_file');
                    } else {
                        $dir = __DIR__ . '/storage/logos/pending';
                        @mkdir($dir, 0755, true);
                        $ext = $mimeToExt[$mime];
                        $fn  = 'claim_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], "$dir/$fn")) {
                            $error = t('logoclaim.save_failed');
                        } else {
                            $proofUrl = "/storage/logos/pending/$fn";
                        }
                    }
                }
            }

            if (!$error) {
                $res = LogoClaimService::submitClaim(
                    $db,
                    $companyId,
                    $user['id'],
                    $user['email'],
                    $proofType,
                    $proofUrl,
                    trim($_POST['role_at_company'] ?? '') ?: null,
                    trim($_POST['note'] ?? '') ?: null
                );
                if ($res['ok']) {
                    $success = $res;
                } else {
                    $error = $res['error'];
                }
            }
        }
    }
}

$csrfToken = generateCSRFToken();

function claim_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$pageTitle       = t('logoclaim.page_title', ['name' => $company['name_en']]);
$pageDescription = t('logoclaim.page_description', ['name' => $company['name_en']]);
$canonicalUrl    = 'https://cardify.om/logo-claim?company=' . $companyId;
$bodyClass       = 'bg-white';
$showNavigation  = true;
$metaRobots      = 'noindex,nofollow';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <a href="/companies/<?= claim_esc($company['slug']) ?>" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-blue-600 mb-4">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            <?= claim_esc(t('logoclaim.back_to_company', ['name' => $company['name_en']])) ?>
        </a>

        <div class="mb-8">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-3">
                <?= claim_esc(t('logoclaim.badge')) ?>
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">
                <?= claim_esc(t('logoclaim.h1', ['name' => $company['name_en']])) ?>
            </h1>
            <p class="text-lg text-gray-600">
                <?= claim_esc(t('logoclaim.subtitle')) ?>
            </p>
        </div>

        <?php if ($success): ?>
            <?php if (!empty($success['auto_verified'])): ?>
                <div class="rounded-2xl p-6 lg:p-8 bg-gradient-to-br from-emerald-50 to-green-50 border border-emerald-200">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 shrink-0 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-xl">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-xl font-bold text-emerald-900"><?= claim_esc(t('logoclaim.auto_h2')) ?></h2>
                            <p class="text-emerald-800 mt-1"><?= claim_esc(t('logoclaim.auto_sub')) ?></p>
                            <div class="mt-5 flex flex-wrap gap-3">
                                <a href="/companies/<?= claim_esc($company['slug']) ?>"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold shadow shadow-emerald-700/30 transition">
                                    <?= claim_esc(t('logoclaim.auto_back_profile')) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                                <a href="/pricing"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-emerald-200 text-emerald-800 hover:border-emerald-400 text-sm font-semibold transition">
                                    <?= claim_esc(t('logoclaim.auto_order_cards')) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="rounded-2xl p-6 lg:p-8 bg-white border border-gray-200 shadow-sm">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 shrink-0 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-xl font-bold text-gray-900"><?= claim_esc(t('logoclaim.queued_h2')) ?></h2>
                            <p class="text-gray-600 mt-1"><?= claim_esc(t('logoclaim.queued_sub')) ?></p>
                            <a href="/companies/<?= claim_esc($company['slug']) ?>"
                               class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                                <?= claim_esc(t('logoclaim.queued_back')) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="mb-5 rounded-lg bg-rose-50 border border-rose-200 p-4 text-sm text-rose-900 flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 text-rose-600"></i>
                    <?= claim_esc($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="bg-white border border-gray-200 rounded-2xl p-6 lg:p-8 shadow-sm space-y-5">
                <input type="hidden" name="csrf_token" value="<?= claim_esc($csrfToken) ?>">
                <input type="hidden" name="company" value="<?= (int) $companyId ?>">

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5"><?= claim_esc(t('logoclaim.proof_type_label')) ?></label>
                    <select name="proof_type" id="proof_type"
                            class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <option value="domain_email"><?= claim_esc(t('logoclaim.proof_email_option', ['email' => $user['email']])) ?></option>
                        <option value="cr_document"><?= claim_esc(t('logoclaim.proof_cr_option')) ?></option>
                        <option value="domain_dns"><?= claim_esc(t('logoclaim.proof_dns_option')) ?></option>
                        <option value="other"><?= claim_esc(t('logoclaim.proof_other_option')) ?></option>
                    </select>
                </div>

                <div id="proof_file_wrap" style="display:none">
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5"><?= claim_esc(t('logoclaim.proof_file_label')) ?></label>
                    <input type="file" name="proof_file" accept=".pdf,image/png,image/jpeg"
                           class="block w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5"><?= claim_esc(t('logoclaim.role_label')) ?></label>
                    <select name="role_at_company"
                            class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <option value=""><?= claim_esc(t('logoclaim.role_placeholder')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_owner')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_ceo')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_marketing')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_admin')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_legal')) ?></option>
                        <option><?= claim_esc(t('logoclaim.role_other')) ?></option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5"><?= claim_esc(t('logoclaim.note_label')) ?></label>
                    <textarea name="note" rows="3"
                              class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-y"></textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-lg shadow-blue-600/30 hover:shadow-xl hover:shadow-blue-600/40 transition">
                        <?= claim_esc(t('logoclaim.submit')) ?> <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                    <a href="/companies/<?= claim_esc($company['slug']) ?>" class="text-sm text-gray-500 hover:text-blue-600"><?= claim_esc(t('logoclaim.cancel')) ?></a>
                </div>
            </form>

            <script<?= cspNonceAttr() ?>>
              (function () {
                var sel = document.getElementById('proof_type');
                var wrap = document.getElementById('proof_file_wrap');
                function sync() { wrap.style.display = sel.value === 'cr_document' ? 'block' : 'none'; }
                sel.addEventListener('change', sync); sync();
              })();
            </script>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
