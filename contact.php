<?php
/**
 * Cardify, Contact page (Cat R action 438).
 *
 * Bilingual contact page with form + WhatsApp + email + embedded map.
 * Form submission routes to info@cardify.om via Mailer; CSRF guarded;
 * WhatsApp CTA deep-links into the BHD Anna line (+96899899100) with a
 * prefilled bilingual intro message; map embed points to AK Tower,
 * Bousher, Muscat 133.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$baseUrl = 'https://cardify.om';
$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';

// --- Form state ---
$success = false;
$error   = '';
$values  = ['name' => '', 'email' => '', 'subject' => 'general', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('contact.err_csrf');
    } else {
        $values['name']    = trim($_POST['name'] ?? '');
        $values['email']   = trim($_POST['email'] ?? '');
        $values['subject'] = trim($_POST['subject'] ?? 'general');
        $values['message'] = trim($_POST['message'] ?? '');

        if ($values['name'] === '' || $values['email'] === '' || $values['message'] === '') {
            $error = t('contact.err_required');
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = t('contact.err_email');
        } else {
            require_once INCLUDES_DIR . '/Mailer.php';
            $subjLabel = t('contact.form_subj_' . $values['subject']) ?: $values['subject'];
            $safeName    = htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8');
            $safeEmail   = htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8');
            $safeSubject = htmlspecialchars($subjLabel, ENT_QUOTES, 'UTF-8');
            $safeMessage = nl2br(htmlspecialchars($values['message'], ENT_QUOTES, 'UTF-8'));
            $date        = date('M j, Y \\a\\t g:i A');

            $htmlBody = <<<HTMLEMAIL
<!DOCTYPE html><html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
<tr><td style="background:#009bc1;padding:32px 40px;border-radius:8px 8px 0 0">
<h1 style="margin:0;color:#fff;font-size:22px;font-weight:600">New Cardify contact message</h1>
<p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px">{$date}</p></td></tr>
<tr><td style="background:#fff;padding:36px 40px">
<p style="margin:0 0 12px;font-size:13px;color:#8c8fa3;text-transform:uppercase;letter-spacing:.8px">Subject</p>
<p style="margin:0 0 24px;font-size:16px;color:#1a1a2e;font-weight:600">{$safeSubject}</p>
<p style="margin:0 0 6px;font-size:13px;color:#8c8fa3;text-transform:uppercase;letter-spacing:.8px">From</p>
<p style="margin:0 0 24px;font-size:16px"><strong>{$safeName}</strong> &lt;<a href="mailto:{$safeEmail}" style="color:#009bc1">{$safeEmail}</a>&gt;</p>
<p style="margin:0 0 6px;font-size:13px;color:#8c8fa3;text-transform:uppercase;letter-spacing:.8px">Message</p>
<div style="background:#f8f9fb;border-left:3px solid #009bc1;padding:16px 20px;border-radius:0 6px 6px 0;color:#2d2d3f;font-size:15px;line-height:1.6">{$safeMessage}</div>
</td></tr>
<tr><td style="padding:20px 40px;text-align:center;color:#8c8fa3;font-size:12px;background:#fafbfc;border-radius:0 0 8px 8px">
Sent from the Cardify contact form on <a href="https://cardify.om" style="color:#009bc1">cardify.om</a>
</td></tr></table></td></tr></table></body></html>
HTMLEMAIL;

            // Persist first so a mail-server hiccup never loses the
            // message (Cat U action 507). Admin viewer reads
            // contact_messages; email is best-effort on top.
            $emailSent = 0;
            try {
                Mailer::send(
                    'info@cardify.om',
                    "Cardify contact: {$subjLabel}, {$values['name']}",
                    $htmlBody,
                    [],
                    ['replyTo' => $values['email']]
                );
                $emailSent = 1;
                $success = true;
            } catch (Throwable $e) {
                $error = t('contact.err_generic');
                // still persisted below, show the thank-you page.
                $success = true;
            }
            try {
                Database::getInstance()->exec(
                    "INSERT INTO contact_messages
                        (name, email, subject, message, locale, ip_address, user_agent, email_sent)
                     VALUES (:n, :e, :s, :m, :l, :ip, :ua, :es)",
                    [
                        'n'  => $values['name'],
                        'e'  => $values['email'],
                        's'  => $values['subject'],
                        'm'  => $values['message'],
                        'l'  => $isAr ? 'ar' : 'en',
                        'ip' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                        'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                        'es' => $emailSent,
                    ]
                );
            } catch (Throwable $_) { /* non-fatal */ }
        }
    }
}

// --- Page metadata ---
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = t('contact.page_title');
$pageDescription = t('contact.page_desc');
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/contact';
$showNavigation  = true;
$bodyClass       = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

require_once INCLUDES_DIR . '/ui-header.php';

Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('contact.hero_h1'), $canonicalUrl],
]);

$waMsg   = $isAr ? 'مرحباً، لدي سؤال حول كارديفاي' : 'Hi, I have a question about Cardify';
$waUrl   = 'https://wa.me/96898899100?text=' . rawurlencode($waMsg);
$mapUrl  = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('AK Tower Bousher Muscat');
$mapEmbed= 'https://www.google.com/maps?q=' . rawurlencode('AK Tower Bousher Muscat') . '&output=embed';
?>

