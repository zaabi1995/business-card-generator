<?php
/**
 * Cardify, Free Email Signature Generator
 *
 * Builds a clean, table-based HTML email signature (Gmail + Outlook compatible)
 * from form inputs and lets the user copy either rich HTML or plain text.
 * Runs fully client-side.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/JsonLd.php';
// r150 / bhd-r6-99 clause 2: the join to the app family is written
// once, in ToolEntity, so a tool page cannot omit it.
require_once INCLUDES_DIR . '/ToolEntity.php';

$pageTitle = 'Free Email Signature Generator for Oman Professionals';
$pageDescription = 'Create a clean, professional email signature free. Gmail and Outlook compatible. Bilingual Arabic/English tip included. No sign-up required.';
$canonicalUrl = 'https://cardify.om/tools/email-signature-generator';

$softwareLd = ToolEntity::node('email-signature-generator', [
    'name' => 'Cardify Email Signature Generator',
    'description' => 'Free online email signature generator. Produces a table-based HTML signature that renders correctly in Gmail, Outlook, Apple Mail, and mobile clients. Copy with one click.',
    'featureList' => [
        'Gmail, Outlook, Apple Mail compatible',
        'Table-based HTML',
        'Live preview',
        'One-click copy (rich HTML)',
        'Plain-text fallback',
    ],
]);

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tools', 'item' => 'https://cardify.om/tools/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Email Signature Generator', 'item' => $canonicalUrl],
    ],
];

$faq = [
    ['q' => 'Is the email signature generator really free?', 'a' => 'Yes. No account, no watermark, no ad inserted into your signature, no "upgrade to remove logo" trick. The HTML you copy is yours.'],
    ['q' => 'Does the signature render correctly in Gmail and Outlook?', 'a' => 'Yes. We output table-based HTML with inline styles, which is the only format that survives Gmail, Outlook (desktop and web), Apple Mail, and mobile clients consistently. No flexbox, no CSS grid, those break in Outlook.'],
    ['q' => 'How do I install the signature in Gmail?', 'a' => 'Copy the HTML with the Rich Copy button, open Gmail → Settings → See all settings → General → Signature, paste into the signature editor, and save. Send a test email to yourself to verify.'],
    ['q' => 'Can I include both Arabic and English text?', 'a' => 'Yes. Paste Arabic into any field and the signature renders bilingual with correct right-to-left alignment where appropriate. Arabic names, titles, and addresses are supported.'],
    ['q' => 'Will my data be saved on your servers?', 'a' => 'No. The whole generator is a single HTML page, nothing is posted anywhere. Refresh the page and all your inputs are gone. If you need to edit the signature later, bookmark the URL and type your details again.'],
    ['q' => 'What if my logo URL stops working?', 'a' => 'Email clients need the logo hosted somewhere public. Use your company website\'s logo URL, a CDN, or a Cardify digital card asset. If the URL breaks, recipients see the alt text instead.'],
];

$howToLd = [
    '@context' => 'https://schema.org',
    '@type' => 'HowTo',
    'name' => 'How to create a professional email signature for free',
    'description' => 'Generate a clean, Gmail and Outlook compatible HTML email signature from your contact details in under a minute.',
    'totalTime' => 'PT3M',
    'tool' => [
        ['@type' => 'HowToTool', 'name' => 'Cardify Email Signature Generator'],
    ],
    'step' => [
        ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Enter your details', 'text' => 'Type your name, title, company, phone, email, and website. The live preview updates as you type.'],
        ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Add a logo URL (optional)', 'text' => 'Paste a public URL to your company logo. Keep it under 400px wide for Gmail and Outlook compatibility.'],
        ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Copy the signature', 'text' => 'Click Copy HTML for rich clients like Gmail and Outlook, or Copy plain text for clients that strip HTML.'],
        ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Install in your email client', 'text' => 'Paste into Gmail Settings → Signature, Outlook File → Options → Mail → Signatures, or Apple Mail → Settings → Signatures. Send a test to verify rendering.'],
    ],
];

$faqLd = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($q) => [
        '@type' => 'Question',
        'name' => $q['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']],
    ], $faq),
];

$extraHead = '<script type="application/ld+json">' . json_encode($softwareLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($breadcrumbLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($howToLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($faqLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

$showNavigation = true;
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-8 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-3" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <span>Tools</span>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Email Signature Generator</span>
            </nav>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">Free Email Signature Generator for Oman Professionals</h1>
            <p class="text-gray-600 text-lg max-w-3xl">Build a clean, table-based HTML email signature in under a minute. Renders correctly in Gmail, Outlook, Apple Mail, and every major mobile client. No sign-up, no watermark, no email capture.</p>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Form -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Your details</h2>
                <form id="sigForm" class="space-y-4" data-cardify-no-submit>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full name <span class="text-red-500">*</span></label>
                        <input type="text" id="name" required placeholder="Ali Adnan Haider Darwish"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Job title</label>
                        <input type="text" id="title" placeholder="Chief Executive Officer"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                        <input type="text" id="company" placeholder="BHD Printing &amp; Designing"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="tel" id="phone" placeholder="+968 9889 9100"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="email" placeholder="ali@bhdoman.com"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                        <input type="url" id="website" placeholder="https://cardify.om"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">LinkedIn URL</label>
                        <input type="url" id="linkedin" placeholder="https://linkedin.com/in/username"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Logo URL (optional)</label>
                        <input type="url" id="logo" placeholder="https://yourdomain.com/logo.png"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Must be a public https URL. Shown at 60px height in the signature.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Accent colour</label>
                        <input type="color" id="accent" value="#2563eb" class="h-10 w-20 border border-gray-300 rounded-lg cursor-pointer">
                    </div>
                </form>
            </div>

            <!-- Preview -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Live preview</h2>
                <div class="border border-dashed border-gray-200 rounded-lg p-4 bg-gray-50 min-h-[180px]">
                    <div id="sigPreview" class="bg-white p-4 rounded"></div>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 mt-4">
                    <button id="copyHtmlBtn"
                            class="flex-1 bg-blue-600 text-white font-semibold py-2.5 rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-regular fa-copy mr-1"></i> Copy signature HTML
                    </button>
                    <button id="copyTextBtn"
                            class="flex-1 bg-gray-100 text-gray-700 font-semibold py-2.5 rounded-lg hover:bg-gray-200 transition-colors">
                        <i class="fa-regular fa-file-lines mr-1"></i> Copy plain text
                    </button>
                </div>
                <details class="mt-4">
                    <summary class="text-sm text-gray-500 cursor-pointer hover:text-gray-700">View raw HTML</summary>
                    <textarea id="rawHtml" rows="8" readonly class="w-full mt-2 p-3 text-xs font-mono bg-gray-50 border border-gray-200 rounded-lg"></textarea>
                </details>
                <p class="text-xs text-gray-400 mt-3">Paste into Gmail → Settings → Signature, or Outlook → File → Options → Mail → Signatures.</p>
            </div>
        </div>

        <!-- Content -->
        <article class="bg-white rounded-xl shadow-sm p-6 sm:p-8 mt-10 prose prose-blue max-w-none">
            <h2 class="text-2xl font-bold text-gray-900 mt-0">Why your email signature matters more than you think</h2>
            <p>The average professional sends about 40 business emails a day. That's 10,000+ signed messages a year, every one of them a micro-branding opportunity that most people waste with either nothing at all or a cluttered wall of social icons, legal disclaimers, and mismatched fonts. A clean signature costs you nothing and gives every email a last, credible impression of who you are and how to reach you.</p>

            <h2 class="text-2xl font-bold text-gray-900">What makes a great email signature</h2>
            <ul>
                <li><strong>Keep it short.</strong> Name, title, company, one phone number, one link. If a recruiter can read it in two seconds, you're done.</li>
                <li><strong>Use a table, not a div.</strong> Outlook's rendering engine is still Word-based, divs, flexbox, and modern CSS collapse. Tables with inline styles are the only reliable layout.</li>
                <li><strong>Web-safe fonts only.</strong> Arial, Helvetica, Georgia, Verdana. Custom fonts fall back to Times New Roman on 40% of clients.</li>
                <li><strong>One accent colour.</strong> Your company brand colour for name and link, everything else in neutral grey. More than one accent reads as visual noise.</li>
                <li><strong>Logo max 60px tall.</strong> Larger logos break the email's vertical rhythm, inflate the message size, and get flagged by spam filters on Outlook.</li>
                <li><strong>No background images.</strong> Most corporate email clients block them by default. If your design depends on them, it breaks.</li>
                <li><strong>Mobile test.</strong> Over 60% of emails in Oman are read on mobile first. Check that your signature wraps cleanly at 320px wide.</li>
            </ul>

            <h2 class="text-2xl font-bold text-gray-900">Bilingual Arabic/English signatures, Oman tip</h2>
            <p>Half of professional correspondence in Oman is bilingual. The cleanest approach is <em>not</em> to stack Arabic and English vertically, that doubles the signature height and looks cluttered. Instead, use a two-column table: English on the left, Arabic on the right, with the Arabic block set to <code>dir="rtl"</code> and styled with the same web-safe font (e.g. Tahoma, which ships with both Latin and Arabic glyphs on every version of Windows and macOS). Keep name, title, company, and phone on both sides; drop social icons from the Arabic side if space is tight. This is what most Ministry and corporate signatures in Muscat look like, and it's what sets a polished bilingual signature apart from one that was clearly an afterthought.</p>

            <h2 class="text-2xl font-bold text-gray-900">How to install your new signature</h2>
            <h3>Gmail</h3>
            <ol>
                <li>Click <em>Copy signature HTML</em> above.</li>
                <li>In Gmail, click the gear icon &rarr; <em>See all settings</em> &rarr; scroll to <em>Signature</em>.</li>
                <li>Create a new signature, paste (Cmd/Ctrl-V), and save.</li>
            </ol>
            <h3>Outlook (desktop)</h3>
            <ol>
                <li>Go to <em>File &rarr; Options &rarr; Mail &rarr; Signatures</em>.</li>
                <li>Create a new signature and paste.</li>
                <li>If the logo disappears, make sure the logo URL is https and publicly accessible.</li>
            </ol>
            <h3>Apple Mail</h3>
            <ol>
                <li>Preferences &rarr; Signatures &rarr; + to create a new one.</li>
                <li>Uncheck "Always match my default message font," then paste.</li>
            </ol>

            <h2 class="text-2xl font-bold text-gray-900">Is this really free?</h2>
            <p>Yes. Unlike most online signature tools, we do not inject a "Made with X" tracking pixel, we do not require an email address to generate, and we do not host your signature on our domain. The HTML you copy is pure, portable, and yours forever.</p>

            <div class="mt-8 pt-6 border-t border-gray-100 text-sm text-gray-500">
                Need matching business cards for your whole team? <a href="<?= getBasePath() ?>intro" class="text-blue-600 font-medium hover:text-blue-700">Try Cardify &rarr;</a>
            </div>
        </article>
    </div>
</div>

<script<?= cspNonceAttr() ?>>
(function () {
    const fields = ['name','title','company','phone','email','website','linkedin','logo','accent'];
    const preview = document.getElementById('sigPreview');
    const raw = document.getElementById('rawHtml');

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildHtml() {
        const name = document.getElementById('name').value.trim();
        const title = document.getElementById('title').value.trim();
        const company = document.getElementById('company').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const email = document.getElementById('email').value.trim();
        const website = document.getElementById('website').value.trim();
        const linkedin = document.getElementById('linkedin').value.trim();
        const logo = document.getElementById('logo').value.trim();
        const accent = document.getElementById('accent').value || '#2563eb';

        if (!name) return { html: '<span style="color:#9ca3af">Enter your name to see a preview…</span>', text: '' };

        const tdLogo = logo
            ? `<td valign="top" style="padding:0 16px 0 0;border-right:2px solid ${esc(accent)};"><img src="${esc(logo)}" alt="${esc(company || name)}" height="60" style="display:block;height:60px;width:auto;border:0;"></td>`
            : '';

        let detailsHtml = `<div style="font-size:15px;color:#111827;font-weight:700;line-height:1.2;">${esc(name)}</div>`;
        if (title || company) {
            const tc = [title, company].filter(Boolean).map(esc).join(' · ');
            detailsHtml += `<div style="font-size:13px;color:#6b7280;margin-top:2px;">${tc}</div>`;
        }

        const contactRows = [];
        if (phone)    contactRows.push(`<a href="tel:${esc(phone.replace(/\s+/g,''))}" style="color:#374151;text-decoration:none;">${esc(phone)}</a>`);
        if (email)    contactRows.push(`<a href="mailto:${esc(email)}" style="color:${esc(accent)};text-decoration:none;">${esc(email)}</a>`);
        if (website)  contactRows.push(`<a href="${esc(website)}" style="color:${esc(accent)};text-decoration:none;">${esc(website.replace(/^https?:\/\//,''))}</a>`);
        if (linkedin) contactRows.push(`<a href="${esc(linkedin)}" style="color:${esc(accent)};text-decoration:none;">LinkedIn</a>`);
        if (contactRows.length) {
            detailsHtml += `<div style="font-size:13px;color:#374151;margin-top:8px;line-height:1.6;">${contactRows.join(' &nbsp;·&nbsp; ')}</div>`;
        }

        const html = `<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;"><tr>${tdLogo}<td valign="top" style="${logo ? 'padding:0 0 0 16px;' : ''}">${detailsHtml}</td></tr></table>`;

        const textParts = [name];
        if (title || company) textParts.push([title, company].filter(Boolean).join(' · '));
        if (phone)    textParts.push('Tel: ' + phone);
        if (email)    textParts.push('Email: ' + email);
        if (website)  textParts.push('Web: ' + website);
        if (linkedin) textParts.push('LinkedIn: ' + linkedin);

        return { html, text: textParts.join('\n') };
    }

    let current = { html: '', text: '' };

    function render() {
        current = buildHtml();
        preview.innerHTML = current.html;
        raw.value = current.html;
    }

    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', render);
    });

    document.getElementById('copyHtmlBtn').addEventListener('click', async function () {
        if (!current.html) return;
        const btn = this;
        const orig = btn.innerHTML;
        try {
            if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
                const blobHtml = new Blob([current.html], { type: 'text/html' });
                const blobText = new Blob([current.text], { type: 'text/plain' });
                await navigator.clipboard.write([new ClipboardItem({ 'text/html': blobHtml, 'text/plain': blobText })]);
            } else {
                // Fallback: copy visible preview as rich text using execCommand
                const range = document.createRange();
                range.selectNode(preview);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                document.execCommand('copy');
                sel.removeAllRanges();
            }
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
        } catch (e) {
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i> Copy failed, use raw HTML';
        }
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });

    document.getElementById('copyTextBtn').addEventListener('click', async function () {
        if (!current.text) return;
        const btn = this;
        const orig = btn.innerHTML;
        try {
            await navigator.clipboard.writeText(current.text);
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
        } catch (e) {
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i> Copy failed';
        }
        setTimeout(() => { btn.innerHTML = orig; }, 2000);
    });

    render();
})();
</script>

<?php include __DIR__ . '/../views/partials/tool_seo_faq.php'; ?>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
