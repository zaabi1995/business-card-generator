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
    die('Missing company');
}

$company = $db->fetchOne(
    "SELECT * FROM om_companies WHERE id = :id",
    [':id' => $companyId]
);
if (!$company) {
    http_response_code(404);
    die('Company not found');
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
        $error = 'Invalid CSRF token';
    } else {
        $proofType = $_POST['proof_type'] ?? 'domain_email';
        if (!in_array($proofType, ['domain_email', 'cr_document', 'domain_dns', 'other'], true)) {
            $error = 'Invalid proof type';
        } else {
            $proofUrl = null;

            if ($proofType === 'cr_document') {
                if (empty($_FILES['proof_file']['tmp_name'])) {
                    $error = 'Please upload your CR document (PDF/JPG/PNG, ≤5MB)';
                } else {
                    $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
                    $mime = mime_content_type($_FILES['proof_file']['tmp_name']);
                    if (!in_array($mime, $allowed, true) || $_FILES['proof_file']['size'] > 5 * 1024 * 1024) {
                        $error = 'Proof file must be PDF/JPG/PNG under 5MB';
                    } else {
                        $dir = __DIR__ . '/storage/logos/pending';
                        @mkdir($dir, 0755, true);
                        $ext = pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION);
                        $fn = 'claim_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
                        if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], "$dir/$fn")) {
                            $error = 'Could not save proof file. Check back later.';
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

$pageTitle       = 'Claim ' . $company['name_en'] . ' — Logo Library';
$pageDescription = 'Verify ownership of ' . $company['name_en'] . ' to unlock logo downloads.';
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
            Back to <?= claim_esc($company['name_en']) ?>
        </a>

        <div class="mb-8">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-3">
                Claim · Verify ownership
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">
                Claim <?= claim_esc($company['name_en']) ?>
            </h1>
            <p class="text-lg text-gray-600">
                Verify you represent this company to unlock logo downloads and profile management.
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
                            <h2 class="text-xl font-bold text-emerald-900">Verified instantly</h2>
                            <p class="text-emerald-800 mt-1">Your company domain matched. The logo is now verified and downloadable.</p>
                            <div class="mt-5 flex flex-wrap gap-3">
                                <a href="/companies/<?= claim_esc($company['slug']) ?>"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold shadow shadow-emerald-700/30 transition">
                                    Back to profile <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                                <a href="/pricing"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-emerald-200 text-emerald-800 hover:border-emerald-400 text-sm font-semibold transition">
                                    Order business cards for your team
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
                            <h2 class="text-xl font-bold text-gray-900">Claim submitted</h2>
                            <p class="text-gray-600 mt-1">Your claim is in our review queue. We'll respond within 48 hours.</p>
                            <a href="/companies/<?= claim_esc($company['slug']) ?>"
                               class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow shadow-blue-600/20 transition">
                                Back to profile <i class="fa-solid fa-arrow-right text-xs"></i>
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
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Proof type</label>
                    <select name="proof_type" id="proof_type"
                            class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <option value="domain_email">Company email (<?= claim_esc($user['email']) ?>) — fastest, auto-verifies if domain matches</option>
                        <option value="cr_document">Upload CR document (PDF/image)</option>
                        <option value="domain_dns">Add DNS TXT record (instructions via email)</option>
                        <option value="other">Other (describe in note)</option>
                    </select>
                </div>

                <div id="proof_file_wrap" style="display:none">
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Proof file (PDF/JPG/PNG, ≤5MB)</label>
                    <input type="file" name="proof_file" accept=".pdf,image/png,image/jpeg"
                           class="block w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Your role at the company</label>
                    <select name="role_at_company"
                            class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <option value="">—</option>
                        <option>Owner</option>
                        <option>CEO / Managing Director</option>
                        <option>Marketing</option>
                        <option>Admin</option>
                        <option>Legal</option>
                        <option>Other</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Note (optional)</label>
                    <textarea name="note" rows="3"
                              class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-y"></textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-lg shadow-blue-600/30 hover:shadow-xl hover:shadow-blue-600/40 transition">
                        Submit claim <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                    <a href="/companies/<?= claim_esc($company['slug']) ?>" class="text-sm text-gray-500 hover:text-blue-600">Cancel</a>
                </div>
            </form>

            <script>
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
