<?php
/**
 * Cardify, Free WhatsApp QR Code Generator
 *
 * Builds an api.whatsapp.com/send click-to-chat URL from a phone number +
 * message, then encodes it into a QR code. Fully client-side.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/JsonLd.php';
// r150 / bhd-r6-99 clause 2: the join to the app family is written
// once, in ToolEntity, so a tool page cannot omit it.
require_once INCLUDES_DIR . '/ToolEntity.php';

$pageTitle = 'Free WhatsApp QR Code Generator';
$pageDescription = 'Generate a free WhatsApp QR code that opens a chat with a pre-filled message. Perfect for shop signs, menus, and business cards. No sign-up.';
$canonicalUrl = 'https://cardify.om/tools/whatsapp-qr-generator';

$softwareLd = ToolEntity::node('whatsapp-qr-generator', [
    'name' => 'Cardify WhatsApp QR Code Generator',
    'description' => 'Free online tool to create a WhatsApp QR code that opens a pre-filled chat. Includes Oman country code defaults and high-resolution PNG export.',
    'featureList' => [
        'api.whatsapp.com click-to-chat URL',
        'Pre-filled message support',
        'Country code picker (+968 Oman default)',
        'PNG download at 1024×1024',
        'No sign-up required',
    ],
]);

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tools', 'item' => 'https://cardify.om/tools/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'WhatsApp QR Code Generator', 'item' => $canonicalUrl],
    ],
];

$faq = [
    ['q' => 'How does a WhatsApp QR code work?', 'a' => 'Behind the scenes, the QR encodes an api.whatsapp.com/send URL, WhatsApp\'s official click-to-chat link format. When someone scans the QR with their phone camera, their browser opens the link and WhatsApp launches a chat with your number and any pre-filled message you set.'],
    ['q' => 'Do I need to be "WhatsApp Business" to use this?', 'a' => 'No. Click-to-chat works with both regular WhatsApp and WhatsApp Business. Either number type accepts incoming chats from scans.'],
    ['q' => 'What country code should I pick?', 'a' => 'Pick the country code matching your number. The tool defaults to +968 Oman, but there are picker entries for every GCC country. If a scanner is abroad, the country code is what makes sure the message reaches your number.'],
    ['q' => 'Can I pre-fill a message for the customer?', 'a' => 'Yes. The pre-filled message box lets you set any greeting, "Hi, I saw your menu at the restaurant" or "I\'d like to order 50 business cards", and the recipient only has to tap send. This dramatically increases reply rates for shop signs and menus.'],
    ['q' => 'Does this work if I change my phone number later?', 'a' => 'You would need to regenerate the QR with the new number and reprint. For signs and cards that may change, pair the QR with a short redirect URL you control (Cardify digital cards do this automatically).'],
    ['q' => 'Is the QR image free to use on commercial materials?', 'a' => 'Yes. Print it on shop windows, restaurant menus, delivery boxes, business cards, exhibition banners, anywhere. No license required, no Cardify watermark.'],
];

$howToLd = [
    '@context' => 'https://schema.org',
    '@type' => 'HowTo',
    'name' => 'How to create a WhatsApp QR code for click-to-chat',
    'description' => 'Generate a scannable QR code that opens a WhatsApp chat with your number and an optional pre-filled message.',
    'totalTime' => 'PT1M',
    'tool' => [
        ['@type' => 'HowToTool', 'name' => 'Cardify WhatsApp QR Code Generator'],
    ],
    'step' => [
        ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Pick your country code', 'text' => 'Select the country code for your WhatsApp number. Oman (+968) is the default; every GCC code is supported.'],
        ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Enter your number', 'text' => 'Type your WhatsApp number without spaces or dashes. Any number registered with regular or Business WhatsApp will work.'],
        ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Set a pre-filled message (optional)', 'text' => 'Add a greeting so customers don\'t have to type from a blank chat. "Hi, I saw your ad" or "I\'d like to order" works well.'],
        ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Download the PNG', 'text' => 'Click Download PNG to get a 1024×1024 image for print. Paste the click-to-chat URL on social bios or websites where a QR is not needed.'],
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
           . '<script type="application/ld+json">' . json_encode($faqLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script defer src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>';

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
                <span class="text-gray-700">WhatsApp QR Code Generator</span>
            </nav>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">Free WhatsApp QR Code Generator</h1>
            <p class="text-gray-600 text-lg max-w-3xl">Create a QR code that opens a WhatsApp chat to your number with a message already typed out. Perfect for business cards, shop signs, restaurant menus, and social media posts.</p>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Form -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Your WhatsApp number</h2>
                <form id="waForm" class="space-y-4" onsubmit="return false;">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Country <span class="text-red-500">*</span></label>
                        <select id="country" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="968" selected>Oman (+968)</option>
                            <option value="971">United Arab Emirates (+971)</option>
                            <option value="966">Saudi Arabia (+966)</option>
                            <option value="974">Qatar (+974)</option>
                            <option value="973">Bahrain (+973)</option>
                            <option value="965">Kuwait (+965)</option>
                            <option value="20">Egypt (+20)</option>
                            <option value="962">Jordan (+962)</option>
                            <option value="961">Lebanon (+961)</option>
                            <option value="91">India (+91)</option>
                            <option value="92">Pakistan (+92)</option>
                            <option value="880">Bangladesh (+880)</option>
                            <option value="63">Philippines (+63)</option>
                            <option value="44">United Kingdom (+44)</option>
                            <option value="1">United States (+1)</option>
                            <option value="49">Germany (+49)</option>
                            <option value="33">France (+33)</option>
                            <option value="90">Turkey (+90)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone number <span class="text-red-500">*</span></label>
                        <input type="tel" id="phone" required placeholder="98899100"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <p class="text-xs text-gray-400 mt-1">No leading zero. Numbers only. E.g. 98899100 for Oman.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Pre-filled message</label>
                        <textarea id="message" rows="3" placeholder="Hi, I saw your QR code and I'd like to know more about your services."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                        <p class="text-xs text-gray-400 mt-1">Shown in the chat input before they send. Leave blank for a normal chat.</p>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs text-gray-500 mb-1">Generated link:</p>
                        <code id="waUrl" class="text-xs text-gray-800 break-all block"></code>
                    </div>
                </form>
            </div>

            <!-- Preview -->
            <div class="bg-white rounded-xl shadow-sm p-6 flex flex-col items-center">
                <h2 class="text-xl font-semibold text-gray-900 mb-4 self-start">QR preview</h2>
                <div id="qrWrap" class="flex items-center justify-center w-72 h-72 bg-gray-50 rounded-xl border border-dashed border-gray-200 mb-4">
                    <canvas id="qrCanvas" class="max-w-full max-h-full"></canvas>
                    <p id="qrHint" class="text-gray-400 text-sm text-center px-4">Enter a phone number to generate your QR code.</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full">
                    <button id="downloadBtn" disabled
                            class="flex-1 bg-green-600 text-white font-semibold py-2.5 rounded-lg hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors">
                        <i class="fa-solid fa-download mr-1"></i> Download PNG
                    </button>
                    <button id="copyLinkBtn" disabled
                            class="flex-1 bg-gray-100 text-gray-700 font-semibold py-2.5 rounded-lg hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <i class="fa-regular fa-copy mr-1"></i> Copy link
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-3 text-center">PNG exported at 1024×1024. Test by scanning with your own phone before printing.</p>
            </div>
        </div>

        <!-- Content -->
        <article class="bg-white rounded-xl shadow-sm p-6 sm:p-8 mt-10 prose prose-blue max-w-none">
            <h2 class="text-2xl font-bold text-gray-900 mt-0">WhatsApp QR codes: the one-tap sales channel</h2>
            <p>WhatsApp is, quietly, the most important business tool in Oman. Over 95% of adults in the Sultanate use it daily, most small businesses list it as their primary contact method, and almost every purchase decision, from food delivery to a printing order, starts with a WhatsApp message. A WhatsApp QR code lets a customer go from "I'm curious" to an open chat with you in a single tap. No saving your number. No opening WhatsApp manually. No typing.</p>

            <h2 class="text-2xl font-bold text-gray-900">How the click-to-chat link works</h2>
            <p>The official WhatsApp click-to-chat format is <code>https://api.whatsapp.com/send?phone=&lt;country-code-and-number&gt;&amp;text=&lt;url-encoded-message&gt;</code>. When a user scans the QR or clicks the link, WhatsApp opens directly on the chat screen with your number loaded and the pre-filled message already in the text box, they just hit send. The number must be in international format with no plus sign, no zeroes, no spaces, no brackets. For an Oman number 98899100, the link is <code>https://api.whatsapp.com/send?phone=96898899100</code>. WhatsApp also publishes a short wa.me form, but the long host is the one we generate: it is the same official endpoint and it resolves reliably on Omani mobile networks where the short domain sometimes does not.</p>

            <h2 class="text-2xl font-bold text-gray-900">Seven ways Omani businesses are using WhatsApp QR codes</h2>
            <ul>
                <li><strong>Restaurant table tents.</strong> "Scan to order via WhatsApp", lower app-store friction than a dedicated ordering app, no commission to a platform.</li>
                <li><strong>Retail shop windows.</strong> After-hours customers scan the window sticker and send you a message about a product they spotted.</li>
                <li><strong>Service vans and signboards.</strong> Cleaning services, AC repair, car wash, a QR on the vehicle gets more calls than a painted phone number.</li>
                <li><strong>Invoice and quote PDFs.</strong> Add a QR at the bottom: "Questions? Scan to chat."</li>
                <li><strong>Real estate signs.</strong> Interested buyers scan from the street and the agent's pre-filled message includes the property reference automatically.</li>
                <li><strong>Print shop receipts.</strong> "Scan for urgent reprint requests", customers who lose the receipt still have the chat.</li>
                <li><strong>Event booths.</strong> Faster than collecting business cards. Attendees walk away with your WhatsApp already open.</li>
            </ul>

            <h2 class="text-2xl font-bold text-gray-900">Best-practice message templates</h2>
            <p>The pre-filled message should be short, identify where the scan came from, and give the customer a reason to hit send. Some formats that work in Oman:</p>
            <ul>
                <li><em>Hi, I'd like to order from your menu.</em> (restaurant)</li>
                <li><em>Hi, I'm interested in the [product] I saw at [shop name].</em> (retail)</li>
                <li><em>Hi, I need a quote for [service].</em> (service businesses)</li>
                <li><em>Hi, I'd like to book an appointment.</em> (salons, clinics)</li>
                <li><em>مرحبا، أود الاستفسار عن خدماتكم.</em> (generic Arabic)</li>
            </ul>

            <h2 class="text-2xl font-bold text-gray-900">Printing tips</h2>
            <p>For a standard business card (85×55mm), print the QR at 20×20mm minimum, any smaller and budget phone cameras struggle at arm's length. For a poster or sign viewed from 2+ metres, go at least 5×5cm. Always test-scan with your own phone at the distance customers will actually be, not on your desk right after you print the file.</p>

            <h2 class="text-2xl font-bold text-gray-900">Is this free?</h2>
            <p>Completely. We don't shorten your link through our domain, we don't track scans, and there's no "upgrade to remove watermark" pop-up. You get a raw, portable QR image and the underlying click-to-chat URL.</p>

            <div class="mt-8 pt-6 border-t border-gray-100 text-sm text-gray-500">
                Need business cards with QR codes for your whole team? <a href="<?= getBasePath() ?>intro" class="text-blue-600 font-medium hover:text-blue-700">Try Cardify &rarr;</a>
            </div>
        </article>
    </div>
</div>

<script>
(function () {
    const countryEl = document.getElementById('country');
    const phoneEl = document.getElementById('phone');
    const msgEl = document.getElementById('message');
    const qrCanvas = document.getElementById('qrCanvas');
    const qrHint = document.getElementById('qrHint');
    const downloadBtn = document.getElementById('downloadBtn');
    const copyLinkBtn = document.getElementById('copyLinkBtn');
    const waUrlEl = document.getElementById('waUrl');
    let currentUrl = '';

    function buildUrl() {
        const code = countryEl.value;
        const raw = phoneEl.value.replace(/\D/g, '').replace(/^0+/, '');
        if (!raw) return '';
        const msg = msgEl.value.trim();
        let url = 'https://api.whatsapp.com/send?phone=' + code + raw;
        if (msg) url += '?text=' + encodeURIComponent(msg);
        return url;
    }

    function render() {
        const url = buildUrl();
        currentUrl = url;
        waUrlEl.textContent = url || '(enter a phone number)';
        if (!url) {
            qrCanvas.style.display = 'none';
            qrHint.style.display = 'block';
            downloadBtn.disabled = true;
            copyLinkBtn.disabled = true;
            return;
        }
        if (typeof QRCode === 'undefined') {
            qrHint.textContent = 'Loading QR library…';
            return;
        }
        QRCode.toCanvas(qrCanvas, url, { width: 280, margin: 2, errorCorrectionLevel: 'M', color: { dark: '#25D366', light: '#ffffff' } }, function (err) {
            if (err) { console.error(err); return; }
            qrCanvas.style.display = 'block';
            qrHint.style.display = 'none';
            downloadBtn.disabled = false;
            copyLinkBtn.disabled = false;
        });
    }

    [countryEl, phoneEl, msgEl].forEach(el => el.addEventListener('input', render));
    countryEl.addEventListener('change', render);

    downloadBtn.addEventListener('click', function () {
        if (!currentUrl || typeof QRCode === 'undefined') return;
        QRCode.toDataURL(currentUrl, { width: 1024, margin: 2, errorCorrectionLevel: 'M', color: { dark: '#25D366', light: '#ffffff' } }, function (err, url) {
            if (err) { console.error(err); return; }
            const a = document.createElement('a');
            a.href = url;
            a.download = 'whatsapp-qr.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    });

    copyLinkBtn.addEventListener('click', async function () {
        if (!currentUrl) return;
        const btn = copyLinkBtn;
        const orig = btn.innerHTML;
        try {
            await navigator.clipboard.writeText(currentUrl);
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
        } catch (e) {
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i> Copy failed';
        }
        setTimeout(() => { btn.innerHTML = orig; }, 1800);
    });

    const checkReady = setInterval(function () {
        if (typeof QRCode !== 'undefined') {
            clearInterval(checkReady);
            render();
        }
    }, 100);
    render();
})();
</script>

<?php include __DIR__ . '/../views/partials/tool_seo_faq.php'; ?>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
