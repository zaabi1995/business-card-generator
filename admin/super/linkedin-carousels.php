<?php
/**
 * Super Admin — LinkedIn Carousels
 * View, generate, and manually post the daily LinkedIn carousels.
 * The cron auto-generates the next due post at 9 AM. Ali manually posts to
 * the Cardify company page; this UI gives him copy buttons + PDF download
 * + "mark as posted" tracking.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle POST actions
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
            $commentary = $slides['hook_en'] . "\n\n" . $slides['hook_ar']
                        . "\n\nSwipe through, or read the full post: " . $blogUrl
                        . "\n\n#Cardify #Oman #DigitalBusinessCards #Branding";
            $relPdf = str_replace(BASE_DIR . '/', '', $pdfPath);
            $db->update('blog_posts', [
                'linkedin_carousel_pdf' => $relPdf,
                'linkedin_carousel_data' => json_encode($slides, JSON_UNESCAPED_UNICODE),
                'linkedin_commentary' => $commentary,
                'linkedin_generated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            $message = 'Carousel generated successfully'; $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Generation failed: ' . $e->getMessage(); $messageType = 'error';
        }
    } elseif ($action === 'mark_posted') {
        $db->update('blog_posts', ['linkedin_marked_posted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $message = 'Marked as posted'; $messageType = 'success';
    } elseif ($action === 'unmark_posted') {
        $db->update('blog_posts', ['linkedin_marked_posted_at' => null], 'id = :id', ['id' => $id]);
        $message = 'Unmarked'; $messageType = 'success';
    } elseif ($action === 'regenerate') {
        // Clear existing data first, then regenerate
        $db->update('blog_posts', [
            'linkedin_carousel_pdf' => null,
            'linkedin_carousel_data' => null,
            'linkedin_commentary' => null,
            'linkedin_generated_at' => null,
        ], 'id = :id', ['id' => $id]);
        // Redirect to GET to avoid double-submit; user clicks Generate again
        header('Location: /admin/super/linkedin-carousels.php?cleared=' . $id); exit;
    }
}

if (isset($_GET['cleared'])) {
    $message = 'Carousel data cleared. Click Generate to recreate.';
    $messageType = 'success';
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'pending') {
    $where = 'linkedin_generated_at IS NULL AND DATE(published_at) <= CURDATE()';
} elseif ($filter === 'ready') {
    $where = 'linkedin_generated_at IS NOT NULL AND linkedin_marked_posted_at IS NULL';
} elseif ($filter === 'posted') {
    $where = 'linkedin_marked_posted_at IS NOT NULL';
} elseif ($filter === 'today') {
    $where = "DATE(linkedin_generated_at) = CURDATE() OR DATE(published_at) = CURDATE()";
}

$posts = $db->fetchAll(
    "SELECT id, title, slug, status, published_at, featured_image,
            linkedin_carousel_pdf, linkedin_carousel_data, linkedin_commentary,
            linkedin_generated_at, linkedin_marked_posted_at
     FROM blog_posts
     WHERE $where
     ORDER BY published_at DESC
     LIMIT 200"
);

$counts = $db->fetchOne("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN linkedin_marked_posted_at IS NOT NULL THEN 1 ELSE 0 END) AS posted,
    SUM(CASE WHEN linkedin_generated_at IS NOT NULL AND linkedin_marked_posted_at IS NULL THEN 1 ELSE 0 END) AS ready,
    SUM(CASE WHEN linkedin_generated_at IS NULL AND DATE(published_at) <= CURDATE() THEN 1 ELSE 0 END) AS pending
    FROM blog_posts");

adminHeader('LinkedIn Carousels', 'content');
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fa-brands fa-linkedin text-[#0a66c2]"></i> LinkedIn Carousels
            </h1>
            <p class="text-sm text-gray-600 mt-1">
                Cron auto-generates the next due carousel at <strong>9 AM Oman time</strong> daily.
                Copy the commentary + download the PDF, then post manually to the Cardify company page.
            </p>
        </div>
        <div class="grid grid-cols-4 gap-3 text-center">
            <a href="?filter=all" class="px-4 py-2 rounded-lg <?= $filter==='all'?'bg-gray-900 text-white':'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['total'] ?></div><div class="text-xs">Total</div>
            </a>
            <a href="?filter=pending" class="px-4 py-2 rounded-lg <?= $filter==='pending'?'bg-amber-500 text-white':'bg-amber-50 text-amber-700 hover:bg-amber-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['pending'] ?></div><div class="text-xs">Pending</div>
            </a>
            <a href="?filter=ready" class="px-4 py-2 rounded-lg <?= $filter==='ready'?'bg-blue-600 text-white':'bg-blue-50 text-blue-700 hover:bg-blue-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['ready'] ?></div><div class="text-xs">Ready to post</div>
            </a>
            <a href="?filter=posted" class="px-4 py-2 rounded-lg <?= $filter==='posted'?'bg-green-600 text-white':'bg-green-50 text-green-700 hover:bg-green-100' ?>">
                <div class="text-xl font-bold"><?= (int)$counts['posted'] ?></div><div class="text-xs">Posted</div>
            </a>
        </div>
    </div>
</div>

<div class="space-y-4">
    <?php foreach ($posts as $p): ?>
        <?php
            $hasData = !empty($p['linkedin_generated_at']);
            $isPosted = !empty($p['linkedin_marked_posted_at']);
            $slides = $hasData && $p['linkedin_carousel_data'] ? json_decode($p['linkedin_carousel_data'], true) : null;
            $pdfUrl = $p['linkedin_carousel_pdf'] ? '/admin/super/linkedin-carousel-download.php?id=' . $p['id'] : '';
            $statusBadge = $isPosted ? '<span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded">POSTED</span>'
                            : ($hasData ? '<span class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded">READY</span>'
                            : '<span class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded">NOT GENERATED</span>');
        ?>
        <div class="bg-white rounded-lg shadow overflow-hidden <?= $isPosted ? 'opacity-70' : '' ?>">
            <div class="p-4 flex items-start justify-between gap-4 border-b border-gray-100">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <?= $statusBadge ?>
                        <span class="text-xs text-gray-500">Scheduled: <?= htmlspecialchars(date('Y-m-d', strtotime($p['published_at']))) ?></span>
                        <?php if ($hasData): ?>
                            <span class="text-xs text-gray-500">Generated: <?= htmlspecialchars(date('M j, H:i', strtotime($p['linkedin_generated_at']))) ?></span>
                        <?php endif; ?>
                        <?php if ($isPosted): ?>
                            <span class="text-xs text-green-600">Posted: <?= htmlspecialchars(date('M j, H:i', strtotime($p['linkedin_marked_posted_at']))) ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900 truncate">
                        <a href="https://cardify.om/blog/<?= htmlspecialchars($p['slug']) ?>" target="_blank" class="hover:underline"><?= htmlspecialchars($p['title']) ?></a>
                    </h2>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <?php if (!$hasData): ?>
                        <form method="POST" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="px-3 py-2 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?= $pdfUrl ?>" download class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                            <i class="fa-solid fa-file-pdf"></i> Download PDF
                        </a>
                        <?php if (!$isPosted): ?>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="mark_posted">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="px-3 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                                    <i class="fa-solid fa-check"></i> Mark posted
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="unmark_posted">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="px-3 py-2 bg-gray-200 text-gray-700 text-sm rounded hover:bg-gray-300" title="Undo posted">
                                    <i class="fa-solid fa-rotate-left"></i> Undo
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Clear and regenerate? Old PDF + copy will be replaced.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="regenerate">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="px-3 py-2 bg-amber-100 text-amber-700 text-sm rounded hover:bg-amber-200" title="Regenerate">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($slides): ?>
            <div class="p-4 bg-gray-50">
                <div class="grid md:grid-cols-2 gap-4">
                    <!-- Commentary block -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold text-gray-700">Full commentary (LinkedIn caption)</h3>
                            <button type="button" onclick="copyToClipboard(this, 'comm-<?= $p['id'] ?>')" class="text-xs px-2 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100">
                                <i class="fa-solid fa-copy"></i> Copy
                            </button>
                        </div>
                        <textarea id="comm-<?= $p['id'] ?>" readonly class="w-full h-40 text-sm border border-gray-200 rounded p-2 font-mono bg-white"><?= htmlspecialchars($p['linkedin_commentary'] ?? '') ?></textarea>
                    </div>

                    <!-- Slide-by-slide breakdown -->
                    <div class="space-y-2">
                        <h3 class="text-sm font-semibold text-gray-700">Per-slide copy</h3>
                        <?php
                        $items = [
                            'Hook EN' => $slides['hook_en'] ?? '',
                            'Hook AR' => $slides['hook_ar'] ?? '',
                            'Tension' => $slides['tension'] ?? '',
                            'Point 1' => ($slides['points'][0]['number'] ?? '') . ' — ' . ($slides['points'][0]['text'] ?? ''),
                            'Point 2' => ($slides['points'][1]['number'] ?? '') . ' — ' . ($slides['points'][1]['text'] ?? ''),
                            'Point 3' => ($slides['points'][2]['number'] ?? '') . ' — ' . ($slides['points'][2]['text'] ?? ''),
                            'Takeaway EN' => $slides['takeaway_en'] ?? '',
                            'Takeaway AR' => $slides['takeaway_ar'] ?? '',
                            'CTA EN' => $slides['cta_en'] ?? '',
                            'CTA AR' => $slides['cta_ar'] ?? '',
                        ];
                        foreach ($items as $label => $val):
                            $isAr = strpos($label, 'AR') !== false;
                            $eid = 'el-' . $p['id'] . '-' . md5($label);
                        ?>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="w-20 flex-shrink-0 text-gray-500"><?= $label ?>:</span>
                            <span id="<?= $eid ?>" class="flex-1 truncate <?= $isAr ? 'text-right' : '' ?>" <?= $isAr ? 'dir="rtl"' : '' ?>><?= htmlspecialchars($val) ?></span>
                            <button type="button" onclick="copyTextDirectly(this, <?= json_encode($val, JSON_HEX_QUOT|JSON_HEX_APOS|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>)" class="px-1.5 py-0.5 bg-white border border-gray-300 rounded hover:bg-gray-100 text-xs">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($posts)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
        No posts match this filter.
    </div>
    <?php endif; ?>
</div>

<script>
function copyToClipboard(btn, textareaId) {
    const ta = document.getElementById(textareaId);
    ta.select();
    document.execCommand('copy');
    flashCopy(btn);
}
function copyTextDirectly(btn, text) {
    navigator.clipboard.writeText(text).then(() => flashCopy(btn));
}
function flashCopy(btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
    btn.classList.add('bg-green-100');
    setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('bg-green-100'); }, 1500);
}
</script>

<?php adminFooter(); ?>
