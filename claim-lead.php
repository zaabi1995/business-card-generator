<?php
/**
 * /claim-lead.php?token={magic}
 *
 * Lead-side of the bulk-claim flow. The cold lead taps the WhatsApp
 * magic-link, this page shows a preview of the pre-built card and a
 * single "Claim & edit" button.
 *
 * Flow:
 *   GET  — validates token (not expired, not already used), logs
 *          opened_at on first visit, renders preview + claim CTA.
 *   POST — claims the card: sets employees.status='active', stamps
 *          token_used_at + claimed_at, logs the user in as the
 *          employee (session), redirects to the card editor.
 *
 * Distinct from /claim.php (viral-footer lead capture) — do not merge.
 */

ob_start();
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/Auth.php';
    require_once INCLUDES_DIR . '/functions.php';
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo 'Error loading /claim-lead.';
    error_log('claim-lead bootstrap: ' . $e->getMessage());
    exit;
}

$db = Database::getInstance();

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
// Token is 32 hex chars (16 bytes) — old generation used 16 bytes, so 32 hex.
// Also accept 64 hex (32 bytes) for forward-compat with longer tokens.
if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(400);
    renderClaimError('Invalid claim link.', 'Please check the link you received on WhatsApp — it may have been copied incompletely.');
    exit;
}

$lead = $db->fetchOne(
    "SELECT * FROM bulk_claim_leads WHERE magic_token = :t LIMIT 1",
    ['t' => $token]
);
if (!$lead) {
    http_response_code(404);
    renderClaimError('Link not found.', 'This claim link does not exist. Please WhatsApp us if you think this is a mistake.');
    exit;
}

// Expired?
if (!empty($lead['expires_at']) && strtotime($lead['expires_at']) < time()) {
    http_response_code(410);
    renderClaimError('This link has expired.', 'Your magic link is more than 14 days old. Reply to the WhatsApp message and we will send a fresh one.');
    exit;
}

// Already used?
if (!empty($lead['token_used_at']) || !empty($lead['claimed_at'])) {
    // Gracefully send them to the card they already claimed
    if (!empty($lead['card_url'])) {
        header('Location: ' . $lead['card_url']);
        exit;
    }
    renderClaimError('Already claimed.', 'This card has already been claimed.');
    exit;
}

$employee = !empty($lead['employee_id']) ? findEmployeeById($lead['employee_id']) : null;
if (!$employee) {
    http_response_code(410);
    renderClaimError('Card not available.', 'The pre-built card for this link is no longer available. WhatsApp us and we will set you up manually.');
    exit;
}
$company = findCompanyById($employee['company_id']);

// Stamp opened_at on first visit (idempotent, no overwrite).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($lead['opened_at'])) {
    try {
        $db->update('bulk_claim_leads', ['opened_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $lead['id']]);
        // Bump batch.opened counter
        $db->getConnection()->prepare(
            "UPDATE bulk_claim_batches SET opened = opened + 1 WHERE id = :b"
        )->execute([':b' => $lead['batch_id']]);
    } catch (Throwable $e) { /* non-fatal */ }
}

// ---------------- CLAIM (POST) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        renderClaimError('Invalid request.', 'Please open the WhatsApp link again.');
        exit;
    }

    // Atomically claim (one-time-use guard).
    $stmt = $db->getConnection()->prepare(
        "UPDATE bulk_claim_leads
            SET token_used_at = NOW(), claimed_at = COALESCE(claimed_at, NOW())
          WHERE id = :id
            AND token_used_at IS NULL"
    );
    $stmt->execute([':id' => $lead['id']]);
    $claimed = $stmt->rowCount() > 0;

    if (!$claimed) {
        header('Location: ' . ($lead['card_url'] ?: '/'));
        exit;
    }

    // Promote the employee out of 'unclaimed'
    $db->update('employees', [
        'status' => 'active',
    ], 'id = :id', ['id' => $employee['id']]);

    // Bump batch counters
    try {
        $db->getConnection()->prepare(
            "UPDATE bulk_claim_batches SET claimed = claimed + 1 WHERE id = :b"
        )->execute([':b' => $lead['batch_id']]);
    } catch (Throwable $e) { /* non-fatal */ }

    // Log a card_event so growth analytics sees the conversion
    try {
        if (class_exists('CardAnalytics')) {
            require_once INCLUDES_DIR . '/CardAnalytics.php';
            CardAnalytics::log(
                $employee['id'],
                $employee['company_id'],
                'bulk_claim_claimed',
                '/claim-lead'
            );
        }
    } catch (Throwable $e) { /* non-fatal, event_type may not be in ENUM yet */ }

    // Bounce them to their public card — they can edit from there
    // (OTP / password setup is intentionally deferred to a future pass).
    $target = !empty($lead['card_url']) ? $lead['card_url'] : '/';
    header('Location: ' . $target . '?claimed=1');
    exit;
}