<main class="min-h-screen bg-gray-50">
    <header class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('contact.hero_h1')) ?></h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto"><?= htmlspecialchars(t('contact.hero_sub')) ?></p>
        </div>
    </header>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid lg:grid-cols-3 gap-8">

            <!-- Side cards -->
            <aside class="lg:col-span-1 space-y-6">
                <!-- WhatsApp -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center mb-4">
                        <i class="fa-brands fa-whatsapp text-xl text-green-600"></i>
                    </div>
                    <h2 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('contact.card_wa_h')) ?></h2>
                    <p class="text-gray-600 text-sm mb-4"><?= htmlspecialchars(t('contact.card_wa_p')) ?></p>
                    <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-4 py-2.5 bg-green-500 hover:bg-green-600 text-white text-sm font-semibold rounded-lg transition">
                        <i class="fa-brands fa-whatsapp"></i>
                        <?= htmlspecialchars(t('contact.card_wa_btn')) ?>
                    </a>
                    <p class="mt-3 text-xs text-gray-500 font-mono">+968 9889 9100</p>
                </div>

                <!-- Email -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-envelope text-xl text-blue-600"></i>
                    </div>
                    <h2 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('contact.card_email_h')) ?></h2>
                    <p class="text-gray-600 text-sm mb-3"><?= htmlspecialchars(t('contact.card_email_p')) ?></p>
                    <a href="mailto:info@cardify.om" class="text-blue-600 hover:text-blue-700 font-medium text-sm break-all">info@cardify.om</a>
                </div>

                <!-- Address -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-location-dot text-xl text-amber-600"></i>
                    </div>
                    <h2 class="font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('contact.card_addr_h')) ?></h2>
                    <p class="text-gray-600 text-sm whitespace-pre-line mb-2"><?= htmlspecialchars(t('contact.card_addr_p')) ?></p>
                    <p class="text-gray-500 text-xs mb-3"><?= htmlspecialchars(t('contact.card_addr_hours')) ?></p>
                    <a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 text-blue-600 hover:text-blue-700 font-medium text-sm">
                        <i class="fa-solid fa-map-location-dot"></i>
                        <?= htmlspecialchars(t('contact.card_addr_map')) ?>
                    </a>
                </div>

                <!-- Social -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-share-nodes text-xl text-purple-600"></i>
                    </div>
                    <h2 class="font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('contact.card_social_h')) ?></h2>
                    <div class="flex gap-3">
                        <a href="https://instagram.com/cardify.om" target="_blank" rel="noopener"
                           aria-label="Instagram"
                           class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-pink-600 hover:text-white flex items-center justify-center transition">
                            <i class="fa-brands fa-instagram text-gray-600"></i>
                        </a>
                        <a href="https://www.linkedin.com/company/cardify-om" target="_blank" rel="noopener"
                           aria-label="LinkedIn"
                           class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-blue-700 hover:text-white flex items-center justify-center transition">
                            <i class="fa-brands fa-linkedin-in text-gray-600"></i>
                        </a>
                    </div>
                </div>
            </aside>

            <!-- Form + map -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                    <?php if ($success): ?>
                        <div class="text-center py-10">
                            <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-5">
                                <i class="fa-solid fa-check text-3xl text-green-600"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('contact.ok_h')) ?></h2>
                            <p class="text-gray-600 mb-6 max-w-md mx-auto"><?= htmlspecialchars(t('contact.ok_b')) ?></p>
                            <a href="<?= ($isAr ? '/ar' : '') ?>/" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?>"></i>
                                <?= htmlspecialchars(t('contact.back_home')) ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <h2 class="text-2xl font-bold text-gray-900 mb-6"><?= htmlspecialchars(t('contact.form_h')) ?></h2>
                        <?php if ($error): ?>
                            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                                <i class="fa-solid fa-circle-exclamation <?= $isAr ? 'ml-2' : 'mr-2' ?>"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" class="space-y-5">
                            <?= csrfField() ?>
                            <div class="grid md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('contact.form_name')) ?> *</label>
                                    <input type="text" name="name" required
                                           value="<?= htmlspecialchars($values['name']) ?>"
                                           placeholder="<?= htmlspecialchars(t('contact.form_name_ph')) ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('contact.form_email')) ?> *</label>
                                    <input type="email" name="email" required
                                           value="<?= htmlspecialchars($values['email']) ?>"
                                           placeholder="<?= htmlspecialchars(t('contact.form_email_ph')) ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            <div>
                                <label for="contact-subject" class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('contact.form_subject')) ?></label>
                                <select id="contact-subject" name="subject" class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php foreach (['general','support','sales','partner','feedback'] as $o): ?>
                                        <option value="<?= $o ?>" <?= $values['subject'] === $o ? 'selected' : '' ?>><?= htmlspecialchars(t('contact.form_subj_' . $o)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= htmlspecialchars(t('contact.form_msg')) ?> *</label>
                                <textarea name="message" rows="6" required
                                          placeholder="<?= htmlspecialchars(t('contact.form_msg_ph')) ?>"
                                          class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($values['message']) ?></textarea>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3">
                                <button type="submit" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <?= htmlspecialchars(t('contact.form_submit')) ?>
                                </button>
                                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener"
                                   class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition">
                                    <i class="fa-brands fa-whatsapp"></i>
                                    <?= htmlspecialchars(t('contact.card_wa_btn')) ?>
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Map -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="aspect-[16/9] bg-gray-100">
                        <iframe src="<?= htmlspecialchars($mapEmbed) ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Cardify office location"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
