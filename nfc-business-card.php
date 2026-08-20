<?php
/**
 * Cardify, head-term landing page for "NFC business card".
 *
 * r328. Sibling of digital-business-card.php. Deliberately a DIFFERENT page
 * rather than a section of that one: the buyer typing "nfc business card" is
 * asking a hardware question (does it work on my phone, what happens when it
 * does not) and the buyer typing "digital business card" is asking a platform
 * question. Answering both on one URL is how a page ends up ranking for
 * neither. Cross-linked, not duplicated.
 *
 * Self-canonical, English only, no /ar/ twin declared. See the note in
 * digital-business-card.php for the order of operations to add one.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'NFC Business Cards, Tap to Share in Arabic and English';
$pageDescription = 'An NFC business card shares your contact details when someone taps their phone against it. Cardify NFC cards are re-programmable, carry a QR code for phones without NFC, and cost OMR 25.000 each, delivered across Oman.';
$canonicalUrl    = 'https://cardify.om/nfc-business-card';

$showNavigation = true;

$faq = [
    ['What is an NFC business card?',
     'An NFC business card is a physical card with a small radio chip embedded inside it. When someone holds their phone near the card, the phone reads a web address off the chip and opens your digital business card. Nothing is installed, nothing is paired, and no battery is involved: the chip draws the tiny amount of power it needs from the phone itself.'],
    ['Which phones can read an NFC business card?',
     'Every iPhone from the iPhone XR and XS onwards reads NFC cards with the screen simply on and unlocked, with no app open. The older iPhone 7, 8 and X can read them using the NFC Tag Reader in Control Center. Most Android phones sold in the last decade have NFC and read tags once NFC is switched on in settings. Phones that cannot tap can still scan the QR code printed on the same card.'],
    ['What happens if the other person has no NFC?',
     'They scan the QR code instead. Every Cardify NFC card is printed with a QR code alongside the chip precisely because a card that only works on some phones is not a business card. The QR opens exactly the same profile, so the outcome is identical.'],
    ['Can an NFC card be reprogrammed?',
     'Yes. Cardify NFC cards are re-programmable, so the same physical card can be pointed at a different profile later. That matters in two situations: an employee changes role, or an employee leaves and the card is reassigned rather than binned.'],
    ['How much does an NFC business card cost?',
     'OMR 25.000 per card, which includes the chip, the printed card and the programming. There is no subscription attached to it. The Cardify platform behind the card is free for unlimited employees, so the card price is the whole price.'],
    ['Is tapping an NFC card safe?',
     'Yes. The chip is read-only in normal use and stores nothing except a web address. It carries no payment credential, holds no personal data beyond the link, and cannot read anything off the phone that taps it. The range is a couple of centimetres, so it cannot be read across a room.'],
    ['Do I need to keep paying for the card to work?',
     'No. The card points at a Cardify profile, and the Cardify platform has no subscription fee for the web platform, so there is no renewal that can switch your card off.'],
    ['Can we put NFC in something other than a card?',
     'Yes. The same chip works in a keyring, a phone-back sticker, a desk stand, or a badge. Reception desks and exhibition stands often prefer a stand, because it stays put and every visitor taps the same one instead of collecting cards.'],
];

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['NFC Business Cards', $canonicalUrl],
    ]),
    Seo::articleNode(
        __FILE__,
        'NFC Business Cards, Tap to Share in Arabic and English',
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
                <span class="text-gray-700">NFC Business Cards</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                NFC business cards that tap to share, in Arabic and English
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                Hold a phone near the card and your bilingual profile opens. A QR code on the same card covers every phone that cannot tap. OMR 25.000 per card, re-programmable, delivered across Oman.
            </p>
            <div class="flex flex-col sm:flex-row gap-3 mt-7">
                <a href="<?= $base ?>get-started" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/25 transition">
                    Build your card free, then order NFC
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

        <h2 id="what-is-an-nfc-business-card">What an NFC business card is</h2>
        <p>
            An NFC business card looks like an ordinary business card and behaves like a doorway. Inside the card stock sits a chip roughly the size of a fingernail, holding one thing: a web address. When somebody brings their phone within a couple of centimetres of it, the phone reads that address and offers to open it. They tap the notification, your digital business card opens in their browser, and one more tap saves you into their contacts.
        </p>
        <p>
            NFC stands for Near Field Communication, the same short-range radio standard behind contactless payment and building access badges. The chip in a business card is the simplest kind: it has no battery, stores no personal data, and does nothing but answer with a URL when a phone powers it from a few millimetres away.
        </p>
        <p>
            The reason it feels different from handing over paper is the number of steps it removes. A paper card requires the other person to later find it, read it, type your details into their phone, and spell your name correctly. An NFC tap collapses that into one motion, while both of you are still standing there.
        </p>

        <h2 id="which-phones-work">Which phones actually work</h2>
        <p>
            This is the question worth answering honestly, because it is the one that decides whether NFC cards are useful to you or an expensive novelty.
        </p>
        <ul>
            <li><strong>iPhone XR, iPhone XS and every iPhone since:</strong> reads NFC cards with the screen on and unlocked, with no app open and nothing enabled. The phone shows a notification you tap to open the link. This is the majority of iPhones in active use.</li>
            <li><strong>iPhone 7, 8 and X:</strong> the hardware can read tags, but not in the background. The owner opens the NFC Tag Reader from Control Center first. It works, it just is not seamless.</li>
            <li><strong>Android:</strong> most phones sold in the last decade include NFC. It reads tags automatically once NFC is switched on in settings, which it usually is by default on phones that also do contactless payment.</li>
            <li><strong>Budget Android handsets and older phones:</strong> many have no NFC hardware at all. Nothing you can do to the card changes that.</li>
        </ul>
        <p>
            Which is precisely why the next section exists.
        </p>

        <h2 id="qr-fallback">The QR code is not a downgrade, it is the point</h2>
        <p>
            Every Cardify NFC card is printed with a QR code as well as carrying the chip. This is not a compromise or a cheaper option, it is what makes the card usable in front of a stranger whose phone you know nothing about.
        </p>
        <p>
            In practice the exchange goes one of two ways. Either they tap, or you say "or just scan it" and point at the QR. Both open the same profile, both end in a saved contact, and neither requires you to interrogate someone about their handset in the middle of a meeting. A card that only works on some phones fails at exactly the moment it is supposed to work.
        </p>
        <p>
            If you want the mechanics of the QR side, that is covered on the <a href="<?= $base ?>digital-business-card">digital business card</a> page, and the underlying file format is explained under <a href="<?= $base ?>virtual-business-card">virtual business cards</a>.
        </p>

        <h2 id="nfc-vs-qr">NFC against QR, honestly</h2>
        <div class="not-prose overflow-x-auto my-8">
            <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden bg-white">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200"></th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">NFC tap</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">QR scan</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600">
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Works on every phone</td>
                        <td class="px-4 py-3 border-b border-gray-100">No. Needs NFC hardware, switched on</td>
                        <td class="px-4 py-3 border-b border-gray-100">Yes, any phone with a camera</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Works in the dark</td>
                        <td class="px-4 py-3 border-b border-gray-100">Yes</td>
                        <td class="px-4 py-3 border-b border-gray-100">Poorly. The camera needs to see it</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Works across a table</td>
                        <td class="px-4 py-3 border-b border-gray-100">No. Two centimetres or so</td>
                        <td class="px-4 py-3 border-b border-gray-100">Yes, from a metre or more</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Feels impressive</td>
                        <td class="px-4 py-3 border-b border-gray-100">Yes, noticeably</td>
                        <td class="px-4 py-3 border-b border-gray-100">Ordinary now</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-medium text-gray-900">Cost per person</td>
                        <td class="px-4 py-3">OMR 25.000</td>
                        <td class="px-4 py-3">Free, or from OMR 5.000 per 100 printed</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p>
            The sensible reading of that table is that NFC is worth buying for the people who hand over cards constantly, and QR is worth having on everything. A sales team working an exhibition stand at the Oman Convention and Exhibition Centre will get real value from tapping. An accountant who exchanges four cards a year will not.
        </p>

        <h2 id="reprogramming">Re-programmable, which matters more than it sounds</h2>
        <p>
            Cardify NFC cards can be re-pointed after they are made. The physical card stays the same and the address on the chip changes.
        </p>
        <p>
            Two situations make this worth paying attention to. The first is promotion: a person's title changes, and because the chip points at a live profile rather than storing the details itself, nothing needs reprogramming at all. The second is departure. When someone leaves, the company keeps the card, revokes the individual, and can reassign the same physical card to their replacement. On platforms where the chip is welded to one person's identity, that card becomes rubbish the day they resign.
        </p>
        <p>
            The same logic applies to a reception desk or an exhibition stand, where the card belongs to the stand rather than to a person, and should point at whichever profile or campaign is current.
        </p>

        <h2 id="where-nfc-earns-its-price">Where NFC earns its price</h2>
        <ul>
            <li><strong>Exhibition stands and conferences.</strong> High volume, short conversations, and a tap is faster than anything else available.</li>
            <li><strong>Reception desks.</strong> One NFC stand, every visitor leaves with the company profile.</li>
            <li><strong>Field sales.</strong> Staff meeting several new people a day, where the saved-contact rate is the whole point.</li>
            <li><strong>Executives and senior representation.</strong> Where the card is doing signalling work as well as informational work. We have a separate page for that case: <a href="<?= $base ?>solutions/nfc-business-cards-oman-executives">NFC cards for C-suite and board-level meetings in Oman</a>.</li>
            <li><strong>Vehicles and site offices.</strong> An NFC sticker on a service vehicle or a site cabin, pointing at the company profile.</li>
        </ul>
        <p>
            Where it does not earn its price: giving one to all four hundred staff because it seems modern. The digital card is free and unlimited. Buy chips for the people who tap.
        </p>

        <h2 id="ordering">Ordering NFC cards in Oman</h2>
        <p>
            Build the card first. Sign up, approve a template, and generate the digital cards for whoever needs them, which costs nothing and has no employee limit. Then order NFC for the subset who need a physical card, from the same dashboard.
        </p>
        <p>
            NFC tap cards are OMR 25.000 each, which covers the chip, the printed card and the programming. If you want ordinary printed cards for the rest of the team, those run OMR 5.000 per 100 Standard, OMR 6.000 per 100 Premium and OMR 15.000 per 100 Luxury. Orders are fulfilled by verified Omani print shops, dispatched within one working day and delivered across Oman in two to four working days, with same-day pickup available in Muscat.
        </p>

        <h2 id="faq">Frequently asked questions</h2>
        <?php foreach ($faq as [$q, $a]): ?>
            <h3><?= htmlspecialchars($q) ?></h3>
            <p><?= htmlspecialchars($a) ?></p>
        <?php endforeach; ?>

        <div class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Start free, add NFC when you need it</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">The digital card costs nothing and has no employee limit. Order NFC only for the people who tap.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <h2 id="keep-reading" class="mt-12">Keep reading</h2>
        <ul>
            <li><a href="<?= $base ?>digital-business-card">Digital business cards</a>, the platform behind the chip</li>
            <li><a href="<?= $base ?>virtual-business-card">Virtual business cards</a>, vCards, email signatures and remote meetings</li>
            <li><a href="<?= $base ?>glossary/nfc">NFC, defined</a>, in Arabic and English</li>
            <li><a href="<?= $base ?>tools/nfc-business-card-guide">NFC business card guide</a></li>
            <li><a href="<?= $base ?>solutions/nfc-business-cards-oman-executives">NFC cards for Omani executives</a></li>
        </ul>
    </article>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
