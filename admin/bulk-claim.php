<?php
/**
 * Bulk Claim — Super-admin growth tool
 *
 * Paste a CSV of contacted-but-cold leads (Ali's 70+ from the Cardify
 * expansion campaign) → preview → one-click creates preliminary card
 * records + dispatches a WhatsApp magic-link for each.
 *
 * Flow:
 *   1. GET  /admin/bulk-claim.php           — upload/paste CSV
 *   2. POST action=preview                  — renders dedup preview table
 *   3. POST action=send                     — super-admin confirms; creates
 *                                             employees + leads + dispatches
 *                                             WhatsApp, rate-limited to
 *                                             BULK_CLAIM_MAX_PER_BATCH rows
 *
 * Super admin only. CSRF-protected.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

// -------------------------------------------------------------------
// Tunables
// -------------------------------------------------------------------
const BULK_CLAIM_MAX_PER_BATCH   = 100;
const BULK_CLAIM_TOKEN_TTL_DAYS  = 14;
const BULK_CLAIM_WA_SLEEP_USEC   = 2000000; // 2s between sends
const BULK_CLAIM_DEFAULT_COMPANY = '8e69b990-4e7f-405b-a98a-816a06e3f97c'; // BHD Oman

// -------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------

/**
 * Parse CSV text into a list of associative rows.
 * Accepts: name, phone, company_name, title, email (any case, any order).
 */
function parseBulkClaimCsv($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];

    // Normalise line endings
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $rows = [];
    $header = null;
    while (($line = fgetcsv($fh)) !== false) {
        // Skip blank rows
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;
        if ($header === null) {
            $header = array_map(function ($h) {
                return strtolower(trim((string)$h));
            }, $line);
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = isset($line[$i]) ? trim((string)$line[$i]) : '';
        }
        // Alias "company" → "company_name", "job_title" → "title"
        if (!isset($row['company_name']) && isset($row['company'])) {
            $row['company_name'] = $row['company'];
        }
        if (!isset($row['title']) && isset($row['job_title'])) {
            $row['title'] = $row['job_title'];
        }
        if (empty($row['name']) && empty($row['phone'])) continue;
        $rows[] = [
            'name'         => $row['name']         ?? '',
            'phone'        => $row['phone']        ?? '',
            'company_name' => $row['company_name'] ?? '',
            'title'        => $row['title']        ?? '',
            'email'        => $row['email']        ?? '',
        ];
    }
    fclose($fh);
    return $rows;
}

/**
 * Validate + enrich a parsed row for previewing. Returns
 *  ['row' => ..., 'normalized_phone' => '968...', 'reason' => null|'...']
 */
function analyzeBulkClaimRow($row, $db) {
    $name  = trim((string)$row['name']);
    $phone = WhatsApp::normalizePhone($row['phone'] ?? '');

    if ($name === '')            return ['row' => $row, 'normalized_phone' => $phone, 'reason' => 'missing_name'];
    if (strlen($phone) < 8)      return ['row' => $row, 'normalized_phone' => $phone, 'reason' => 'invalid_phone'];

    // Duplicate already-contacted lead
    $dupLead = $db->fetchOne(
        "SELECT id FROM bulk_claim_leads WHERE phone = :p LIMIT 1",
        ['p' => $phone]
    );
    if ($dupLead) return ['row' => $row, 'normalized_phone' => $phone, 'reason' => 'already_in_leads'];

    // Already has an employee card (by phone or mobile)
    $dupEmp = $db->fetchOne(
        "SELECT id FROM employees WHERE (phone = :p OR mobile = :p) LIMIT 1",
        ['p' => $phone]
    );
    if ($dupEmp) return ['row' => $row, 'normalized_phone' => $phone, 'reason' => 'already_has_card'];

    return ['row' => $row, 'normalized_phone' => $phone, 'reason' => null];
}

/**
 * Build the public magic-link claim URL for a token.
 */
function bulkClaimMagicUrl($token) {
    return 'https://cardify.om/claim-lead.php?token=' . urlencode($token);
}

/**
 * Build the WhatsApp message for a lead.
 */
