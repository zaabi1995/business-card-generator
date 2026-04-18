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

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Claim <?= esc($company['name_en']) ?> — Logo Library</title>
<meta name="robots" content="noindex,nofollow">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/includes/partials/nav.php'; ?>
<main class="max-w-2xl mx-auto px-4 py-10">
  <a href="/companies/<?= esc($company['slug']) ?>"
     class="text-sm text-gray-500 hover:underline">← Back to <?= esc($company['name_en']) ?></a>
  <h1 class="text-3xl font-bold mt-2">Claim <?= esc($company['name_en']) ?></h1>
  <p class="text-gray-600 mt-1">
    Verify you represent this company to unlock logo downloads and profile management.
  </p>

  <?php if ($success): ?>
    <div class="mt-6 p-4 bg-green-50 border border-green-300 rounded-lg">
      <?php if (!empty($success['auto_verified'])): ?>
        <h2 class="font-bold text-green-800">Verified instantly ✓</h2>
        <p class="text-sm mt-1">Your company domain matched. The logo is now verified and downloadable.</p>
        <a href="/companies/<?= esc($company['slug']) ?>"
           class="inline-block mt-3 px-4 py-2 bg-green-700 text-white rounded-lg">Back to profile</a>
        <p class="mt-3 text-xs text-gray-600">
          Next step: <a href="/pricing" class="underline">order business cards for your team</a>.
        </p>
      <?php else: ?>
        <h2 class="font-bold text-green-800">Claim submitted</h2>
        <p class="text-sm mt-1">Your claim is in our review queue. We'll respond within 48 hours.</p>
        <a href="/companies/<?= esc($company['slug']) ?>"
           class="inline-block mt-3 px-4 py-2 bg-green-700 text-white rounded-lg">Back to profile</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="mt-4 p-3 bg-red-50 border border-red-300 rounded text-sm text-red-800">
        <?= esc($error) ?>
      </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data"
          class="mt-6 space-y-4 bg-white border rounded-lg p-6">
      <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="company" value="<?= (int) $companyId ?>">

      <div>
        <label class="block text-sm font-medium">Proof type</label>
        <select name="proof_type" id="proof_type" class="mt-1 w-full border rounded px-3 py-2">
          <option value="domain_email">
            Company email (<?= esc($user['email']) ?>) — fastest, auto-verifies if domain matches
          </option>
          <option value="cr_document">Upload CR document (PDF/image)</option>
          <option value="domain_dns">Add DNS TXT record (instructions via email)</option>
          <option value="other">Other (describe in note)</option>
        </select>
      </div>

      <div id="proof_file_wrap" style="display:none">
        <label class="block text-sm font-medium">Proof file (PDF/JPG/PNG, ≤5MB)</label>
        <input type="file" name="proof_file" accept=".pdf,image/png,image/jpeg"
               class="mt-1 w-full border rounded px-3 py-2">
      </div>

      <div>
        <label class="block text-sm font-medium">Your role at the company</label>
        <select name="role_at_company" class="mt-1 w-full border rounded px-3 py-2">
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
        <label class="block text-sm font-medium">Note (optional)</label>
        <textarea name="note" rows="3" class="mt-1 w-full border rounded px-3 py-2"></textarea>
      </div>

      <button class="px-5 py-2.5 bg-cyan-600 text-white rounded-lg font-medium">Submit claim</button>
    </form>

    <script>
      const sel = document.getElementById('proof_type');
      const wrap = document.getElementById('proof_file_wrap');
      const sync = () => { wrap.style.display = sel.value === 'cr_document' ? 'block' : 'none'; };
      sel.addEventListener('change', sync); sync();
    </script>
  <?php endif; ?>
</main>
</body>
</html>
