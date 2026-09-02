<?php
/**
 * Cardify, head-term landing page for "virtual business card".
 *
 * r328. Third sibling of digital-business-card.php and nfc-business-card.php.
 * The angle here is the one the other two do not cover: sharing when the two
 * people are NOT in the same room. Email signatures, video calls, WhatsApp,
 * and the file formats underneath. Written this way on purpose, because three
 * pages answering the same question in three synonyms is a doorway cluster,
 * and Google treats it as one.
 *
 * Self-canonical, English only, no /ar/ twin declared.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'Virtual Business Cards for Remote and Online Meetings';
$pageDescription = 'A virtual business card is a contact profile you share without meeting in person: in an email signature, a video call chat, a WhatsApp message or a LinkedIn reply. Cardify gives every employee one in Arabic and English, free for unlimited staff.';
$canonicalUrl    = 'https://cardify.om/virtual-business-card';

$showNavigation = true;

$faq = [
    ['What is a virtual business card?',
     'A virtual business card is a contact profile shared over a distance rather than handed across a table. It is the same underlying thing as a digital business card, but the phrase is normally used for the remote case: a link in an email signature, a card dropped into a video-call chat window, a profile sent on WhatsApp, or a reply to a LinkedIn message. The recipient opens it in a browser and saves the contact to their phone.'],
    ['Is a virtual business card the same as a digital business card?',
     'In practice yes, they are the same product. The two phrases emphasise different moments. "Digital business card" is the general term and is usually used for in-person sharing by QR or NFC. "Virtual business card" tends to be used when nobody is in the room, which is why it comes up in the context of remote work, email signatures and video calls.'],
    ['How do I put a virtual business card in my email signature?',
     'Add the card link behind a short line of text, such as "Save my contact details", and optionally a small QR image. Every recipient of every email you send can then save your details in one tap. On Cardify this is generated for each employee automatically, so a company can roll it out across a whole team rather than asking two hundred people to edit their own signature.'],
    ['Can I share a virtual business card on a video call?',
     'Yes. Paste the link into the meeting chat, or show the QR code on your screen for people to scan with their phones. Both work in Teams, Zoom, Google Meet and Webex, because there is nothing to install: the card is an ordinary web page.'],
    ['What file format does a virtual business card use?',
     'The card itself is a web page. When the recipient taps "Save contact", the page hands their phone a vCard file, a .vcf, which is the standard contact format every phone and CRM already understands. No app is required at either end.'],
    ['Does a virtual business card work in Arabic?',
     'On Cardify, yes, and by default. Every employee card carries a genuine Arabic version alongside the English one under a single link, with real Arabic name and title fields rather than transliteration, and right-to-left rendering. The saved contact carries both spellings.'],
    ['Are virtual business cards free?',
     'The Cardify web platform is free with no employee limit, no template limit and no card limit, and no credit card is required. Payment only applies to physical cards, from OMR 5.000 per 100.'],
];

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Virtual Business Cards', $canonicalUrl],
    ]),
    Seo::articleNode(
        __FILE__,
        'Virtual Business Cards for Remote and Online Meetings',
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
                <span class="text-gray-700">Virtual Business Cards</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                Virtual business cards for meetings that never happen in a room
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                One link per employee, in Arabic and English, that works in an email signature, a Teams chat, a WhatsApp message or a LinkedIn reply. Free for unlimited staff.
            </p>
            <div class="flex flex-col sm:flex-row gap-3 mt-7">
                <a href="<?= $base ?>get-started" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/25 transition">
                    Create your team's cards free
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
                <a href="<?= $base ?>tools/email-signature-generator" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white border border-gray-300 hover:border-gray-400 text-gray-800 font-semibold rounded-xl transition">
                    <i class="fa-solid fa-signature" aria-hidden="true"></i>
                    Free email signature generator
                </a>
            </div>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2 id="what-is-a-virtual-business-card">What a virtual business card is</h2>
        <p>
            A virtual business card is your contact profile as something you can send. Not a photograph of a card, not a signature block someone has to retype, but a live page that ends in a saved contact.
        </p>
        <p>
            Underneath, it is the same product as a <a href="<?= $base ?>digital-business-card">digital business card</a>. The two phrases have drifted apart in usage rather than in meaning. People say "digital business card" when they are thinking about handing one over at an event, and "virtual business card" when nobody is in the room: an email signature, a video call, a message thread. This page is about that second case, because it is the one most companies handle worst.
        </p>

        <h2 id="the-remote-problem">The problem nobody notices they have</h2>
        <p>
            Consider how a contact actually gets into a phone after a remote introduction. Someone emails you. Their signature block has a name, a title, two phone numbers and a company. To save them, you select the text, copy it piece by piece into a new contact, and probably get the spelling of their name wrong. Most people simply do not bother, and the relationship lives in an inbox until the email is archived.
        </p>
        <p>
            Now multiply that by an organisation. Every email your two hundred staff send is an opportunity for the recipient to save them, and almost none of those opportunities are taken, because taking one costs ninety seconds of retyping.
        </p>
        <p>
            A virtual business card collapses that to one tap. The signature carries a link, the link opens the card, the card writes the contact into the phone with the Arabic and English spellings both correct.
        </p>

        <h2 id="where-it-gets-shared">Where a virtual card actually gets shared</h2>
        <ul>
            <li><strong>Email signatures.</strong> The highest-volume surface any company has, and the most neglected. One line and a link under every message every employee sends.</li>
            <li><strong>Video call chat.</strong> Paste the link into the Teams, Zoom, Meet or Webex chat window during the introduction, while people are still paying attention.</li>
            <li><strong>WhatsApp.</strong> The default follow-up channel for business in Oman and the Gulf. A link previews properly and opens in one tap.</li>
            <li><strong>LinkedIn and other messaging.</strong> A reply to a connection request that contains a working phone number rather than a promise to send one.</li>
            <li><strong>Proposal and tender documents.</strong> A QR on the contact page of a submission, so the evaluator can reach the right person without transcribing.</li>
            <li><strong>Out-of-office replies and forms.</strong> Anywhere a person's details currently sit as unhelpful plain text.</li>
        </ul>

        <h2 id="under-the-hood">What is actually happening underneath</h2>
        <p>
            It is worth understanding, because it explains why the recipient never has to install anything.
        </p>
        <p>
            The card is an ordinary web page. When the recipient taps "Save contact", the page hands their device a <a href="<?= $base ?>glossary/vcard">vCard</a> file, a small plain-text format with a <code>.vcf</code> extension that has been a standard since long before smartphones existed. Every contacts app on every platform, plus Outlook, Google Contacts and every serious CRM, already knows how to read one.
        </p>
        <p>
            That universality is the whole trick. There is no compatibility question to ask and no app to install, because the receiving device already knows what to do with the file. The <a href="<?= $base ?>glossary/qr-vcard">QR vCard</a> and <a href="<?= $base ?>glossary/apple-wallet-pass">Apple Wallet pass</a> entries in our glossary cover the other two delivery routes.
        </p>
        <p>
            One honest limitation: the saved contact is a copy taken at that moment. Your card stays current forever, but the copy already sitting in somebody's address book does not update itself. Re-sharing issues a fresh one.
        </p>

        <h2 id="bilingual">Arabic and English, because half your correspondence is</h2>
        <p>
            If your organisation deals with Omani ministries, GCC state-linked companies, or any counterparty whose internal correspondence runs in Arabic, an English-only card quietly asks them to do the translation.
        </p>
        <p>
            Cardify holds a genuine Arabic name and title on every employee record, not a machine transliteration of the English, and your Arabic-speaking staff correct their own entries rather than having marketing guess. The card renders right-to-left properly, and the vCard the recipient saves carries both spellings, so their phone finds the same person whether they search in Arabic or English.
        </p>

        <h2 id="rolling-it-out">Rolling it out without chasing people</h2>
        <p>
            The failure mode of virtual cards at company scale is always the same: you tell everyone to make one, forty people do, and the initiative dies quietly.
        </p>
        <p>
            Cardify inverts the order. An administrator uploads the roster and every card is generated at once, from one approved template. Employees then receive a private link to check and correct their own details, which is a two-minute task rather than a setup project, and the ones who ignore it still have a working card in the meantime.
        </p>
        <p>
            Email signatures are generated per employee from the same data, so the rollout does not depend on two hundred people editing their own mail client correctly. Our free <a href="<?= $base ?>tools/email-signature-generator">email signature generator</a> is open to anyone, including people who never sign up.
        </p>

        <h2 id="cost">What it costs</h2>
        <p>
            The platform is free, permanently, with no employee limit, no template limit and no card limit, and no credit card is required. Nothing about the virtual card costs money at any headcount.
        </p>
        <p>
            Physical cards are the only paid item: OMR 5.000 per 100 Standard, OMR 6.000 per 100 Premium, OMR 15.000 per 100 Luxury, and OMR 10.000 per NFC tap card, fulfilled by verified Omani print shops and delivered across Oman in two to four working days. See the <a href="<?= $base ?>pricing">pricing page</a>.
        </p>

        <h2 id="faq">Frequently asked questions</h2>
        <?php foreach ($faq as [$q, $a]): ?>
            <h3><?= htmlspecialchars($q) ?></h3>
            <p><?= htmlspecialchars($a) ?></p>
        <?php endforeach; ?>

        <div class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Put a working card under every email your team sends</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Free for unlimited employees. Upload a roster, approve one template, and every signature carries a card by this afternoon.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <h2 id="keep-reading" class="mt-12">Keep reading</h2>
        <ul>
            <li><a href="<?= $base ?>digital-business-card">Digital business cards</a>, the in-person side</li>
            <li><a href="<?= $base ?>nfc-business-card">NFC business cards</a>, tap-to-share mechanics</li>
            <li><a href="<?= $base ?>glossary">Glossary</a>, every term here defined in Arabic and English</li>
            <li><a href="<?= $base ?>compare">Compare</a>, Cardify against Popl, Blinq and HiHello</li>
            <li><a href="<?= $base ?>tools/vcard-qr-generator">Free vCard QR generator</a></li>
        </ul>
    </article>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
