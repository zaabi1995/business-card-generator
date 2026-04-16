<?php
/**
 * Cardify — Free vCard QR Code Generator
 *
 * Pure client-side tool that builds a vCard 3.0 payload from form inputs
 * and encodes it into a QR code using qrcode.js. No server-side processing,
 * no data leaves the browser.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Free vCard QR Code Generator for Business Cards';
$pageDescription = 'Generate a free vCard QR code for your business card. Scan to save contact details instantly. Works on iPhone, Android, and any QR scanner.';
$canonicalUrl = 'https://cardify.om/tools/vcard-qr-generator';

$softwareLd = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'Cardify vCard QR Code Generator',
    'description' => 'Free online tool to generate a vCard QR code from your contact details. Download as PNG and print on business cards, email signatures, or office signage.',
    'url' => $canonicalUrl,
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web',
    'browserRequirements' => 'Requires JavaScript. Works in Chrome, Safari, Firefox, Edge.',
    'offers' => [
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'OMR',
    ],
    'creator' => [
        '@type' => 'Organization',
        'name' => 'Cardify',
        'url' => 'https://cardify.om/',
    ],
    'featureList' => [
        'vCard 3.0 export',
        'Real-time QR preview',
        'PNG download at 1024×1024',
        'Copy raw vCard text',
        'No sign-up required',
    ],
];

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tools', 'item' => 'https://cardify.om/tools/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'vCard QR Code Generator', 'item' => $canonicalUrl],
    ],
];

$extraHead = '<script type="application/ld+json">' . json_encode($softwareLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
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
                <span class="text-gray-700">vCard QR Code Generator</span>
            </nav>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">Free vCard QR Code Generator for Business Cards</h1>
            <p class="text-gray-600 text-lg max-w-3xl">Generate a scannable vCard QR code from your contact details. Print it on your business card so people can save you to their phone with one tap. 100% free, no sign-up, everything runs in your browser.</p>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Form -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Your details</h2>
                <form id="vcardForm" class="space-y-4" onsubmit="return false;">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full name <span class="text-red-500">*</span></label>
                        <input type="text" id="fullName" required placeholder="Ali Al-Zaabi"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Job title</label>
                        <input type="text" id="jobTitle" placeholder="CEO"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                        <input type="text" id="company" placeholder="BHD Printing & Designing"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone <span class="text-red-500">*</span></label>
                        <input type="tel" id="phone" required placeholder="+968 9889 9100"
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="address" rows="2" placeholder="P.O. Box 2237, PC 133, Muscat, Oman"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                </form>
            </div>

            <!-- QR preview -->
            <div class="bg-white rounded-xl shadow-sm p-6 flex flex-col items-center">
                <h2 class="text-xl font-semibold text-gray-900 mb-4 self-start">QR preview</h2>
                <div id="qrWrap" class="flex items-center justify-center w-72 h-72 bg-gray-50 rounded-xl border border-dashed border-gray-200 mb-4">
                    <canvas id="qrCanvas" class="max-w-full max-h-full"></canvas>
                    <p id="qrHint" class="text-gray-400 text-sm text-center px-4">Fill in name + phone to generate your QR code.</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full">
                    <button id="downloadBtn" disabled
                            class="flex-1 bg-blue-600 text-white font-semibold py-2.5 rounded-lg hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors">
                        <i class="fa-solid fa-download mr-1"></i> Download PNG
                    </button>
                    <button id="copyBtn" disabled
                            class="flex-1 bg-gray-100 text-gray-700 font-semibold py-2.5 rounded-lg hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <i class="fa-regular fa-copy mr-1"></i> Copy vCard text
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-3 text-center">PNG exported at 1024×1024. Nothing is uploaded — this all runs locally in your browser.</p>
            </div>
        </div>

        <!-- Content -->
        <article class="bg-white rounded-xl shadow-sm p-6 sm:p-8 mt-10 prose prose-blue max-w-none">
            <h2 class="text-2xl font-bold text-gray-900 mt-0">What is a vCard QR code?</h2>
            <p>A vCard is the universal file format for digital contact cards — the same standard Apple, Google, Microsoft, and every major address book app have used since 1996. When you encode a vCard inside a QR code, anyone who scans it with their phone camera gets a "Save contact" prompt with your name, phone, email, company, and address already filled in. No typing, no photos of a paper card that get lost in the camera roll, no risk of a misspelled email.</p>

            <h2 class="text-2xl font-bold text-gray-900">Why businesses in Oman are adding QR codes to their cards</h2>
            <p>Printed business cards still dominate networking in Muscat, Salalah, Sohar, and Nizwa — but the follow-up is broken. Roughly half the cards exchanged at a conference end up in a drawer and never make it into the recipient's phone. A QR code fixes that in two seconds:</p>
            <ul>
                <li><strong>Instant save.</strong> iPhone (iOS 11+) and Android (9+) both scan QR natively from the camera app — no third-party scanner required.</li>
                <li><strong>Zero errors.</strong> The contact is saved exactly as you typed it. No autocorrect mangling your name.</li>
                <li><strong>Bilingual friendly.</strong> vCard 3.0 is UTF-8, so Arabic names and addresses save correctly alongside the English version.</li>
                <li><strong>Update-proof for the physical card.</strong> Pair the QR with a short link (like cardify.om/yourname) and you can change your phone or title without reprinting cards.</li>
            </ul>

            <h2 class="text-2xl font-bold text-gray-900">How to use this free tool</h2>
            <ol>
                <li>Type your name and phone number — those are the only required fields.</li>
                <li>Add title, company, email, website, and address if you want a richer contact card.</li>
                <li>Watch the QR code update in real time as you type.</li>
                <li>Click <em>Download PNG</em> to get a 1024×1024 pixel image, large enough to print crisp on any business card, flyer, or office door sign.</li>
                <li>Or click <em>Copy vCard text</em> to grab the raw .vcf payload for your own CRM or email signature.</li>
            </ol>

            <h2 class="text-2xl font-bold text-gray-900">Where to use your vCard QR code</h2>
            <p>Back of your business card. Bottom of your email signature. Your office door. Your office WhatsApp status. Your conference lanyard. Your trade show booth banner. Your exhibition giveaway. Anywhere a stranger might want to save your contact — and in a country where most business happens through WhatsApp introductions, that is nearly everywhere.</p>

            <h2 class="text-2xl font-bold text-gray-900">Is this tool really free?</h2>
            <p>Yes. No watermark, no account, no email capture, no "upgrade to remove the logo." The QR image is yours to use commercially. We built this as a free gift to Oman's professional community — and if you like it enough to want business cards for your whole team, we hope you'll consider Cardify below.</p>

            <div class="mt-8 pt-6 border-t border-gray-100 text-sm text-gray-500">
                Need business cards for your whole team? <a href="<?= getBasePath() ?>intro" class="text-blue-600 font-medium hover:text-blue-700">Try Cardify &rarr;</a>
            </div>
        </article>
    </div>
</div>

<script>
(function () {
    const fields = ['fullName','jobTitle','company','phone','email','website','address'];
    const qrCanvas = document.getElementById('qrCanvas');
    const qrHint = document.getElementById('qrHint');
    const downloadBtn = document.getElementById('downloadBtn');
    const copyBtn = document.getElementById('copyBtn');
    let currentVCard = '';

    function escapeVCardValue(v) {
        if (!v) return '';
        return String(v).replace(/\\/g, '\\\\').replace(/\n/g, '\\n').replace(/,/g, '\\,').replace(/;/g, '\\;');
    }

    function buildVCard() {
        const name = document.getElementById('fullName').value.trim();
        const title = document.getElementById('jobTitle').value.trim();
        const company = document.getElementById('company').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const email = document.getElementById('email').value.trim();
        const website = document.getElementById('website').value.trim();
        const address = document.getElementById('address').value.trim();

        if (!name || !phone) return '';

        const nameParts = name.split(/\s+/);
        const firstName = nameParts[0] || '';
        const lastName = nameParts.slice(1).join(' ');

        const lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:' + escapeVCardValue(lastName) + ';' + escapeVCardValue(firstName) + ';;;',
            'FN:' + escapeVCardValue(name),
        ];
        if (title)   lines.push('TITLE:' + escapeVCardValue(title));
        if (company) lines.push('ORG:' + escapeVCardValue(company));
        if (phone)   lines.push('TEL;TYPE=CELL:' + escapeVCardValue(phone));
        if (email)   lines.push('EMAIL;TYPE=INTERNET:' + escapeVCardValue(email));
        if (website) lines.push('URL:' + escapeVCardValue(website));
        if (address) lines.push('ADR;TYPE=WORK:;;' + escapeVCardValue(address) + ';;;;');
        lines.push('END:VCARD');
        return lines.join('\r\n');
    }

    function render() {
        const vcard = buildVCard();
        currentVCard = vcard;
        if (!vcard) {
            qrCanvas.style.display = 'none';
            qrHint.style.display = 'block';
            downloadBtn.disabled = true;
            copyBtn.disabled = true;
            return;
        }
        if (typeof QRCode === 'undefined') {
            qrHint.textContent = 'Loading QR library…';
            return;
        }
        QRCode.toCanvas(qrCanvas, vcard, { width: 280, margin: 2, errorCorrectionLevel: 'M' }, function (err) {
            if (err) { console.error(err); return; }
            qrCanvas.style.display = 'block';
            qrHint.style.display = 'none';
            downloadBtn.disabled = false;
            copyBtn.disabled = false;
        });
    }

    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', render);
    });

    downloadBtn.addEventListener('click', function () {
        if (!currentVCard || typeof QRCode === 'undefined') return;
        QRCode.toDataURL(currentVCard, { width: 1024, margin: 2, errorCorrectionLevel: 'M' }, function (err, url) {
            if (err) { console.error(err); return; }
            const a = document.createElement('a');
            a.href = url;
            const name = (document.getElementById('fullName').value.trim() || 'vcard').replace(/\s+/g, '-').toLowerCase();
            a.download = name + '-qr.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    });

    copyBtn.addEventListener('click', async function () {
        if (!currentVCard) return;
        try {
            await navigator.clipboard.writeText(currentVCard);
            const orig = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
            setTimeout(() => { copyBtn.innerHTML = orig; }, 1800);
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = currentVCard;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (_) {}
            document.body.removeChild(ta);
        }
    });

    // Render once library loads
    const checkReady = setInterval(function () {
        if (typeof QRCode !== 'undefined') {
            clearInterval(checkReady);
            render();
        }
    }, 100);
})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
