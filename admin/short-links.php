<?php
/**
 * Admin, Branded Link Shortener
 *
 * Company admins create/edit/delete short links pointing to destination URLs.
 * Each link lives at cardify.om/s/<slug> and increments a click counter.
 * Reserved slugs and dangerous schemes are rejected. Creation is rate-limited
 * to 50 new links per company per 24h.
 *
 * Accessed via:
 *   - /{company_slug}/admin/short-links        (via company_admin.php pageMap)
 *   - /admin/short-links.php                   (legacy direct)
 */
require_once __DIR__ . '/../config.php';
requireAdmin();
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$companyId = getCurrentCompanyId();
$userId = $_SESSION['user_id'] ?? null;
$isSuperAdmin = Auth::isSuperAdmin();

if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

// Slugs that collide with existing public/admin routes, cannot be used.
$RESERVED_SLUGS = [
    'admin','api','login','logout','install','share','company','webhooks',
    'amwalpay','assets','uploads','data','printshop','industries','intro',
    'blog','careers','about','contact','faq','terms','privacy','security',
    'cookies','sitemap','print-shops','generate_card_html','download_card',
    'index','router','vcf','profile','l','s','card','digital_card','qr',
    'maintenance','404','500','502','save_card_image','save_card_both',
    'save_card_with_preview','save_request_preview','log_generation',
    'feed','reset-password','forgot-password','favicon','robots','custom',
    'nfc','portal','onboarding','customize','get-started','pitch-deck',
    'wallet_apple','wallet_google','card_click','tmp','logs','cron',
    'scripts','bhd','docs','composer','package','tailwind',
];

$message = null;
$messageType = 'success';

$base62 = function (int $len = 6): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
};

$isReserved = function (string $slug) use ($RESERVED_SLUGS): bool {
    return in_array(strtolower($slug), $RESERVED_SLUGS, true);
};

// TLDs / shorteners now come from includes/UrlSafety.php (UNSAFE_TLDS,
// UNSAFE_SHORTENERS) so card_click.php and this admin share one source of
// truth. (Codex round-2 Finding 1 + Finding 6.)
$validateDestination = function (string $url) {
    if ($url === '' || strlen($url) > 1024) {
        return 'Destination URL must be 1–1024 characters.';
    }
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return 'Destination must start with http:// or https://';
    }
    // canonicalHost() lowercases and strips trailing dots so `bit.ly.` and
    // `cardify.om.` can't bypass the blocklists below.
    $host = canonicalHostFromUrl($url);
    if ($host === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return 'Destination URL is not valid.';
    }
    // Prevent self-redirect loops / infinite chains.
    $selfHosts = ['cardify.om', 'www.cardify.om', $_SERVER['HTTP_HOST'] ?? ''];
    foreach ($selfHosts as $s) {
        if ($s !== '' && canonicalHost((string) $s) === $host) {
            return 'Destination cannot point back to cardify.om.';
        }
    }
    // Block free/abused TLDs, common in phishing kits.
    if (hasUnsafeTld($host)) {
        $tld = substr(strrchr($host, '.') ?: '', 1);
        return 'That destination TLD (.' . $tld . ') is not allowed. Please use your own domain.';
    }
    // Block other URL shorteners to prevent reputation laundering via chaining.
    if (isUrlShortener($host)) {
        return 'Destination cannot be another URL shortener.';
    }
    return null; // valid
};

