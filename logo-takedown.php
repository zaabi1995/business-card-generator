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

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Takedown — Omani Logo Library</title>
<meta name="robots" content="noindex,nofollow">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/includes/partials/nav.php'; ?>
<main class="max-w-2xl mx-auto px-4 py-10">
  <h1 class="text-3xl font-bold">Logo takedown request</h1>
  <p class="text-gray-600 mt-1">
    Brand owners and representatives can request removal. We acknowledge within 48 hours and
    hide within 24 hours of prima-facie valid claims.
  </p>

  <?php if ($success): ?>
    <div class="mt-6 p-4 bg-green-50 border border-green-300 rounded-lg">
      <h2 class="font-bold text-green-800">Request received (ID #<?= (int) $success['takedown_id'] ?>)</h2>
      <p class="text-sm mt-1">Our team reviews within 48 hours.</p>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="mt-4 p-3 bg-red-50 border border-red-300 rounded text-sm text-red-800">
        <?= esc($error) ?>
      </div>
    <?php endif; ?>
    <form method="post" class="mt-6 space-y-4 bg-white border rounded-lg p-6">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <?php if ($company): ?>
        <input type="hidden" name="company" value="<?= (int) $company['id'] ?>">
        <p class="text-sm">Subject: <strong><?= esc($company['name_en']) ?></strong></p>
      <?php else: ?>
        <div>
          <label class="block text-sm font-medium">Company name or URL slug *</label>
          <input type="text" name="company_hint" class="mt-1 w-full border rounded px-3 py-2" required>
          <p class="text-xs text-gray-500 mt-1">We'll match to the indexed record during review.</p>
        </div>
      <?php endif; ?>
      <div>
        <label class="block text-sm font-medium">Your name *</label>
        <input type="text" name="name" required class="mt-1 w-full border rounded px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-medium">Email *</label>
        <input type="email" name="email" required class="mt-1 w-full border rounded px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-medium">Your role (legal counsel, CEO, etc.)</label>
        <input type="text" name="role" class="mt-1 w-full border rounded px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-medium">Basis of claim *</label>
        <textarea name="claim_basis" rows="4" required class="mt-1 w-full border rounded px-3 py-2"
          placeholder="Describe your relationship to the brand and why the logo should be removed."></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium">Related URLs</label>
        <textarea name="related_urls" rows="2" class="mt-1 w-full border rounded px-3 py-2"
          placeholder="Company website, trademark registration page, etc."></textarea>
      </div>
      <button class="px-5 py-2.5 bg-red-600 text-white rounded-lg font-medium">
        Submit takedown request
      </button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