// ---------------- GET (preview) ----------------

$leadName    = $lead['name'] ?? trim(($employee['name_en'] ?? '') ?: '');
$leadTitle   = $lead['title'] ?? ($employee['position_en'] ?? '');
$leadCompany = $lead['company_name'] ?? ($employee['company_en'] ?? ($company['name'] ?? ''));
$leadPhone   = $lead['phone'] ?? '';

$csrf = generateCSRFToken();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Claim your card — Cardify</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.ico">
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f5f7fb; }
        .card-preview { box-shadow: 0 25px 50px -12px rgba(0, 155, 193, 0.35); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full">

        <div class="text-center mb-6">
            <div class="inline-flex items-center gap-2 text-sm font-medium text-purple-600 bg-white border border-purple-200 px-3 py-1 rounded-full shadow-sm">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>We made this for you</span>
            </div>
        </div>

        <!-- Card preview -->
        <div class="card-preview bg-gradient-to-br from-[#009bc1] to-[#824598] rounded-2xl p-6 text-white mb-6">
            <div class="flex items-start justify-between mb-8">
                <div>
                    <div class="text-xs uppercase tracking-widest opacity-70">Digital business card</div>
                    <div class="text-2xl font-bold mt-1 leading-tight"><?= htmlspecialchars($leadName, ENT_QUOTES) ?></div>
                </div>
                <div class="bg-white/15 backdrop-blur rounded-lg w-10 h-10 flex items-center justify-center">
                    <i class="fa-solid fa-id-card"></i>
                </div>
            </div>

            <?php if ($leadTitle !== ''): ?>
                <div class="text-sm font-medium opacity-95"><?= htmlspecialchars($leadTitle, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($leadCompany !== ''): ?>
                <div class="text-sm opacity-80"><?= htmlspecialchars($leadCompany, ENT_QUOTES) ?></div>
            <?php endif; ?>

            <div class="mt-6 pt-4 border-t border-white/20 text-xs opacity-80">
                <?php if ($leadPhone !== ''): ?>
                    <div><i class="fa-solid fa-phone mr-2 opacity-60"></i>+<?= htmlspecialchars($leadPhone, ENT_QUOTES) ?></div>
                <?php endif; ?>
                <div class="mt-1"><i class="fa-solid fa-sparkles mr-2 opacity-60"></i>Add photo, logo, QR, social links after you claim</div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h1 class="text-xl font-bold text-gray-900">Hi <?= htmlspecialchars(strtok($leadName, ' ') ?: 'there', ENT_QUOTES) ?> 👋</h1>
            <p class="text-sm text-gray-600 mt-2 leading-relaxed">
                We've already built the starter version of your digital business card. Claim it in one click, then polish it — add your photo, logo, links, and share.
            </p>

            <form method="POST" class="mt-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                <button type="submit" class="w-full bg-gradient-to-r from-[#009bc1] to-[#824598] text-white font-semibold py-3 rounded-xl shadow hover:shadow-lg transition">
                    <i class="fa-solid fa-check mr-2"></i>Claim my card
                </button>
            </form>

            <p class="mt-3 text-xs text-gray-400 text-center">
                This magic link is one-time use<?= !empty($lead['expires_at']) ? ' and expires on ' . htmlspecialchars(date('M j, Y', strtotime($lead['expires_at'])), ENT_QUOTES) : '' ?>.
            </p>
        </div>

        <div class="mt-6 text-center text-xs text-gray-400">
            Made with <a href="/" class="text-[#009bc1] font-medium">Cardify</a> &middot; BHD Printing &amp; Designing
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/js/all.min.js" defer></script>
</body>
</html>
<?php

/**
 * Minimal inline error renderer — no dependency on admin-layout or ui-header
 * so the page is resilient even if the includes tree shifts.
 */
function renderClaimError($title, $detail) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> — Cardify</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f5f7fb;}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
        <div class="w-12 h-12 mx-auto bg-amber-50 rounded-full flex items-center justify-center mb-4">
            <span class="text-amber-500 text-2xl">!</span>
        </div>
        <h1 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars($detail, ENT_QUOTES) ?></p>
        <a href="/" class="inline-block mt-5 text-sm font-medium text-purple-600 hover:underline">Go to Cardify</a>
    </div>
</body>
</html><?php
}