// ------------------------------------------------------------------
// Actions: create / update / delete
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid request token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $destination = trim($_POST['destination'] ?? '');
            $slug        = trim($_POST['slug'] ?? '');
            $title       = trim($_POST['title'] ?? '');
            $expiresRaw  = trim($_POST['expires_at'] ?? '');

            $err = $validateDestination($destination);

            if (!$err) {
                // Rate limit: 50 new links / company / 24h
                $rate = $pdo->prepare(
                    "SELECT COUNT(*) FROM short_links
                     WHERE company_id = :c AND created_at >= (NOW() - INTERVAL 1 DAY)"
                );
                $rate->execute([':c' => $companyId]);
                if ((int)$rate->fetchColumn() >= 50) {
                    $err = 'Rate limit reached (50 new links per day). Try again tomorrow.';
                }
            }

            if (!$err) {
                // Slug: custom or auto-generated
                if ($slug !== '') {
                    if (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $slug)) {
                        $err = 'Custom slug must be 3–20 chars (letters, digits, _ or -).';
                    } elseif ($isReserved($slug)) {
                        $err = 'That slug is reserved. Please pick another.';
                    } else {
                        $chk = $pdo->prepare("SELECT 1 FROM short_links WHERE slug = :s");
                        $chk->execute([':s' => $slug]);
                        if ($chk->fetchColumn()) {
                            $err = 'Slug already taken. Try another.';
                        }
                    }
                } else {
                    // Auto-generate, retry up to 5 times on collision
                    $tries = 0;
                    do {
                        $slug  = $base62(6);
                        $tries++;
                        $chk = $pdo->prepare("SELECT 1 FROM short_links WHERE slug = :s");
                        $chk->execute([':s' => $slug]);
                        $taken = (bool) $chk->fetchColumn();
                    } while ($taken && $tries < 5);
                    if ($taken) {
                        $err = 'Could not allocate a unique slug. Try again.';
                    }
                }
            }

            $expiresAt = null;
            if (!$err && $expiresRaw !== '') {
                $ts = strtotime($expiresRaw);
                if (!$ts || $ts < time()) {
                    $err = 'Expiry must be a future date.';
                } else {
                    $expiresAt = date('Y-m-d H:i:s', $ts);
                }
            }

            if ($err) {
                $message = $err;
                $messageType = 'error';
            } else {
                try {
                    // Super-admin creations are auto-approved; all others go
                    // through moderation to prevent phishing abuse. See
                    // migration 062 for the `approved` + `approved_by_user_id`
                    // columns.
                    $autoApproved = $isSuperAdmin ? 1 : 0;

                    $ins = $pdo->prepare(
                        "INSERT INTO short_links
                            (company_id, slug, destination, title, expires_at,
                             created_by_user_id, approved, approved_by_user_id, approved_at)
                         VALUES
                            (:c, :s, :d, :t, :e, :u, :ap, :au, :at)"
                    );
                    $ins->execute([
                        ':c'  => $companyId,
                        ':s'  => $slug,
                        ':d'  => $destination,
                        ':t'  => $title !== '' ? substr($title, 0, 200) : null,
                        ':e'  => $expiresAt,
                        ':u'  => $userId,
                        ':ap' => $autoApproved,
                        ':au' => $autoApproved ? $userId : null,
                        ':at' => $autoApproved ? date('Y-m-d H:i:s') : null,
                    ]);
                    if ($autoApproved) {
                        $message = 'Short link created: /s/' . $slug;
                    } else {
                        $message = 'Short link /s/' . $slug . ' created, awaiting super-admin approval before it will redirect.';
                    }
                } catch (Exception $e) {
                    $message = 'Create failed: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $del = $pdo->prepare(
                    "DELETE FROM short_links WHERE id = :id AND company_id = :c"
                );
                $del->execute([':id' => $id, ':c' => $companyId]);
                $message = $del->rowCount() ? 'Short link deleted.' : 'Link not found.';
                $messageType = $del->rowCount() ? 'success' : 'error';
            }
        } elseif ($action === 'approve' || $action === 'unapprove') {
            // Super-admin moderation gate.
            if (!$isSuperAdmin) {
                $message = 'Only super admins can moderate short links.';
                $messageType = 'error';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    if ($action === 'approve') {
                        $stmt = $pdo->prepare(
                            "UPDATE short_links
                                SET approved = 1,
                                    approved_by_user_id = :u,
                                    approved_at = NOW()
                              WHERE id = :id"
                        );
                        $stmt->execute([':u' => $userId, ':id' => $id]);
                        $message = $stmt->rowCount() ? 'Short link approved.' : 'Link not found.';
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE short_links
                                SET approved = 0,
                                    approved_by_user_id = NULL,
                                    approved_at = NULL
                              WHERE id = :id"
                        );
                        $stmt->execute([':id' => $id]);
                        $message = $stmt->rowCount() ? 'Short link un-approved.' : 'Link not found.';
                    }
                    $messageType = $stmt->rowCount() ? 'success' : 'error';
                }
            }
        } elseif ($action === 'update') {
            $id          = (int)($_POST['id'] ?? 0);
            $destination = trim($_POST['destination'] ?? '');
            $title       = trim($_POST['title'] ?? '');
            $expiresRaw  = trim($_POST['expires_at'] ?? '');

            $err = $validateDestination($destination);
            $expiresAt = null;
            if (!$err && $expiresRaw !== '') {
                $ts = strtotime($expiresRaw);
                if (!$ts) {
                    $err = 'Invalid expiry.';
                } else {
                    $expiresAt = date('Y-m-d H:i:s', $ts);
                }
            }

            if ($err) {
                $message = $err;
                $messageType = 'error';
            } else {
                try {
                    // If destination changed for a non-super-admin, kick back to
                    // pending so the new URL goes through moderation too.
                    if ($isSuperAdmin) {
                        $upd = $pdo->prepare(
                            "UPDATE short_links
                                SET destination = :d, title = :t, expires_at = :e
                              WHERE id = :id AND company_id = :c"
                        );
                        $upd->execute([
                            ':d'  => $destination,
                            ':t'  => $title !== '' ? substr($title, 0, 200) : null,
                            ':e'  => $expiresAt,
                            ':id' => $id,
                            ':c'  => $companyId,
                        ]);
                    } else {
                        $cur = $pdo->prepare("SELECT destination FROM short_links WHERE id = :id AND company_id = :c");
                        $cur->execute([':id' => $id, ':c' => $companyId]);
                        $prevDest = (string)$cur->fetchColumn();
                        $destChanged = ($prevDest !== '' && $prevDest !== $destination);

                        $sql = "UPDATE short_links
                                    SET destination = :d, title = :t, expires_at = :e"
                             . ($destChanged ? ", approved = 0, approved_by_user_id = NULL, approved_at = NULL" : "")
                             . " WHERE id = :id AND company_id = :c";
                        $upd = $pdo->prepare($sql);
                        $upd->execute([
                            ':d'  => $destination,
                            ':t'  => $title !== '' ? substr($title, 0, 200) : null,
                            ':e'  => $expiresAt,
                            ':id' => $id,
                            ':c'  => $companyId,
                        ]);
                        if ($destChanged) {
                            $message = 'Short link updated, destination changed, awaiting super-admin re-approval.';
                        } else {
                            $message = 'Short link updated.';
                        }
                    }
                    if (empty($message)) {
                        $message = 'Short link updated.';
                    }
                } catch (Exception $e) {
                    $message = 'Update failed: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// ------------------------------------------------------------------
// Load list
// ------------------------------------------------------------------
$links = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, slug, destination, title, click_count, expires_at, created_at,
                approved, approved_at
           FROM short_links
          WHERE company_id = :c
          ORDER BY created_at DESC
          LIMIT 500"
    );
    $stmt->execute([':c' => $companyId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('short_links list: ' . $e->getMessage());
}

$totalClicks = 0;
foreach ($links as $l) {
    $totalClicks += (int) $l['click_count'];
}

// Build the public short-link prefix (schema-aware for local dev vs prod).
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
$shortPrefix = $scheme . '://' . $host . rtrim(getBasePath(), '/') . '/s/';

adminHeader('Short Links', 'short-links');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-xl flex items-center gap-3 <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'; ?>">
    <i class="<?= $messageType === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
    <?= sanitize($message); ?>
</div>
<?php endif; ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fa-solid fa-link text-blue-600 mr-2"></i>Branded Short Links
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
            Create tracked short URLs on <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs"><?= sanitize($shortPrefix); ?>&lt;slug&gt;</code>
        </p>
    </div>
    <div class="flex items-center gap-3 text-sm">
        <div class="px-3 py-2 bg-white border border-gray-200 rounded-lg">
            <span class="text-gray-500">Links:</span>
            <span class="font-semibold text-gray-900"><?= number_format(count($links)); ?></span>
        </div>
        <div class="px-3 py-2 bg-white border border-gray-200 rounded-lg">
            <span class="text-gray-500">Clicks:</span>
            <span class="font-semibold text-gray-900"><?= number_format($totalClicks); ?></span>
        </div>
    </div>
</div>

<!-- Create form -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">
        <i class="fa-solid fa-plus text-green-600 mr-1"></i>Create New Short Link
    </h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?= csrfField(); ?>
        <input type="hidden" name="action" value="create">

        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Destination URL <span class="text-red-500">*</span></label>
            <input type="url" name="destination" required placeholder="https://example.com/long/path"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            <p class="text-xs text-gray-500 mt-1">Must start with <code>http://</code> or <code>https://</code>. Cannot point back to cardify.om.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Custom Slug <span class="text-gray-400">(optional)</span></label>
            <div class="flex items-center">
                <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-xs text-gray-500"><?= sanitize($shortPrefix); ?></span>
                <input type="text" name="slug" maxlength="20" pattern="[A-Za-z0-9_-]{3,20}" placeholder="auto"
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            <p class="text-xs text-gray-500 mt-1">3–20 chars: letters, digits, <code>_</code>, <code>-</code>. Leave blank to auto-generate.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-gray-400">(optional)</span></label>
            <input type="text" name="title" maxlength="200" placeholder="Spring campaign landing page"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Expiry <span class="text-gray-400">(optional)</span></label>
            <input type="datetime-local" name="expires_at"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
        </div>

        <div class="md:col-span-2 flex justify-end">
            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
                <i class="fa-solid fa-link mr-1"></i>Create Short Link
            </button>
        </div>
    </form>
</div>

<!-- List -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <?php if (empty($links)): ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fa-solid fa-link-slash text-4xl text-gray-300 mb-3"></i>
        <p class="font-medium"><?= htmlspecialchars(t('emptystates.no_short_links_h')) ?></p>
        <p class="text-sm mt-1"><?= htmlspecialchars(t('emptystates.no_short_links_sub')) ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left">Short URL</th>
                    <th class="px-4 py-3 text-left">Destination</th>
                    <th class="px-4 py-3 text-left">Title</th>
                    <th class="px-4 py-3 text-right">Clicks</th>
                    <th class="px-4 py-3 text-left">Expires</th>
                    <th class="px-4 py-3 text-left">Created</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($links as $l):
                    $shortUrl = $shortPrefix . $l['slug'];
                    $expired = !empty($l['expires_at']) && strtotime($l['expires_at']) < time();
                    $approved = !empty($l['approved']);
                ?>
                <tr class="<?= ($expired || !$approved) ? 'opacity-60' : ''; ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="<?= sanitize($shortUrl); ?>" target="_blank" rel="noopener"
                               class="text-blue-600 hover:underline font-mono text-xs break-all"><?= sanitize($shortUrl); ?></a>
                            <button type="button"
                                    onclick="navigator.clipboard.writeText('<?= sanitize($shortUrl); ?>'); this.innerHTML='<i class=\'fa-solid fa-check text-green-600\'></i>';"
                                    class="text-gray-400 hover:text-gray-600" title="Copy">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                            <?php if (!$approved): ?>
                                <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-semibold uppercase tracking-wide" title="Waiting for super-admin approval, will return 404 until approved">Pending</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 max-w-xs">
                        <a href="<?= sanitize($l['destination']); ?>" target="_blank" rel="noopener"
                           class="text-gray-700 hover:underline text-xs break-all line-clamp-2"><?= sanitize($l['destination']); ?></a>
                    </td>
                    <td class="px-4 py-3 text-gray-600"><?= sanitize($l['title'] ?? '') ?: '<span class="text-gray-300">,</span>'; ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900"><?= number_format((int)$l['click_count']); ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs">
                        <?php if ($l['expires_at']): ?>
                            <?= $expired ? '<span class="text-red-600">Expired</span> ' : ''; ?>
                            <?= sanitize(date('Y-m-d H:i', strtotime($l['expires_at']))); ?>
                        <?php else: ?>
                            <span class="text-gray-300">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= sanitize(date('Y-m-d H:i', strtotime($l['created_at']))); ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <?php if ($isSuperAdmin): ?>
                            <?php if (!$approved): ?>
                            <form method="post" class="inline-block mr-1">
                                <?= csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int)$l['id']; ?>">
                                <button type="submit" class="text-green-600 hover:text-green-800 text-xs" title="Approve (super-admin)">
                                    <i class="fa-solid fa-circle-check"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="post" class="inline-block mr-1" onsubmit="return confirm('Un-approve this link? It will stop redirecting.');">
                                <?= csrfField(); ?>
                                <input type="hidden" name="action" value="unapprove">
                                <input type="hidden" name="id" value="<?= (int)$l['id']; ?>">
                                <button type="submit" class="text-amber-600 hover:text-amber-800 text-xs" title="Un-approve (super-admin)">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" class="inline-block" onsubmit="return confirm('Delete this short link? This cannot be undone.');">
                            <?= csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$l['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-800 text-xs" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php adminFooter(); ?>