function bulkClaimMessageFor($leadName, $token, $ttlDays) {
    $firstName = trim(strtok(trim($leadName), ' ')) ?: 'there';
    $url = bulkClaimMagicUrl($token);
    return "Hi {$firstName} \u{1F44B}\n\n"
         . "We prepared a free digital business card for you — all your contact info + QR code, sharable via link.\n\n"
         . "Claim it in 60 seconds (expires in {$ttlDays} days):\n"
         . $url . "\n\n"
         . "Need help? Reply to this message.\n\n"
         . "— BHD Printing & Designing";
}

// -------------------------------------------------------------------
// POST handlers
// -------------------------------------------------------------------
$action       = $_POST['action'] ?? $_GET['action'] ?? '';
$message      = null;
$messageType  = 'success';
$previewRows  = [];
$previewCsv   = '';
$previewLabel = '';
$previewCompanyId = BULK_CLAIM_DEFAULT_COMPANY;
$sendReport   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    // ---------------- PREVIEW ----------------
    if ($action === 'preview') {
        $csvText = (string)($_POST['csv_text'] ?? '');
        if (isset($_FILES['csv_file']) && !empty($_FILES['csv_file']['tmp_name'])) {
            $uploaded = @file_get_contents($_FILES['csv_file']['tmp_name']);
            if ($uploaded !== false && $uploaded !== '') {
                $csvText = $uploaded;
            }
        }
        $previewCsv       = $csvText;
        $previewLabel     = substr(trim((string)($_POST['admin_label'] ?? '')), 0, 255);
        $previewCompanyId = trim((string)($_POST['company_id'] ?? BULK_CLAIM_DEFAULT_COMPANY));

        $parsed = parseBulkClaimCsv($csvText);
        if (empty($parsed)) {
            $message     = 'Could not parse any rows. Expected header row with: name, phone, company_name, title, email.';
            $messageType = 'error';
        } elseif (count($parsed) > BULK_CLAIM_MAX_PER_BATCH) {
            $message     = 'Batch too large (' . count($parsed) . ' rows). Max ' . BULK_CLAIM_MAX_PER_BATCH . ' per batch — split the CSV.';
            $messageType = 'error';
        } else {
            foreach ($parsed as $r) {
                $previewRows[] = analyzeBulkClaimRow($r, $db);
            }
        }
    }

    // ---------------- SEND -------------------
    if ($action === 'send') {
        $csvText     = (string)($_POST['csv_text'] ?? '');
        $adminLabel  = substr(trim((string)($_POST['admin_label'] ?? '')), 0, 255);
        $companyId   = trim((string)($_POST['company_id'] ?? BULK_CLAIM_DEFAULT_COMPANY));

        $parsed = parseBulkClaimCsv($csvText);
        if (empty($parsed) || count($parsed) > BULK_CLAIM_MAX_PER_BATCH) {
            $message     = 'Invalid or oversized batch. Re-upload CSV and preview first.';
            $messageType = 'error';
        } else {
            // Verify company exists
            $company = findCompanyById($companyId);
            if (!$company) {
                $message     = 'Selected company not found.';
                $messageType = 'error';
            } else {
                $companySlug = $company['slug'];
                $currentUser = Auth::getCurrentUser();
                $adminUserId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 'admin');

                // Create batch record
                $batchId   = generateUUID();
                $nowExpiry = date('Y-m-d H:i:s', time() + BULK_CLAIM_TOKEN_TTL_DAYS * 86400);
                $db->insert('bulk_claim_batches', [
                    'id'            => $batchId,
                    'admin_user_id' => $adminUserId,
                    'admin_label'   => $adminLabel ?: ('Batch ' . date('Y-m-d H:i')),
                    'company_id'    => $companyId,
                    'total'         => count($parsed),
                    'status'        => 'sending',
                    'csv_snapshot'  => substr($csvText, 0, 1000000),
                    'sent_at'       => date('Y-m-d H:i:s'),
                ]);

                $createdN = 0; $sentN = 0; $failedN = 0; $skippedN = 0;
                $sendReport = ['rows' => [], 'batch_id' => $batchId];

                foreach ($parsed as $r) {
                    $analysis = analyzeBulkClaimRow($r, $db);
                    $phone    = $analysis['normalized_phone'];

                    if ($analysis['reason'] !== null) {
                        $skippedN++;
                        $sendReport['rows'][] = [
                            'name'   => $r['name'],
                            'phone'  => $phone,
                            'status' => 'skipped',
                            'reason' => $analysis['reason'],
                        ];
                        continue;
                    }

                    // --- 1. Preliminary employee record ---
                    $employeeId = generateUUID();
                    try {
                        $db->insert('employees', [
                            'id'                     => $employeeId,
                            'company_id'             => $companyId,
                            'email'                  => $r['email'] !== ''
                                ? $r['email']
                                : ('unclaimed-' . substr($employeeId, 0, 8) . '@claim.cardify.om'),
                            'name_en'                => $r['name'],
                            'position_en'            => $r['title'] ?: null,
                            'company_en'             => $r['company_name'] ?: null,
                            'phone'                  => $phone,
                            'mobile'                 => $phone,
                            'status'                 => 'unclaimed',
                            'created_via_bulk_claim' => 1,
                        ]);
                        $createdN++;
                    } catch (Throwable $ie) {
                        error_log('[bulk-claim] employee insert failed: ' . $ie->getMessage());
                        $failedN++;
                        $sendReport['rows'][] = [
                            'name'   => $r['name'],
                            'phone'  => $phone,
                            'status' => 'failed',
                            'reason' => 'employee_insert_failed',
                        ];
                        continue;
                    }

                    // --- 2. Lead row with magic token ---
                    // 32 bytes → 64 hex chars (spec)
                    $token = bin2hex(random_bytes(32));
                    // Codex r3 #5: also store a SHA-256 so /claim-lead.php can
                    // look up by hash; the plaintext column is the legacy path
                    // we'll drop once all outstanding links expire.
                    $tokenHash = hash('sha256', $token);
                    $cardUrl = 'https://cardify.om/' . $companySlug . '/card/' . $employeeId;

                    try {
                        $db->insert('bulk_claim_leads', [
                            'id'              => generateUUID(),
                            'batch_id'        => $batchId,
                            'name'            => $r['name'],
                            'phone'           => $phone,
                            'company_name'    => $r['company_name'] ?: null,
                            'title'           => $r['title'] ?: null,
                            'email'           => $r['email'] ?: null,
                            'magic_token'     => $token,
                            'magic_token_hash' => $tokenHash,
                            'employee_id'     => $employeeId,
                            'card_url'        => $cardUrl,
                            'wa_status'       => 'pending',
                            'expires_at'      => $nowExpiry,
                        ]);
                    } catch (Throwable $le) {
                        error_log('[bulk-claim] lead insert failed: ' . $le->getMessage());
                        $failedN++;
                        $sendReport['rows'][] = [
                            'name'   => $r['name'],
                            'phone'  => $phone,
                            'status' => 'failed',
                            'reason' => 'lead_insert_failed',
                        ];
                        continue;
                    }

                    // --- 3. Dispatch WhatsApp ---
                    $msg = bulkClaimMessageFor($r['name'], $token, BULK_CLAIM_TOKEN_TTL_DAYS);
                    $res = WhatsApp::sendMessage($phone, $msg);

                    if (!empty($res['success'])) {
                        $sentN++;
                        $db->update('bulk_claim_leads', [
                            'wa_status'     => 'sent',
                            'wa_message_id' => $res['messageId'] ?? null,
                            'wa_sent_at'    => date('Y-m-d H:i:s'),
                        ], 'magic_token = :t', ['t' => $token]);
                        $sendReport['rows'][] = [
                            'name'   => $r['name'],
                            'phone'  => $phone,
                            'status' => 'sent',
                        ];
                    } else {
                        $failedN++;
                        $db->update('bulk_claim_leads', [
                            'wa_status' => 'failed',
                            'wa_error'  => substr((string)($res['error'] ?? 'unknown'), 0, 500),
                        ], 'magic_token = :t', ['t' => $token]);
                        $sendReport['rows'][] = [
                            'name'   => $r['name'],
                            'phone'  => $phone,
                            'status' => 'failed',
                            'reason' => $res['error'] ?? 'wa_send_failed',
                        ];
                    }

                    // Rate limit — don't hammer Dardasha
                    usleep(BULK_CLAIM_WA_SLEEP_USEC);
                }

                // Update batch counters
                $db->update('bulk_claim_batches', [
                    'created' => $createdN,
                    'sent'    => $sentN,
                    'failed'  => $failedN,
                    'status'  => $failedN > 0 && $sentN === 0 ? 'failed' : 'sent',
                ], 'id = :id', ['id' => $batchId]);

                // Audit
                if (class_exists('AuditLog')) {
                    try {
                        AuditLog::log(
                            'bulk_claim_batch_sent',
                            'bulk_claim_batch',
                            $batchId,
                            "Bulk claim batch: total=" . count($parsed) . ", created={$createdN}, sent={$sentN}, failed={$failedN}"
                        );
                    } catch (Throwable $ae) { /* non-fatal */ }
                }

                $message     = "Batch dispatched — created {$createdN}, sent {$sentN} WhatsApps, failed {$failedN}, skipped {$skippedN}.";
                $messageType = 'success';
            }
        }
    }
}

