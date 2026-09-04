<?php
/**
 * Cardify, NFC Business Cards Guide (content page, no JS tool)
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'NFC Business Cards Guide: How They Work and Setup in Oman';
$pageDescription = 'Complete guide to NFC business cards in Oman, how they work, iPhone vs Android compatibility, setup steps, NFC vs QR vs traditional cards, and where to buy.';
$canonicalUrl = 'https://cardify.om/tools/nfc-business-card-guide';

$howToLd = [
    '@context' => 'https://schema.org',
    '@type' => 'HowTo',
    'name' => 'How to set up an NFC business card',
    'description' => 'Step-by-step guide to configuring an NFC business card so it opens your contact profile when tapped on any modern smartphone.',
    'totalTime' => 'PT10M',
    'tool' => [
        ['@type' => 'HowToTool', 'name' => 'NFC-enabled business card (NTAG215 or NTAG216 chip)'],
        ['@type' => 'HowToTool', 'name' => 'Smartphone with NFC (iPhone 7 or later, most Android 5+)'],
        ['@type' => 'HowToTool', 'name' => 'NFC writer app (e.g. NFC Tools by wakdev)'],
    ],
    'step' => [
        [
            '@type' => 'HowToStep',
            'position' => 1,
            'name' => 'Choose your destination URL',
            'text' => 'Decide what the card should open when tapped, your Cardify digital card, your LinkedIn profile, your website, or a vCard download link. A public URL is the most reliable option.',
        ],
        [
            '@type' => 'HowToStep',
            'position' => 2,
            'name' => 'Install an NFC writer app',
            'text' => 'Download NFC Tools (free, iOS and Android) or any equivalent NFC writer from the App Store or Google Play.',
        ],
        [
            '@type' => 'HowToStep',
            'position' => 3,
            'name' => 'Write the URL to the card',
            'text' => 'Open the app, tap "Write", choose "URL/URI", paste your destination link, and hold the NFC card against the back of your phone until the app confirms the write was successful.',
        ],
        [
            '@type' => 'HowToStep',
            'position' => 4,
            'name' => 'Lock the card (optional but recommended)',
            'text' => 'In the NFC writer app, use the "Lock tag" function to make the card read-only. This prevents accidental rewrites or tampering by someone who has physical access to the card.',
        ],
        [
            '@type' => 'HowToStep',
            'position' => 5,
            'name' => 'Test on both iPhone and Android',
            'text' => 'Tap the card against an iPhone (unlocked, screen on) and an Android phone. On iPhone you will see a notification banner; tap it to open the URL. On Android the URL opens automatically in most cases.',
        ],
    ],
];

$faqLd = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        [
            '@type' => 'Question',
            'name' => 'Do I need an app to use an NFC business card?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'No. Both iPhone (7 and newer, running iOS 14+) and nearly all Android phones (5.0+) read NFC URLs natively. The recipient just taps the card against their phone, no app install required.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'Does NFC work on iPhone?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'Yes. Since iPhone XS (iOS 13+), NFC tag reading is always on in the background whenever the screen is unlocked. iPhone 7 and 8 users need to be on iOS 14+ and open the Control Center NFC reader manually. On all modern iPhones, tapping an NFC business card pops up a notification banner that opens the URL when tapped.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'How is NFC different from a QR code?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'A QR code is scanned by the phone camera, the user must open the camera app, point at the code, and tap a notification. NFC requires physical contact (under 4cm) but needs zero user action beyond touching the card to the phone. NFC feels faster and more magical; QR is cheaper to print and works on older phones.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'Can I change what my NFC card links to after I print it?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'Only if the card is unlocked. If you point the NFC card at a short URL like cardify.om/ali, you can update the profile behind that URL any time without rewriting the card. If you write a raw vCard or a hard-coded URL directly to the chip, changing it requires either rewriting (if unlocked) or reprinting.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'Where can I buy NFC business cards in Oman?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'NFC cards are not yet sold in most Omani print shops. The most common options are: ordering online from regional suppliers in the UAE who ship to Oman within 3-5 days, importing direct from AliExpress or Amazon (1-3 week delivery), or working with a local agency like BHD Printing that can source and program cards in bulk.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'How much does an NFC business card cost?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'Blank PVC NFC cards cost around 1-2 OMR each for small quantities, dropping to 0.400-0.700 OMR in bulk (100+). Custom-printed NFC cards from regional suppliers typically run 3-8 OMR per card depending on finish. A metal NFC card can cost 15-40 OMR.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'Do NFC cards stop working after a while?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'No. Passive NFC chips (NTAG213, 215, 216) have no battery and no moving parts. They are rated for 100,000+ write cycles and will read reliably for 10+ years under normal use. They can fail if physically cracked, bent sharply, or exposed to strong magnetic fields.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'Can I combine NFC and QR on the same card?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'Yes, and most professionals do. NFC handles the modern, elegant "tap" for people with new phones, while the QR code acts as a universal fallback for older devices or situations where tapping is awkward. Both can point to the same URL.',
            ],
        ],
    ],
];

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tools', 'item' => 'https://cardify.om/tools/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'NFC Business Cards Guide', 'item' => $canonicalUrl],
    ],
];

$extraHead = '<script type="application/ld+json">' . json_encode($howToLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
           . '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

$showNavigation = true;
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-8 border-b border-gray-100">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-3" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <span>Tools</span>
                <span class="mx-2">/</span>
                <span class="text-gray-700">NFC Business Cards Guide</span>
            </nav>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">NFC Business Cards Guide: How They Work and Setup in Oman</h1>
            <p class="text-gray-600 text-lg">A practical guide to NFC business cards, what the technology is, which phones support it, how to program a card, how it compares to QR codes and paper cards, and where to actually buy them in Oman.</p>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <style>
            .guide-content { color: #374151; line-height: 1.8; font-size: 1.0625rem; }
            .guide-content h2 { font-size: 1.75rem; font-weight: 700; color: #111827; margin: 2.5rem 0 1rem; }
            .guide-content h3 { font-size: 1.25rem; font-weight: 600; color: #1f2937; margin: 1.75rem 0 0.75rem; }
            .guide-content p { margin-bottom: 1.1rem; }
            .guide-content ul, .guide-content ol { margin: 0.75rem 0 1.5rem 1.5rem; }
            .guide-content ul { list-style-type: disc; }
            .guide-content ol { list-style-type: decimal; }
            .guide-content li { margin-bottom: 0.5rem; }
            .guide-content strong { color: #111827; }
            .guide-content table { width: 100%; margin: 1.5rem 0; border-collapse: collapse; }
            /* The comparison table is wider than a 320px phone. Without a
               scroll container it took the whole page sideways with it:
               168px of horizontal overflow at 320, 98px at 390. */
            .guide-tablewrap { overflow-x: auto; -webkit-overflow-scrolling: touch; max-width: 100%; }
            .guide-tablewrap > table { min-width: 30rem; }
            .guide-content th { background: #f9fafb; text-align: left; padding: 0.75rem; border: 1px solid #e5e7eb; font-weight: 600; color: #111827; }
            .guide-content td { padding: 0.75rem; border: 1px solid #e5e7eb; vertical-align: top; }
            .guide-content blockquote { border-left: 4px solid #3b82f6; background: #eff6ff; padding: 1rem 1.25rem; margin: 1.5rem 0; border-radius: 0.5rem; }
        </style>

        <article class="bg-white rounded-xl shadow-sm p-6 sm:p-10 guide-content">
            <h2 class="mt-0">What is an NFC business card?</h2>
            <p>An NFC business card looks, to the eye, exactly like a normal paper or plastic business card. What makes it different is a small antenna and a microchip, usually less than half the thickness of a credit card, embedded inside. When someone holds a modern smartphone near the card, the phone powers the chip via electromagnetic induction, reads whatever data is stored on it, and acts on that data. Most commonly, the chip holds a web URL that opens a digital profile page with your contact details, social links, calendar booking widget, and whatever else you want.</p>
            <p>NFC stands for Near-Field Communication. It is the same short-range wireless technology that powers Apple Pay, Google Wallet, contactless metro cards, and modern hotel key cards. Range is intentionally tiny, roughly 2 to 4 centimetres, which is what makes it secure enough for payments. You have to deliberately tap the card against the phone; there is no passive broadcast or tracking.</p>

            <h2>How NFC actually works</h2>
            <p>Inside the card is a passive chip (almost always an NTAG213, NTAG215, or NTAG216 in business-card form factor). "Passive" means it has no battery. Instead, when a phone's NFC reader is close enough, the phone's magnetic field induces a tiny current in the card's copper antenna, which briefly powers the chip. The chip then modulates the phone's field in response, transmitting the stored data, often as a URL in the NFC Data Exchange Format (NDEF).</p>
            <p>The data capacity is small: NTAG213 holds 144 bytes, NTAG215 holds 504 bytes, and NTAG216 holds 888 bytes. That is plenty for a URL or a compact vCard, but not for an image or a PDF. Almost every professional NFC business card in use today stores a URL that points to a hosted profile, which is a much better approach than writing a static vCard directly to the chip, more on that below.</p>

            <h2>iPhone vs Android compatibility</h2>
            <p>Compatibility is no longer a meaningful concern in 2026, but it is the question everyone asks first, so here is the full picture.</p>

            <h3>iPhone</h3>
            <ul>
                <li><strong>iPhone XS, XR, 11, 12, 13, 14, 15, 16 and newer</strong> (any iPhone from 2018 onward): NFC tag reading is permanently on in the background whenever the phone is unlocked and the screen is on. Tap the card to the top of the phone and a notification banner appears, tap it to open the URL. Zero setup, zero app install.</li>
                <li><strong>iPhone 7, 8, X</strong>: NFC reading works but is not always on. The user must open Control Center, tap the NFC reader button (you may need to add it in Settings first), and then hold the card to the phone.</li>
                <li><strong>iPhone 6 and earlier</strong>: No NFC reading. These users need a QR fallback.</li>
            </ul>

            <h3>Android</h3>
            <ul>
                <li><strong>Nearly every Android phone from Android 5.0 (2014) onward</strong> reads NFC tags automatically when the screen is on. The URL opens in the default browser without any user action beyond tapping the card.</li>
                <li>A small number of very budget devices (mostly sub-50 USD handsets from 2017-2019) ship without NFC hardware at all. If the user has one of these, they need the QR fallback.</li>
            </ul>

            <blockquote>
                <strong>Rule of thumb:</strong> If someone has bought a phone in the last five years, their NFC works. Always design for the 1-2% of users who cannot tap by printing a QR code on the same card, ideally pointing to the same URL.
            </blockquote>

            <h2>How to set up your NFC business card</h2>
            <p>The five steps below apply whether you bought a blank card online or a custom-printed batch from a supplier.</p>
            <ol>
                <li><strong>Decide what the tap should do.</strong> The strongest choice is a public URL that you control, a Cardify profile, a LinkedIn page, a dedicated page on your own website. Avoid writing a raw vCard directly to the chip: vCards are rigid, cannot be updated once written, and render inconsistently across iOS and Android.</li>
                <li><strong>Install an NFC writer app.</strong> <em>NFC Tools</em> by wakdev is free on both iOS and Android and has the cleanest interface. <em>NXP TagWriter</em> is the official manufacturer tool and slightly more powerful. Either works.</li>
                <li><strong>Write the URL.</strong> In the app, choose "Write" &rarr; "URL/URI", paste your destination link, and hold the blank card flat against the back of your phone (or the top, for iPhone). The write takes about two seconds. You will hear a confirmation tone or see a green checkmark.</li>
                <li><strong>Lock the tag.</strong> This is optional but strongly recommended for a business card that leaves your hands. In the writer app, choose "Other features" &rarr; "Lock tag". Once locked, the tag is permanently read-only. A malicious person who picks up your card cannot rewrite it to point to a phishing site.</li>
                <li><strong>Test on both phones.</strong> Before you hand out 100 cards, test one on an iPhone and one on an Android. Confirm the URL opens, loads quickly, and displays correctly on a small screen.</li>
            </ol>

            <h2>NFC vs QR vs traditional business cards</h2>
            <div class="guide-tablewrap"><table>
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>Traditional paper</th>
                        <th>QR code</th>
                        <th>NFC</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Cost per card (OMR)</td>
                        <td>0.050 – 0.200</td>
                        <td>0.050 – 0.200</td>
                        <td>1.000 – 8.000</td>
                    </tr>
                    <tr>
                        <td>User action required</td>
                        <td>Type contact manually</td>
                        <td>Open camera, scan, tap notification</td>
                        <td>Tap card to phone</td>
                    </tr>
                    <tr>
                        <td>Works on older phones</td>
                        <td>Always</td>
                        <td>Any phone with camera</td>
                        <td>Phones from 2018+ only</td>
                    </tr>
                    <tr>
                        <td>Update info after printing</td>
                        <td>No, reprint</td>
                        <td>Yes (if QR points to short URL)</td>
                        <td>Yes (if chip is unlocked and URL-based)</td>
                    </tr>
                    <tr>
                        <td>"Wow" factor</td>
                        <td>Low</td>
                        <td>Medium</td>
                        <td>High</td>
                    </tr>
                    <tr>
                        <td>Works without network</td>
                        <td>Yes</td>
                        <td>Depends on destination</td>
                        <td>Depends on destination</td>
                    </tr>
                    <tr>
                        <td>Durability</td>
                        <td>Crumples, stains</td>
                        <td>Crumples, stains</td>
                        <td>PVC/metal, 10+ year chip lifespan</td>
                    </tr>
                </tbody>
            </table></div>

            <p>The practical answer for most Oman-based businesses in 2026: <strong>print a beautiful paper or PVC card with both a QR and an NFC chip, all pointing to the same hosted profile.</strong> You pay a few hundred baisa more per card, and you cover every scenario, the CEO who loves the tap, the auditor with an old phone who needs the QR, and the traditionalist who still wants to write the number on their rolodex.</p>

            <h2>Where to buy NFC business cards in Oman</h2>
            <p>The local print-shop market is still catching up. As of 2026, very few shops in Muscat or Salalah stock NFC cards as a standard product, you will almost always be placing a custom order. Your realistic options:</p>
            <ul>
                <li><strong>Regional UAE suppliers.</strong> Dubai-based companies like TapNTag, Popl, and V1CE ship to Oman within 3-5 business days via DHL or Aramex. Prices range from 3-8 OMR per custom-printed card in small batches, with steep bulk discounts above 100 pieces.</li>
                <li><strong>Direct import from AliExpress / Alibaba.</strong> If you just need blank NFC PVC cards to write yourself, bulk orders from Chinese suppliers come in at 0.200-0.500 OMR per card, 1-3 week delivery. Good for experimentation or in-house company use.</li>
                <li><strong>Amazon UAE.</strong> Same-week delivery, slightly higher prices than AliExpress, better return policy.</li>
                <li><strong>Local agencies that can source + program.</strong> A full-service print and branding partner (such as BHD Printing &amp; Designing) can handle the entire flow, design the card, order NFC stock from a trusted supplier, program each card with a per-employee URL, and deliver a finished product to your office. This is the right choice if you want the whole team on the same system and do not want to touch an NFC writer app yourself.</li>
            </ul>

            <h2>A note on privacy and security</h2>
            <p>NFC business cards are a one-way read, the card broadcasts a URL, the phone opens it. The card cannot "steal" anything from the recipient's phone; there is no channel for that. The only real risk is that if you leave the card unlocked, someone with physical access (for example, a card you handed to a stranger at a conference who then sits next to you) could technically reprogram it to point at a phishing URL before giving it back. Locking the tag after the initial write eliminates that risk completely, and takes ten seconds.</p>

            <h2>FAQ</h2>
            <h3>Do I need an app to use an NFC business card?</h3>
            <p>No. Both iPhone (7 and newer, running iOS 14+) and nearly all Android phones (5.0+) read NFC URLs natively. The recipient just taps the card against their phone, no app install required.</p>

            <h3>Does NFC work on iPhone?</h3>
            <p>Yes. Since iPhone XS (iOS 13+), NFC tag reading is always on in the background whenever the screen is unlocked. iPhone 7 and 8 users need to be on iOS 14+ and open the Control Center NFC reader manually. On all modern iPhones, tapping an NFC business card pops up a notification banner that opens the URL when tapped.</p>

            <h3>How is NFC different from a QR code?</h3>
            <p>A QR code is scanned by the phone camera, the user must open the camera app, point at the code, and tap a notification. NFC requires physical contact (under 4cm) but needs zero user action beyond touching the card to the phone. NFC feels faster and more magical; QR is cheaper to print and works on older phones.</p>

            <h3>Can I change what my NFC card links to after I print it?</h3>
            <p>Only if the card is unlocked. If you point the NFC card at a short URL like <code>cardify.om/ali</code>, you can update the profile behind that URL any time without rewriting the card. If you write a raw vCard or a hard-coded URL directly to the chip, changing it requires either rewriting (if unlocked) or reprinting.</p>

            <h3>How much does an NFC business card cost?</h3>
            <p>Blank PVC NFC cards cost around 1-2 OMR each for small quantities, dropping to 0.400-0.700 OMR in bulk (100+). Custom-printed NFC cards from regional suppliers typically run 3-8 OMR per card depending on finish. A metal NFC card can cost 15-40 OMR.</p>

            <h3>Do NFC cards stop working after a while?</h3>
            <p>No. Passive NFC chips have no battery and no moving parts. They are rated for 100,000+ write cycles and will read reliably for 10+ years under normal use. They can fail if physically cracked, bent sharply, or exposed to strong magnetic fields.</p>

            <h3>Can I combine NFC and QR on the same card?</h3>
            <p>Yes, and most professionals do. NFC handles the modern, elegant "tap" for people with new phones, while the QR code acts as a universal fallback for older devices or situations where tapping is awkward. Both can point to the same URL.</p>

            <div class="mt-10 pt-6 border-t border-gray-100 text-sm text-gray-500">
                Building a digital card profile your NFC and QR should point to? <a href="<?= getBasePath() ?>intro" class="text-blue-600 font-medium hover:text-blue-700">Try Cardify &rarr;</a>
            </div>
        </article>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
