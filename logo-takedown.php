<?php
/**
 * Public takedown form. No login required.
 *
 * GET  /logo-takedown?company=NNN  → form pre-scoped
 * GET  /logo-takedown              → generic form (company described in free-text)
 * POST                             → submit
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/LogoTakedownService.php';

$db = Database::getInstance();
$companyId = (int) ($_GET['company'] ?? $_POST['company'] ?? 0);
$company = $companyId
    ? $db->fetchOne("SELECT id, slug, name_en FROM om_companies WHERE id = :id", [':id' => $companyId])
    : null;

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token';
    } else {
        $hint = trim($_POST['company_hint'] ?? '');
        if (!$companyId && $hint === '') {
            $error = 'Please specify which company the takedown is for.';
        } else {
            $fields = [
                'name'         => trim($_POST['name'] ?? ''),
                'email'        => sanitizeEmail($_POST['email'] ?? ''),
                'role'         => trim($_POST['role'] ?? '') ?: null,
                'claim_basis'  => trim($_POST['claim_basis'] ?? ''),
                'related_urls' => trim($_POST['related_urls'] ?? '') ?: null,
                'company_hint' => $hint ?: null,
            ];
            if (!$fields['name'] || !$fields['email'] || !$fields['claim_basis']) {
                $error = 'Name, email, and basis are required';
            } else {
                $res = LogoTakedownService::submit($db, $companyId, $fields);
                if ($res['ok']) $success = $res; else $error = $res['error'];
            }
        }
    }
}

$csrfToken = generateCSRFToken();

function takedown_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$pageTitle       = 'Takedown request, Omani Logo Library';
$pageDescription = 'Brand owners and representatives can request removal of a logo from the Omani Logo Library.';
$canonicalUrl    = 'https://cardify.om/logo-takedown' . ($companyId ? '?company=' . $companyId : '');
$bodyClass       = 'bg-white';
$showNavigation  = true;
$metaRobots      = 'noindex,nofollow';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-6 flex-wrap">
            <a href="/logos" class="hover:text-blue-600">Logo Library</a>
            <i class="fa-solid fa-chevron-right text-[10px] text-gray-300"></i>
            <span class="text-gray-900 font-medium">Takedown</span>
        </nav>

        <div class="mb-8">
            <span class="inline-block px-3 py-1 rounded-full bg-rose-100 text-rose-700 text-xs font-semibold uppercase tracking-wide mb-3">
                Trademark · Takedown
            </span>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">Logo takedown request</h1>
            <p class="text-lg text-gray-600">
                Brand owners and representatives can request removal. We acknowledge within 48 hours and hide within 24 hours of prima-facie valid claims.
            </p>
        </div>

        <?php if ($success): ?>
            <div class="rounded-2xl p-6 lg:p-8 bg-gradient-to-br from-emerald-50 to-green-50 border border-emerald-200">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 shrink-0 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-xl">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-xl font-bold text-emerald-900">Request received (ID #<?= (int) $success['takedown_id'] ?>)</h2>
                        <p class="text-emerald-800 mt-1">Our team reviews within 48 hours. You'll receive a follow-up at the email you provided.</p>
                        <a href="/logos" class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold transition">
                            Back to the library <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="mb-5 rounded-lg bg-rose-50 border border-rose-200 p-4 text-sm text-rose-900 flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 text-rose-600"></i>
                    <?= takedown_esc($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="bg-white border border-gray-200 rounded-2xl p-6 lg:p-8 shadow-sm space-y-5">
                <input type="hidden" name="csrf_token" value="<?= takedown_esc($csrfToken) ?>">

                <?php if ($company): ?>
                    <input type="hidden" name="company" value="<?= (int) $company['id'] ?>">
                    <div class="rounded-lg bg-blue-50 border border-blue-200 p-3 text-sm text-blue-900">
                        <i class="fa-solid fa-bullseye mr-1.5 text-blue-600"></i>
                        Subject: <strong><?= takedown_esc($company['name_en']) ?></strong>
                    </div>
                <?php else: ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-1.5">Company name or URL slug *</label>
                        <input type="text" name="company_hint" required
                               class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <p class="text-xs text-gray-500 mt-1.5">We'll match to the indexed record during review.</p>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-1.5">Your name *</label>
                        <input type="text" name="name" required
                               class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-1.5">Email *</label>
                        <input type="email" name="email" required
                               class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Your role <span class="font-normal text-gray-500">(legal counsel, CEO, brand manager, etc.)</span></label>
                    <input type="text" name="role"
                           class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Basis of claim *</label>
                    <textarea name="claim_basis" rows="4" required
                              class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-y"
                              placeholder="Describe your relationship to the brand and why the logo should be removed."></textarea>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-900 mb-1.5">Related URLs</label>
                    <textarea name="related_urls" rows="2"
                              class="w-full px-3 py-2.5 text-sm bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-y"
                              placeholder="Company website, trademark registration page, etc."></textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold shadow-lg shadow-rose-600/30 hover:shadow-xl hover:shadow-rose-600/40 transition">
                        Submit takedown request <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                    <a href="/logos" class="text-sm text-gray-500 hover:text-blue-600">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
