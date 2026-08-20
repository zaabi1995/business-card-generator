<?php
/**
 * Cardify, head-term landing page for "digital business card".
 *
 * r328. Before this file existed the phrase every buyer actually types had no
 * page on the estate: /digital-business-card and /digital-business-cards both
 * 404'd, and every asset we owned for the term was geo-qualified
 * (/solutions/digital-business-cards-oman-sales-teams and friends), which
 * ranks for the modifier and never for the head. The home page said "digital
 * business card" three times in 1,753 words.
 *
 * Self-canonical, English only. No /ar/ twin is declared: ArTwins has no entry
 * for this path, so the header emits en + x-default at itself and no phantom
 * Arabic alternate. Translate the BODY first, then add the ArTwins entry and
 * the nginx rewrite, in that order.
 *
 * Routing is the aaPanel nginx rewrite (docs/head-terms-nginx-rewrites.conf).
 * .htaccess is inert on this host: there is no Apache in front of it.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'Digital Business Cards for Teams, Arabic and English';
$pageDescription = 'A digital business card is a shareable web profile with a QR code and a saveable contact file. Cardify gives every employee one in Arabic and English, free for unlimited staff, with optional printed and NFC cards fulfilled in Oman.';
$canonicalUrl    = 'https://cardify.om/digital-business-card';

$showNavigation = true;

$faq = [
    ['What is a digital business card?',
     'A digital business card is a web page that holds one person\'s contact details and can be shared instantly by QR code, link, tap or message. Instead of handing over a printed card, you show a QR code or send a URL, and the other person taps "Save contact" to write your name, number, email and company straight into their phone\'s address book as a vCard file. Because the card is a live page rather than ink on paper, changing your mobile number or job title updates every copy you have ever shared.'],
    ['Do digital business cards work without an app?',
     'Yes. A Cardify card opens in the phone\'s normal web browser, so the person receiving it installs nothing and creates no account. The QR code is read by the built-in camera on both iPhone and Android. An app is only needed if you want to scan and file other people\'s paper cards, which is what the free Cardify scanner app on iOS does.'],
    ['Can one card be in both Arabic and English?',
     'Yes, and on Cardify it is the default rather than an add-on. Each employee card carries an Arabic side and an English side under one URL. The card opens in the language of the visitor\'s device and offers a one-tap switch, and both versions save into the contacts app with correctly spelled names in each script.'],
    ['How much does a digital business card cost?',
     'The Cardify platform is free with no employee limit, no template limit and no card limit, and no credit card is required. You only pay if you order physical cards: OMR 5.000 per 100 Standard cards, OMR 6.000 per 100 Premium, OMR 15.000 per 100 Luxury, and OMR 25.000 for a re-programmable NFC tap card.'],
    ['What happens to a card when an employee leaves?',
     'The company keeps the card, not the employee. An administrator revokes the person\'s access, and the profile URL, the QR code and the scan history stay with the business. The same URL can be reassigned to a replacement, so QR codes already printed on brochures, vehicles or signage keep working instead of leading to a dead page.'],
    ['Is a digital business card the same as a QR code?',
     'No. The QR code is one way to open the card, not the card itself. The same profile can be opened by QR, by a link in an email signature, by tapping an NFC card, by a WhatsApp message, or by an Apple Wallet or Google Wallet pass. Replacing the QR image does not change the card, and changing the card does not require reprinting the QR.'],
    ['Do digital business cards work offline?',
     'Opening a card needs a connection, because it is a web page. Two things do not: a contact already saved to the phone stays saved forever, and a wallet pass added to Apple Wallet or Google Wallet keeps displaying its QR code with no signal, which is the case that matters in a basement exhibition hall.'],
    ['Can I still have printed cards as well?',
     'Most Cardify customers do. The usual arrangement is a printed card carrying the person\'s QR code on the back, so a meeting that starts with paper still ends with a saved contact. Printing is ordered from the same dashboard and fulfilled by verified Omani print shops, dispatched within one working day and delivered across Oman in two to four working days.'],
];

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Digital Business Cards', $canonicalUrl],
    ]),
    Seo::articleNode(
        __FILE__,
        'Digital Business Cards for Teams, Arabic and English',
        $pageDescription,
        $canonicalUrl,
        null,
        'en-OM'
    ),
    Seo::faqNode($faq)
);

require_once INCLUDES_DIR . '/ui-header.php';
$base = getBasePath();
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= $base ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Digital Business Cards</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                Digital business cards built for bilingual teams
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                One card per employee, in Arabic and English, under a URL your company owns. Free for unlimited staff. Printed and NFC cards optional, fulfilled in Oman.
            </p>
            <div class="flex flex-col sm:flex-row gap-3 mt-7">
                <a href="<?= $base ?>get-started" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/25 transition">
                    Create your team's cards free
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
                <a href="<?= $base ?>pricing" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white border border-gray-300 hover:border-gray-400 text-gray-800 font-semibold rounded-xl transition">
                    <i class="fa-solid fa-tag" aria-hidden="true"></i>
                    See pricing
                </a>
            </div>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2 id="what-is-a-digital-business-card">What a digital business card actually is</h2>
        <p>
            A digital business card is a web page that holds one person's professional contact details and can be handed over instantly: by showing a QR code, sending a link, tapping a phone against an NFC card, or dropping the URL into a WhatsApp message. The person receiving it taps one button and your name, mobile number, email, job title and company are written into their phone's address book as a standard vCard file, the same <code>.vcf</code> format every contacts app on earth already understands.
        </p>
        <p>
            The part that matters is not the QR code. It is that the card is <em>live</em>. A printed card is a photograph of your contact details on the day it was printed. A digital card is the details themselves. When a colleague moves from one mobile number to another, every card they have ever shared updates at once, including the QR codes already printed on the back of a brochure eighteen months ago.
        </p>
        <p>
            For an individual that is a convenience. For a company of two hundred people it is a different thing entirely: it is the difference between a contact database you control and a few thousand pieces of card stock scattered across the Gulf, each one asserting a version of your organisation that may no longer be true.
        </p>

        <h2 id="how-it-works">How it works, start to finish</h2>
        <ol>
            <li><strong>Upload the roster.</strong> A spreadsheet of names, titles, emails and mobile numbers, in Arabic and English. Nothing else is required to start.</li>
            <li><strong>Approve one template.</strong> Your logo, your brand colours, your typeface, your field order. The template is approved once by whoever owns the brand, and every employee card is generated from it, so nobody is inventing a personal layout in a design tool.</li>
            <li><strong>Every employee gets a card.</strong> Each person receives a private link to review and correct their own details. They can fix the spelling of their name in Arabic, add a photo, or change a mobile number without asking anyone in marketing, and without being able to touch the template.</li>
            <li><strong>Share it, in whatever way the meeting allows.</strong> QR code on a phone screen, link in an email signature, NFC tap, wallet pass, WhatsApp, or the QR printed on the back of a physical card.</li>
        </ol>
        <p>
            The whole sequence, from a spreadsheet to a company with working cards, is usually one afternoon. There is no implementation project, because there is nothing to install.
        </p>

        <h2 id="arabic-and-english">Arabic and English on the same card</h2>
        <p>
            This is the part most digital business card platforms do not do, and it is the reason Cardify exists. A card is not bilingual because it has a language toggle bolted on. It is bilingual when both versions are correct.
        </p>
        <p>
            An Arabic name is not an English name transliterated by an algorithm. A right-to-left layout is not a left-to-right layout mirrored. A job title that reads well in English frequently has no single correct Arabic equivalent, and the one your organisation uses in its own correspondence is the one that belongs on the card. So on Cardify, each employee record holds a genuine Arabic field alongside the English one, your Arabic-speaking staff can correct their own entry, and the card renders right-to-left with the correct script rather than reversing the English.
        </p>
        <p>
            Both versions live under one URL and one QR code. The card opens in the language of the visitor's device and offers a one-tap switch. When the recipient saves the contact, the vCard carries both spellings, so searching their phone for the Arabic name finds the same person as searching for the English one.
        </p>
        <p>
            If your organisation deals with Omani government entities, GCC state-linked companies, or any counterparty whose internal correspondence is in Arabic, this is not a nicety. A card that exists only in English quietly asks the other party to do the translation work.
        </p>

        <h2 id="whats-on-the-card">What sits on a Cardify digital business card</h2>
        <ul>
            <li>Name, job title, department and company, in both scripts</li>
            <li>Tap-to-call in Oman's +968 format, with extension handling</li>
            <li>Tap-to-email and a direct WhatsApp button with a pre-filled opening message</li>
            <li>A "Save contact" button that writes a full vCard into the phone's address book</li>
            <li>A QR code that opens the card, sized to stay scannable when printed small</li>
            <li>An Apple Wallet and Google Wallet pass, so the card sits next to a boarding pass and works with no signal</li>
            <li>Company logo, brand colours and an optional profile photo</li>
            <li>A map pin to the office and a link to the company website</li>
            <li>Social profiles that your brand owner chooses to allow, rather than whatever each person adds</li>
            <li>Optional attachments, such as a company profile PDF or a product catalogue</li>
        </ul>

        <h2 id="digital-printed-nfc">Digital, printed and NFC are not rivals</h2>
        <p>
            A recurring mistake is treating this as a choice. In practice most teams run all three, because they solve different moments.
        </p>
        <div class="not-prose overflow-x-auto my-8">
            <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden bg-white">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">Format</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">Best for</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">Updates after sharing</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">Cost</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600">
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Digital card</td>
                        <td class="px-4 py-3 border-b border-gray-100">Everything. The card itself.</td>
                        <td class="px-4 py-3 border-b border-gray-100">Yes, instantly</td>
                        <td class="px-4 py-3 border-b border-gray-100">Free, unlimited employees</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Printed card</td>
                        <td class="px-4 py-3 border-b border-gray-100">Formal meetings, government offices, senior counterparties who expect paper</td>
                        <td class="px-4 py-3 border-b border-gray-100">The paper does not, but the QR on it points at a card that does</td>
                        <td class="px-4 py-3 border-b border-gray-100">From OMR 5.000 per 100</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-medium text-gray-900">NFC card</td>
                        <td class="px-4 py-3">Exhibition stands, reception desks, sales staff meeting many people a day</td>
                        <td class="px-4 py-3">Yes, and the chip can be re-pointed without reprinting</td>
                        <td class="px-4 py-3">OMR 25.000 per card</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p>
            The pattern that works in Oman: give everyone a digital card, print for the people who sit across the table from ministries and banks, and buy NFC cards for the handful of staff who work exhibitions. Read more on <a href="<?= $base ?>nfc-business-card">NFC business cards</a> if tap-to-share is the part you care about.
        </p>

        <h2 id="rolling-out-to-a-team">Rolling it out to fifty people, or five thousand</h2>
        <p>
            Individual card apps fall apart at organisational scale, and they fail in a predictable way: everybody makes their own card, so the brand drifts within a month, half the staff never finish setting theirs up, and nobody can answer the question "what does our company's card look like?"
        </p>
        <p>
            Cardify inverts that. The template is a company asset, approved once. Employees own their own <em>data</em> and nothing else. An administrator can see who has completed their profile and who has not, chase the stragglers with one click, and generate cards in bulk from the roster rather than waiting for two hundred people to individually sign up.
        </p>
        <p>
            The company also gets its own subdomain, <code>yourcompany.cardify.om</code>, so every employee card sits under a URL that reads as yours. Analytics are aggregated at the company level: how many times cards were opened, how many contacts were saved, which offices are actually using them.
        </p>
        <p>
            Departures are handled properly, which is the failure mode nobody plans for. Revoking an employee leaves the URL, the QR code and the history in the company's hands.
        </p>

        <h2 id="cost">What it costs</h2>
        <p>
            The platform is free, permanently, with no employee ceiling and no card ceiling, and no credit card is required to start. That is not a trial. There is no per-seat monthly fee to grow into.
        </p>
        <p>
            Money only changes hands when you order something physical:
        </p>
        <ul>
            <li><strong>Standard cards</strong>, 300gsm matt, full colour both sides: OMR 5.000 per 100</li>
            <li><strong>Premium cards</strong>, 350gsm soft-touch, full colour both sides: OMR 6.000 per 100</li>
            <li><strong>Luxury cards</strong>, 450gsm with spot UV or foil accents: OMR 15.000 per 100</li>
            <li><strong>NFC tap cards</strong>, re-programmable chip plus QR: OMR 25.000 per card</li>
        </ul>
        <p>
            Printing is fulfilled by verified Omani print shops, dispatched within one working day and delivered across Oman in two to four working days. Pickup from Muscat is same-day. Full detail is on the <a href="<?= $base ?>pricing">pricing page</a>.
        </p>

        <h2 id="where-it-works">Where it works</h2>
        <p>
            Cardify is built and hosted in Muscat and used across the GCC. The digital card works anywhere with a browser. Printed and NFC fulfilment currently runs through our verified print network in Oman, with delivery across the Sultanate including Salalah, Sohar and Duqm.
        </p>
        <p>
            Country-specific guidance sits on the <a href="<?= $base ?>gcc/oman">Oman</a>, <a href="<?= $base ?>gcc/saudi-arabia">Saudi Arabia</a>, <a href="<?= $base ?>gcc/uae">UAE</a>, <a href="<?= $base ?>gcc/qatar">Qatar</a>, <a href="<?= $base ?>gcc/bahrain">Bahrain</a> and <a href="<?= $base ?>gcc/kuwait">Kuwait</a> pages.
        </p>

        <h2 id="faq">Frequently asked questions</h2>
        <?php foreach ($faq as [$q, $a]): ?>
            <h3><?= htmlspecialchars($q) ?></h3>
            <p><?= htmlspecialchars($a) ?></p>
        <?php endforeach; ?>

        <div class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Give every employee a bilingual card today</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Free for unlimited staff. No credit card. Upload a roster, approve one template, and your whole team has cards this afternoon.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <h2 id="keep-reading" class="mt-12">Keep reading</h2>
        <ul>
            <li><a href="<?= $base ?>nfc-business-card">NFC business cards</a>, how tap-to-share actually works and which phones support it</li>
            <li><a href="<?= $base ?>virtual-business-card">Virtual business cards</a>, the vCard file, email signatures and remote meetings</li>
            <li><a href="<?= $base ?>glossary">Glossary</a>, the terms in this field defined in Arabic and English</li>
            <li><a href="<?= $base ?>compare">Compare</a>, Cardify against Popl, Blinq and HiHello</li>
            <li><a href="<?= $base ?>solutions">Solutions</a>, guidance by industry and role in Oman</li>
        </ul>
    </article>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
