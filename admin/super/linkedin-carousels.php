<?php
/**
 * Super Admin, LinkedIn Carousels
 * Generate, copy, and post the daily LinkedIn carousels.
 * Cron auto-generates the next due post at 9 AM Oman time.
 * This UI gives Ali the commentary + PDF + mark-posted tracking for the
 * Cardify company page (he posts manually, no auto-publish to LinkedIn).
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid request');
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        $message = 'Missing post ID'; $messageType = 'error';
    } elseif ($action === 'generate') {
        require_once INCLUDES_DIR . '/CarouselSlideGenerator.php';
        require_once INCLUDES_DIR . '/CarouselPDFRenderer.php';
        try {
            $post = $db->fetchOne("SELECT id, title, slug, excerpt, content AS body FROM blog_posts WHERE id = ?", [$id]);
            if (!$post) throw new RuntimeException('Post not found');
            $slides = CarouselSlideGenerator::generate($post);
            $pdfDir = BASE_DIR . '/uploads/linkedin-carousels';
            if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
            $pdfPath = $pdfDir . '/' . date('Y-m-d') . '-' . preg_replace('/[^a-z0-9-]/i', '-', $post['slug']) . '.pdf';
            $blogUrl = 'https://cardify.om/blog/' . $post['slug'];
            CarouselPDFRenderer::render($slides, $pdfPath, $blogUrl);
            $commentary = $slides['hook_en']
                        . "\n\n" . $slides['hook_ar']
                        . "\n\nFull guide: " . $blogUrl
                        . "\n\n#Cardify #Oman #DigitalBusinessCards #Branding";
            $relPdf = str_replace(BASE_DIR . '/', '', $pdfPath);
            $db->update('blog_posts', [
                'linkedin_carousel_pdf' => $relPdf,
                'linkedin_carousel_data' => json_encode($slides, JSON_UNESCAPED_UNICODE),
                'linkedin_commentary' => $commentary,
                'linkedin_generated_at' => dbNow(),
            ], 'id = :id', ['id' => $id]);
            $message = 'Carousel generated successfully'; $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Generation failed: ' . $e->getMessage(); $messageType = 'error';
        }
    } elseif ($action === 'mark_posted') {
        $db->update('blog_posts', ['linkedin_marked_posted_at' => dbNow()], 'id = :id', ['id' => $id]);
        $message = 'Marked as posted'; $messageType = 'success';
    } elseif ($action === 'unmark_posted') {
        $db->update('blog_posts', ['linkedin_marked_posted_at' => null], 'id = :id', ['id' => $id]);
        $message = 'Unmarked'; $messageType = 'success';
    } elseif ($action === 'regenerate') {
        $db->update('blog_posts', [
            'linkedin_carousel_pdf' => null,
            'linkedin_carousel_data' => null,
            'linkedin_commentary' => null,
            'linkedin_generated_at' => null,
        ], 'id = :id', ['id' => $id]);
        header('Location: /admin/super/linkedin-carousels.php?cleared=' . $id); exit;
    }
}
if (isset($_GET['cleared'])) {
    $message = 'Carousel data cleared. Click Generate to recreate.';
    $messageType = 'success';
}

$counts = $db->fetchOne("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN linkedin_marked_posted_at IS NOT NULL THEN 1 ELSE 0 END) AS posted,
    SUM(CASE WHEN linkedin_generated_at IS NOT NULL AND linkedin_marked_posted_at IS NULL THEN 1 ELSE 0 END) AS ready,
    SUM(CASE WHEN linkedin_generated_at IS NULL AND DATE(published_at) <= CURDATE() THEN 1 ELSE 0 END) AS pending
    FROM blog_posts");

// Hero: the next "ready" carousel (most recently generated, not yet posted)
$heroPost = $db->fetchOne(
    "SELECT id, title, slug, published_at,
            linkedin_carousel_pdf, linkedin_carousel_data, linkedin_commentary, linkedin_generated_at
     FROM blog_posts
     WHERE linkedin_generated_at IS NOT NULL AND linkedin_marked_posted_at IS NULL
     ORDER BY linkedin_generated_at DESC
     LIMIT 1"
);

// Filter for the table below
$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'pending') {
    $where = 'linkedin_generated_at IS NULL AND DATE(published_at) <= CURDATE()';
} elseif ($filter === 'ready') {
    $where = 'linkedin_generated_at IS NOT NULL AND linkedin_marked_posted_at IS NULL';
} elseif ($filter === 'posted') {
    $where = 'linkedin_marked_posted_at IS NOT NULL';
}
// Exclude the hero post from the table to avoid duplication
$excludeHero = $heroPost ? ' AND id != ' . (int)$heroPost['id'] : '';

$posts = $db->fetchAll(
    "SELECT id, title, slug, status, published_at,
            linkedin_carousel_pdf, linkedin_carousel_data, linkedin_commentary,
            linkedin_generated_at, linkedin_marked_posted_at,
            CASE
                WHEN linkedin_marked_posted_at IS NOT NULL THEN 2
                WHEN linkedin_generated_at IS NOT NULL THEN 0
                ELSE 1
            END AS sort_priority
     FROM blog_posts
     WHERE $where $excludeHero
     ORDER BY sort_priority ASC,
              CASE
                WHEN linkedin_generated_at IS NOT NULL THEN linkedin_generated_at
                WHEN linkedin_marked_posted_at IS NOT NULL THEN linkedin_marked_posted_at
                ELSE published_at
              END DESC
     LIMIT 500"
);

$cardifyLinkedInUrl = 'https://www.linkedin.com/company/111727648/admin/page-posts/published/';

adminHeader('LinkedIn Carousels', 'linkedin-carousels');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Header + counts -->
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fa-brands fa-linkedin" style="color: #2563eb;"></i> LinkedIn Carousels
            </h1>
            <p class="text-sm text-gray-600 mt-1 max-w-2xl">
                Cron auto-generates the next due carousel at <strong>9 AM Oman time</strong>.
                Copy the caption, download the PDF, then post manually to the
                <a href="<?= $cardifyLinkedInUrl ?>" target="_blank" class="text-blue-600 underline">Cardify LinkedIn page</a>.
            </p>
        </div>
        <div class="grid grid-cols-4 gap-2 text-center">
            <a href="?filter=all" class="px-3 py-2 rounded-lg <?= $filter==='all'?'bg-gray-900 text-white':'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['total'] ?></div><div class="text-xs">Total</div>
            </a>
            <a href="?filter=pending" class="px-3 py-2 rounded-lg <?= $filter==='pending'?'bg-amber-500 text-white':'bg-amber-50 text-amber-700 hover:bg-amber-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['pending'] ?></div><div class="text-xs">Pending</div>
            </a>
            <a href="?filter=ready" class="px-3 py-2 rounded-lg <?= $filter==='ready'?'bg-blue-600 text-white':'bg-blue-50 text-blue-700 hover:bg-blue-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['ready'] ?></div><div class="text-xs">Ready</div>
            </a>
            <a href="?filter=posted" class="px-3 py-2 rounded-lg <?= $filter==='posted'?'bg-green-600 text-white':'bg-green-50 text-green-700 hover:bg-green-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['posted'] ?></div><div class="text-xs">Posted</div>
            </a>
        </div>
    </div>
</div>

<?php if ($heroPost && $filter === 'all'): ?>
    <!-- HERO: Today's ready-to-post carousel -->
    <?php $heroSlides = $heroPost['linkedin_carousel_data'] ? json_decode($heroPost['linkedin_carousel_data'], true) : null; ?>
    <div class="rounded-2xl shadow-xl mb-8 overflow-hidden" style="background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%); color: #fff;">
        <div class="p-6" style="border-bottom: 1px solid rgba(255,255,255,0.20);">
            <div class="flex items-center gap-2 mb-2">
                <span class="px-2 py-0.5 text-xs uppercase font-semibold rounded" style="background: rgba(255,255,255,0.22); color: #fff; letter-spacing: 0.05em;">Ready to post now</span>
                <span class="text-xs" style="color: rgba(255,255,255,0.85);">Generated <?= htmlspecialchars(date('M j, H:i', dbTs($heroPost['linkedin_generated_at']))) ?></span>
            </div>
            <h2 class="text-2xl font-bold leading-tight" style="color: #fff;">
                <a href="https://cardify.om/blog/<?= htmlspecialchars($heroPost['slug']) ?>" target="_blank" style="color: #fff; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= htmlspecialchars($heroPost['title']) ?></a>
            </h2>
        </div>

        <div class="p-6 grid lg:grid-cols-3 gap-5" style="background: rgba(0,0,0,0.10);">
            <!-- LEFT: Caption -->
            <div class="lg:col-span-2">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold uppercase" style="color: #fff; letter-spacing: 0.05em;">LinkedIn caption (paste this)</label>
                    <button type="button" onclick="copyHero()" id="heroCopyBtn" class="px-3 py-1.5 text-sm font-semibold rounded-md" style="background: #fff; color: #2563eb; border: 0; cursor: pointer;">
                        <i class="fa-solid fa-copy"></i> Copy caption
                    </button>
                </div>
                <textarea id="heroCaption" readonly class="w-full text-sm font-mono rounded-lg p-4 resize-none" style="height: 11rem; background: #fff; color: #111827; border: 0;"><?= htmlspecialchars($heroPost['linkedin_commentary'] ?? '') ?></textarea>
            </div>

            <!-- RIGHT: Actions -->
            <div class="space-y-3">
                <a href="/admin/super/linkedin-carousel-download.php?id=<?= $heroPost['id'] ?>" download class="block text-center px-4 py-3 text-sm font-semibold rounded-lg" style="background: #fff; color: #2563eb; text-decoration: none;">
                    <i class="fa-solid fa-file-arrow-down"></i> Download PDF carousel
                </a>
                <a href="<?= $cardifyLinkedInUrl ?>" target="_blank" class="block text-center px-4 py-3 text-sm font-semibold rounded-lg" style="background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.4); text-decoration: none;">
                    <i class="fa-brands fa-linkedin"></i> Open Cardify LinkedIn
                </a>
                <form method="POST" class="block">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="mark_posted">
                    <input type="hidden" name="id" value="<?= $heroPost['id'] ?>">
                    <button type="submit" class="w-full px-4 py-3 text-sm font-semibold rounded-lg" style="background: #16a34a; color: #fff; border: 0; cursor: pointer;">
                        <i class="fa-solid fa-check-circle"></i> Mark as posted
                    </button>
                </form>
                <form method="POST" class="block" onsubmit="return confirm('Regenerate carousel? Old text + PDF replaced.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="regenerate">
                    <input type="hidden" name="id" value="<?= $heroPost['id'] ?>">
                    <button type="submit" class="w-full px-3 py-2 text-xs font-medium rounded" style="background: transparent; color: rgba(255,255,255,0.85); border: 1px solid rgba(255,255,255,0.30); cursor: pointer;">
                        <i class="fa-solid fa-rotate"></i> Regenerate
                    </button>
                </form>
            </div>
        </div>

        <?php if ($heroSlides): ?>
        <details style="background: rgba(0,0,0,0.15); border-top: 1px solid rgba(255,255,255,0.15);">
            <summary class="px-6 py-3 cursor-pointer text-xs uppercase font-semibold" style="color: #fff; letter-spacing: 0.05em;">
                <i class="fa-solid fa-list"></i> View per-slide copy (10 elements)
            </summary>
            <div class="px-6 pb-6 grid sm:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                <?php
                $items = [
                    'Hook EN' => $heroSlides['hook_en'] ?? '',
                    'Hook AR' => $heroSlides['hook_ar'] ?? '',
                    'Tension' => $heroSlides['tension'] ?? '',
                    'Point 1' => ($heroSlides['points'][0]['text'] ?? ''),
                    'Point 2' => ($heroSlides['points'][1]['text'] ?? ''),
                    'Point 3' => ($heroSlides['points'][2]['text'] ?? ''),
                    'Takeaway EN' => $heroSlides['takeaway_en'] ?? '',
                    'Takeaway AR' => $heroSlides['takeaway_ar'] ?? '',
                    'CTA EN' => $heroSlides['cta_en'] ?? '',
                    'CTA AR' => $heroSlides['cta_ar'] ?? '',
                ];
                foreach ($items as $label => $val):
                    $isAr = strpos($label, 'AR') !== false;
                ?>
                <div class="flex items-center gap-2 py-1.5" style="border-bottom: 1px solid rgba(255,255,255,0.12);">
                    <span class="w-20 flex-shrink-0" style="color: rgba(255,255,255,0.7);"><?= $label ?>:</span>
                    <span class="flex-1 truncate <?= $isAr ? 'text-right' : '' ?>" style="color: #fff;" <?= $isAr ? 'dir="rtl"' : '' ?>><?= htmlspecialchars($val) ?></span>
                    <button type="button" onclick="copyTextDirectly(this, <?= json_encode($val, JSON_HEX_QUOT|JSON_HEX_APOS|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>)" class="px-1.5 py-0.5 rounded" style="background: rgba(255,255,255,0.18); color: #fff; border: 0; cursor: pointer;">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- All carousels list -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">
            <?php
            echo $filter === 'pending' ? 'Pending (need generation)' :
                ($filter === 'ready' ? 'Ready to post' :
                ($filter === 'posted' ? 'Already posted' : 'All carousels'));
            ?>
            <span class="ml-2 text-sm text-gray-500"><?= count($posts) ?></span>
        </h3>
    </div>

    <div class="divide-y divide-gray-100">
        <?php foreach ($posts as $p):
            $hasData = !empty($p['linkedin_generated_at']);
            $isPosted = !empty($p['linkedin_marked_posted_at']);
        ?>
        <div class="px-5 py-3 hover:bg-gray-50">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <!-- Status pill -->
                <div style="flex: 0 0 96px; padding-top: 4px;">
                    <?php if ($isPosted): ?>
                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; background: #dcfce7; color: #15803d; border-radius: 4px;">
                            <i class="fa-solid fa-check"></i> POSTED
                        </span>
                    <?php elseif ($hasData): ?>
                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1d4ed8; border-radius: 4px;">
                            <i class="fa-solid fa-circle-dot"></i> READY
                        </span>
                    <?php else: ?>
                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #b45309; border-radius: 4px;">
                            <i class="fa-regular fa-clock"></i> WAITING
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Title + meta -->
                <div style="flex: 1 1 auto; min-width: 0;">
                    <h4 class="font-medium text-gray-900" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; <?= $isPosted ? 'opacity: 0.6;' : '' ?>">
                        <a href="https://cardify.om/blog/<?= htmlspecialchars($p['slug']) ?>" target="_blank" style="color: inherit; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= htmlspecialchars($p['title']) ?></a>
                    </h4>
                    <div class="text-xs text-gray-500" style="margin-top: 4px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                        <span><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars(date('Y-m-d', dbTs($p['published_at']))) ?></span>
                        <?php if ($hasData): ?>
                            <span><i class="fa-solid fa-wand-magic-sparkles" style="color: #3b82f6;"></i> Generated <?= htmlspecialchars(date('M j, H:i', dbTs($p['linkedin_generated_at']))) ?></span>
                        <?php endif; ?>
                        <?php if ($isPosted): ?>
                            <span style="color: #16a34a;"><i class="fa-solid fa-paper-plane"></i> Posted <?= htmlspecialchars(date('M j, H:i', dbTs($p['linkedin_marked_posted_at']))) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actions -->
                <div style="flex: 0 0 auto; display: flex; align-items: center; gap: 6px;">
                    <?php if (!$hasData): ?>
                        <form method="POST" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 bg-gray-900 text-white text-xs font-semibold rounded hover:bg-gray-800">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" onclick="toggleRow(<?= $p['id'] ?>)" class="px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs rounded" title="Show caption">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <a href="/admin/super/linkedin-carousel-download.php?id=<?= $p['id'] ?>" download class="px-2.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded" title="Download PDF">
                            <i class="fa-solid fa-file-arrow-down"></i>
                        </a>
                        <?php if (!$isPosted): ?>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="mark_posted">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="px-2.5 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs rounded" title="Mark posted">
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unmark_posted">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="px-2.5 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs rounded" title="Undo">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Regenerate this carousel?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="regenerate">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="px-2.5 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-700 text-xs rounded" title="Regenerate">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasData): ?>
            <!-- Expandable: caption preview -->
            <div id="row-<?= $p['id'] ?>" class="hidden mt-3 pl-24 pr-4 pb-2">
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-semibold uppercase tracking-wider text-gray-600">Caption</label>
                        <button type="button" onclick="copyToClipboard(this, 'cap-<?= $p['id'] ?>')" class="px-2 py-1 bg-white border border-gray-300 hover:bg-gray-100 text-xs rounded">
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                    </div>
                    <textarea id="cap-<?= $p['id'] ?>" readonly class="w-full h-28 text-sm font-mono border border-gray-200 rounded p-2 bg-white"><?= htmlspecialchars($p['linkedin_commentary'] ?? '') ?></textarea>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($posts)): ?>
        <div class="px-5 py-12 text-center text-gray-500">
            No carousels match this filter.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyHero() {
    const ta = document.getElementById('heroCaption');
    ta.select();
    document.execCommand('copy');
    const btn = document.getElementById('heroCopyBtn');
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy caption'; }, 1800);
}
function copyToClipboard(btn, taId) {
    const ta = document.getElementById(taId);
    ta.select();
    document.execCommand('copy');
    flashCopy(btn);
}
function copyTextDirectly(btn, text) {
    navigator.clipboard.writeText(text).then(() => flashCopy(btn));
}
function flashCopy(btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    setTimeout(() => { btn.innerHTML = orig; }, 1500);
}
function toggleRow(id) {
    document.getElementById('row-' + id).classList.toggle('hidden');
}
</script>

<?php adminFooter(); ?>