// -------------------------------------------------------------------
// Fetch recent batches for the dashboard
// -------------------------------------------------------------------
$recentBatches = $db->fetchAll(
    "SELECT b.*,
            (SELECT COUNT(*) FROM bulk_claim_leads l WHERE l.batch_id = b.id AND l.opened_at IS NOT NULL) AS opened,
            (SELECT COUNT(*) FROM bulk_claim_leads l WHERE l.batch_id = b.id AND l.claimed_at IS NOT NULL) AS claimed,
            (SELECT COUNT(*) FROM bulk_claim_leads l WHERE l.batch_id = b.id AND l.activated_at IS NOT NULL) AS active
       FROM bulk_claim_batches b
       ORDER BY b.created_at DESC
       LIMIT 10"
);

$companiesForPicker = $db->fetchAll("SELECT id, name FROM companies ORDER BY name ASC LIMIT 200");

$csrf = generateCSRFToken();

adminHeader(t('adminchrome.bulk_claim'), 'reports');
?>

<div class="max-w-6xl mx-auto">

    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-600"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Bulk Claim</h1>
                <p class="text-gray-500 text-sm">Pre-build cards for cold leads and send magic-link claims via WhatsApp.</p>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl flex items-start gap-3
        <?= $messageType === 'success'
            ? 'bg-green-50 border border-green-200 text-green-700'
            : ($messageType === 'warning' ? 'bg-amber-50 border border-amber-200 text-amber-700' : 'bg-red-50 border border-red-200 text-red-700') ?>">
        <i class="fa-solid fa-<?= $messageType === 'success' ? 'circle-check' : ($messageType === 'warning' ? 'triangle-exclamation' : 'circle-exclamation') ?> mt-0.5"></i>
        <div><?= sanitize($message) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($sendReport !== null): ?>
    <div class="mb-6 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div class="font-medium text-sm text-gray-700">Send Results</div>
            <a href="?batch=<?= urlencode($sendReport['batch_id']) ?>" class="text-xs text-purple-600 font-medium hover:underline">View batch dashboard →</a>
        </div>
        <div class="max-h-72 overflow-y-auto divide-y divide-gray-50">
            <?php foreach ($sendReport['rows'] as $r): ?>
            <div class="px-5 py-2 flex items-center justify-between text-sm">
                <span class="text-gray-700"><?= sanitize($r['name']) ?> &middot; <?= sanitize($r['phone']) ?></span>
                <?php if ($r['status'] === 'sent'): ?>
                    <span class="text-green-600 font-medium"><i class="fa-solid fa-check mr-1"></i>Sent</span>
                <?php elseif ($r['status'] === 'skipped'): ?>
                    <span class="text-amber-600 font-medium"><i class="fa-solid fa-forward mr-1"></i>Skipped (<?= sanitize($r['reason']) ?>)</span>
                <?php else: ?>
                    <span class="text-red-600 font-medium"><i class="fa-solid fa-xmark mr-1"></i><?= sanitize($r['reason'] ?? 'Failed') ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">1. Paste or upload CSV</h2>
                <p class="text-xs text-gray-500 mt-1">Columns: <code class="text-[11px] bg-gray-50 px-1 rounded">name, phone, company_name, title, email</code>. First row must be the header.</p>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>">
                <input type="hidden" name="action" value="preview">

                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Batch label (optional)</label>
                    <input type="text" name="admin_label" maxlength="255"
                        value="<?= sanitize($previewLabel) ?>"
                        placeholder="e.g. Cardify expansion Apr 2026"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-400 focus:ring-purple-200">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Host company (card records will be created under this company)</label>
                    <select name="company_id"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-400 focus:ring-purple-200">
                        <?php foreach ($companiesForPicker as $c): ?>
                            <option value="<?= sanitize($c['id']) ?>" <?= $c['id'] === $previewCompanyId ? 'selected' : '' ?>>
                                <?= sanitize($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Upload .csv</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv"
                        class="w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-gray-100 file:text-gray-700">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">…or paste CSV</label>
                    <textarea name="csv_text" rows="8"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm font-mono focus:border-purple-400 focus:ring-purple-200"
                        placeholder="name,phone,company_name,title,email&#10;Ali Al-Zaabi,96899889100,BHD Group,CEO,ali@bhd.om"><?= sanitize($previewCsv) ?></textarea>
                </div>

                <div class="flex items-center justify-between">
                    <p class="text-xs text-gray-500">Max <?= BULK_CLAIM_MAX_PER_BATCH ?> leads per batch.</p>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-medium hover:bg-purple-700">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i>Preview
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">Recent batches</h2>
            </div>
            <div class="divide-y divide-gray-50">
                <?php if (empty($recentBatches)): ?>
                    <div class="px-5 py-6 text-sm text-gray-500">No batches yet.</div>
                <?php else: foreach ($recentBatches as $b): ?>
                    <a href="?batch=<?= urlencode($b['id']) ?>" class="block px-5 py-3 hover:bg-gray-50">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-800 truncate"><?= sanitize($b['admin_label'] ?? '(unnamed)') ?></span>
                            <span class="text-xs text-gray-500"><?= date('M j', strtotime($b['created_at'])) ?></span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            <?= (int)$b['sent'] ?>/<?= (int)$b['total'] ?> sent &middot; <?= (int)$b['claimed'] ?> claimed &middot; <?= (int)$b['active'] ?> active
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>

    <?php if (!empty($previewRows)):
        $validCount = 0; $dupCount = 0; $invalidCount = 0;
        foreach ($previewRows as $pr) {
            if ($pr['reason'] === null)                          $validCount++;
            elseif (in_array($pr['reason'], ['already_in_leads', 'already_has_card'], true)) $dupCount++;
            else                                                 $invalidCount++;
        }
    ?>
    <div class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-gray-800">2. Preview (<?= count($previewRows) ?> rows)</h2>
                <p class="text-xs text-gray-500 mt-1">
                    <span class="text-green-600 font-medium"><?= $validCount ?> ready</span> &middot;
                    <span class="text-amber-600 font-medium"><?= $dupCount ?> duplicate</span> &middot;
                    <span class="text-red-600 font-medium"><?= $invalidCount ?> invalid</span>
                </p>
            </div>
            <?php if ($validCount > 0): ?>
            <form method="POST" onsubmit="return confirm('Send <?= $validCount ?> WhatsApp magic-links now? Duplicates + invalids will be skipped automatically.');">
                <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>">
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="csv_text" value="<?= sanitize($previewCsv) ?>">
                <input type="hidden" name="admin_label" value="<?= sanitize($previewLabel) ?>">
                <input type="hidden" name="company_id" value="<?= sanitize($previewCompanyId) ?>">
                <button type="submit" class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700">
                    <i class="fa-brands fa-whatsapp mr-1"></i>Send to <?= $validCount ?> lead<?= $validCount === 1 ? '' : 's' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Name</th>
                        <th class="text-left px-4 py-2 font-medium">Phone</th>
                        <th class="text-left px-4 py-2 font-medium">Company</th>
                        <th class="text-left px-4 py-2 font-medium">Title</th>
                        <th class="text-left px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($previewRows as $pr): ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-800"><?= sanitize($pr['row']['name']) ?></td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs"><?= sanitize($pr['normalized_phone']) ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= sanitize($pr['row']['company_name']) ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= sanitize($pr['row']['title']) ?></td>
                        <td class="px-4 py-2">
                            <?php if ($pr['reason'] === null): ?>
                                <span class="inline-flex items-center text-xs font-medium text-green-700 bg-green-50 px-2 py-1 rounded-full">
                                    <i class="fa-solid fa-check mr-1"></i>Ready
                                </span>
                            <?php elseif ($pr['reason'] === 'already_has_card'): ?>
                                <span class="inline-flex items-center text-xs font-medium text-amber-700 bg-amber-50 px-2 py-1 rounded-full">
                                    <i class="fa-solid fa-id-card mr-1"></i>Already has a card
                                </span>
                            <?php elseif ($pr['reason'] === 'already_in_leads'): ?>
                                <span class="inline-flex items-center text-xs font-medium text-amber-700 bg-amber-50 px-2 py-1 rounded-full">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i>Already contacted
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center text-xs font-medium text-red-700 bg-red-50 px-2 py-1 rounded-full">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i><?= sanitize($pr['reason']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Batch detail dashboard (inline)
    if (!empty($_GET['batch'])):
        $batchId = $_GET['batch'];
        $batch   = $db->fetchOne("SELECT * FROM bulk_claim_batches WHERE id = :id", ['id' => $batchId]);
        if ($batch):
            $leads = $db->fetchAll(
                "SELECT * FROM bulk_claim_leads WHERE batch_id = :b ORDER BY created_at ASC",
                ['b' => $batchId]
            );
    ?>
    <div class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Batch: <?= sanitize($batch['admin_label'] ?? $batch['id']) ?></h2>
            <p class="text-xs text-gray-500 mt-1">
                Created <?= date('M j, Y H:i', strtotime($batch['created_at'])) ?> &middot;
                <?= (int)$batch['sent'] ?>/<?= (int)$batch['total'] ?> sent &middot;
                Status: <?= sanitize($batch['status']) ?>
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Name</th>
                        <th class="text-left px-4 py-2 font-medium">Phone</th>
                        <th class="text-left px-4 py-2 font-medium">WA</th>
                        <th class="text-left px-4 py-2 font-medium">Opened</th>
                        <th class="text-left px-4 py-2 font-medium">Claimed</th>
                        <th class="text-left px-4 py-2 font-medium">Active</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($leads as $l): ?>
                    <tr>
                        <td class="px-4 py-2 text-gray-800"><?= sanitize($l['name']) ?></td>
                        <td class="px-4 py-2 text-gray-500 font-mono text-xs"><?= sanitize($l['phone']) ?></td>
                        <td class="px-4 py-2 text-xs">
                            <?= $l['wa_status'] === 'sent' ? '<span class="text-green-600">sent</span>' : ($l['wa_status'] === 'failed' ? '<span class="text-red-600" title="' . sanitize($l['wa_error'] ?? '') . '">failed</span>' : 'pending') ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600"><?= $l['opened_at'] ? date('M j H:i', strtotime($l['opened_at'])) : '—' ?></td>
                        <td class="px-4 py-2 text-xs text-gray-600"><?= $l['claimed_at'] ? date('M j H:i', strtotime($l['claimed_at'])) : '—' ?></td>
                        <td class="px-4 py-2 text-xs text-gray-600"><?= $l['activated_at'] ? date('M j H:i', strtotime($l['activated_at'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; endif; ?>

</div>

<?php adminFooter(); ?>
