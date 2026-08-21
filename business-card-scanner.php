<?php
/**
 * /business-card-scanner  (+ /ar/business-card-scanner)
 *
 * The scanner had no SEO surface at all: /scanner, /scan and
 * /business-card-scanner all 404'd, and the only page that mentioned scanning
 * (/app) carried no structured data of any kind. Every "business card scanner"
 * query resolved to somebody else.
 *
 * What this page deliberately does NOT say is "the best business card
 * scanner". That claim is not defensible against CamCard, ABBYY or Microsoft
 * Lens on any axis we can evidence. The two claims below ARE defensible and
 * are the whole argument of the page:
 *
 *   1. Standard OCR runs on the device, in Arabic and English. A card image
 *      is sent only when a Pro user explicitly requests a server reread.
 *   2. Print, share and scan are connected in one Cardify account. This is a
 *      direct product capability, so the page describes it without making a
 *      sweeping or untestable claim about every competing product.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AppEntity.php';

$isAr = (class_exists('I18n') && I18n::getLocale() === 'ar') || (($_GET['lang'] ?? '') === 'ar');
// r81 / r6-99: app.php's copy of this fourth spelling (no country segment, no
// slug) was replaced by the record; this one survived because it feeds the
// visible CTA rather than the graph, and the probe only read ld+json. The
// store link a reader clicks and the store link the graph asserts are the
// same claim about the same listing, so they read the same constant.
$appStoreUrl = AppEntity::APPSTORE_URL;

$pageTitle = $isAr
    ? 'ماسح بطاقات العمل: قراءة عربية وإنجليزية على الجهاز | كارديفاي'
    : 'Business Card Scanner: Arabic & English, On-Device | Cardify';
$pageDescription = $isAr
    ? 'ماسح بطاقات عمل مجاني لـ iPhone يقرأ العربية والإنجليزية. المسح القياسي على الجهاز، وإعادة القراءة عبر الخادم اختيارية لمستخدم Pro.'
    : 'A free iPhone business card scanner for Arabic and English cards. Standard scanning stays on-device, with an optional Pro server-assisted reread only when you choose it.';
$canonicalUrl = 'https://cardify.om/business-card-scanner';
// llm27-34: $canonicalUrl is the ENGLISH address and is used below as the
// locale-invariant app URL. The FAQ node is NOT locale-invariant: its questions
// and answers are written twice, once per language, so keying both bodies to
// one @id publishes one identifier with two contradicting payloads. The WebPage
// pair on this same page is already namespaced this way by ui-header.
$localeUrl = $isAr
    ? 'https://cardify.om/ar/business-card-scanner'
    : $canonicalUrl;
$showNavigation = true;

$faq = $isAr ? [
    ['هل تُرفع صورة البطاقة إلى خادم؟',
     'المسح القياسي وتنظيف بيانات الاتصال يتمان على جهازك، ولا تُرفع صورة البطاقة. وإذا اخترت بنفسك ميزة Pro لإعادة قراءة بطاقة صعبة عبر الخادم، تُرسل صورة البطاقة التي حددتها لتلك المرة فقط.'],
    ['هل يقرأ البطاقات العربية؟',
     'نعم، العربية والإنجليزية، بما في ذلك البطاقات التي تحمل اللغتين على الوجه نفسه. تُعرض النتيجة للمراجعة قبل الحفظ، لأن أي قارئ ضوئي يخطئ أحياناً في الأسماء والألقاب.'],
    ['كم يكلّف؟',
     'المسح القياسي ومراجعة الحقول وحفظ جهات الاتصال مجانية. تضيف Pro ميزات Apple Wallet، والنسخ الاحتياطي والمزامنة بين الأجهزة، واستيراد جهات اتصال iPhone، والقوالب المميزة ورفع الشعار، وإعادة القراءة الاختيارية عبر الخادم. طلبات الطباعة منفصلة.'],
    ['ما الفرق عن تطبيقات المسح الأخرى؟',
     'يجمع كارديفاي بين مسح بطاقات العمل، وبطاقتك الرقمية، والمشاركة عبر QR وNFC وApple Wallet، وطلب بطاقاتك المطبوعة من مطبعة مجموعة BHD في مسقط، وذلك ضمن حساب كارديفاي نفسه.'],
] : [
    ['Is the card image uploaded to a server?',
     'Standard scanning and contact cleanup run on your device, so the card image is not uploaded. If you explicitly choose the Pro server-assisted reread for a difficult card, only the card image selected for that reread is sent.'],
    ['Does it read Arabic cards?',
     'Yes, Arabic and English, including cards that carry both on the same face. Every result is shown for review before it is saved, because any optical reader gets names and titles wrong sometimes.'],
    ['What does it cost?',
     'Standard scanning, field review, and saving contacts are free. Pro adds Apple Wallet, cross-device backup and sync, iPhone contact import, premium card templates and logo upload, and the optional server-assisted reread. Printed card orders are separate.'],
    ['How is this different from the other scanner apps?',
     'Cardify combines business card scanning, your own digital card, QR, NFC and Apple Wallet sharing, and printed-card ordering from BHD Group\'s press in Muscat within the same Cardify account.'],
];

$features = $isAr ? [
    ['fa-microchip', 'مسح قياسي على الجهاز', 'يعمل التعرّف الضوئي القياسي محلياً على iPhone ولا يلزم اتصال بالإنترنت. إعادة القراءة عبر الخادم خيار منفصل لمستخدم Pro.'],
    ['fa-language', 'عربي وإنجليزي', 'يقرأ الوجه العربي والوجه الإنجليزي، والبطاقات التي تجمع اللغتين، ويحفظ الاسم بالنصّين حين يتوفّران.'],
    ['fa-list-check', 'مراجعة قبل الحفظ', 'تُعرض الحقول المستخرجة للتصحيح قبل إضافتها إلى جهات الاتصال، بدل حفظ قراءة خاطئة بصمت.'],
    ['fa-address-card', 'بطاقتك أنت أيضاً', 'التطبيق نفسه يحمل بطاقتك الرقمية وبطاقة Apple Wallet، ويطلب طباعتها من مطبعة المجموعة في مسقط.'],
] : [
    ['fa-microchip', 'Standard on-device scanning', 'Standard optical recognition runs locally on the iPhone without a connection. The server-assisted reread is a separate Pro action you choose.'],
    ['fa-language', 'Arabic and English', 'Reads the Arabic face, the English face, and cards that carry both, keeping the name in both scripts where the card provides them.'],
    ['fa-list-check', 'Review before saving', 'Extracted fields are shown for correction before they reach your contacts, instead of silently saving a misread.'],
    ['fa-address-card', 'Your own card too', 'The same app carries your digital card and its Apple Wallet pass, and orders it printed at the group\'s press in Muscat.'],
];

$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        // r81 / r6-99 + llm20-21: this block used to be the SECOND
        // definition of the app, under @id https://cardify.om/#app, while
        // index.php published https://cardify.om/app#ios-app. Both cited
        // r6-99, both asserted uniqueness, and nothing compared them. One
        // record now, in includes/AppEntity.php. The publisher stays the
        // group node, and r151 stopped this surface from being the one that
        // says so: it was passing the group @id while app.php and index.php
        // took a cardify.om default, i.e. one @id with two publishers chosen
        // by which page you fetched. AppEntity::PUBLISHER_ID owns it now and
        // node() takes no argument, so a caller cannot disagree.
        AppEntity::node(),
        [
            '@type'      => 'FAQPage',
            '@id'        => $localeUrl . '#faq',
            'inLanguage' => $isAr ? 'ar' : 'en',
            'mainEntity' => array_map(static fn($q) => [
                '@type'          => 'Question',
                'name'           => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
            ], $faq),
        ],
    ],
];
$extraHead = '<script type="application/ld+json">'
    . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<main class="min-h-screen bg-gray-50" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
    <section class="pt-32 pb-16 bg-white border-b border-gray-100">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold mb-6" style="background:#e6f5f9;color:#00708c">
                <i class="fa-brands fa-apple" aria-hidden="true"></i>
                <?= $isAr ? 'مجاني على iPhone وiPad' : 'Free on iPhone and iPad' ?>
            </div>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight mb-6" style="color:#101828">
                <?= $isAr ? 'ماسح بطاقات عمل يقرأ العربية، على جهازك.' : 'A business card scanner that reads Arabic, on your device.' ?>
            </h1>
            <p class="text-lg text-gray-600 leading-relaxed mb-8">
                <?= $isAr
                    ? 'يقرأ كارديفاي البطاقة بالعربية أو الإنجليزية على iPhone، ثم يعرض الحقول لمراجعتها قبل الحفظ. المسح القياسي يبقى على الجهاز، ويُرسل مسح محدد فقط إذا اخترت ميزة Pro لإعادة القراءة عبر الخادم.'
                    : 'Cardify reads Arabic and English business cards on the iPhone, then shows every field for review before saving. Standard scanning stays on your device. A selected scan is sent only if you choose the optional Pro server-assisted reread.' ?>
            </p>
            <a href="<?= $e($appStoreUrl) ?>" class="inline-flex items-center gap-2 rounded-xl px-7 py-4 text-white font-semibold" style="background:#009bc1">
                <i class="fa-brands fa-apple" aria-hidden="true"></i>
                <?= $isAr ? 'حمّله من App Store' : 'Get it on the App Store' ?>
            </a>
            <p class="mt-4 text-sm text-gray-500">
                <?= $isAr
                    ? 'المسح وحفظ جهات الاتصال مجاناً. يقرأ الماسح البطاقات العربية والإنجليزية على الجهاز، وتطبع كارديفاي بطاقتك في مسقط.'
                    : 'Scanning and saving contacts is free. The scanner reads Arabic and English cards on the device, and Cardify prints your own card in Muscat.' ?>
            </p>
        </div>
    </section>

    <section class="py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 grid sm:grid-cols-2 gap-8">
            <?php foreach ($features as [$icon, $t, $d]): ?>
            <div class="bg-white rounded-2xl p-7 shadow-sm border border-gray-100">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-5" style="background:#e6f5f9;color:#00718c">
                    <i class="fa-solid <?= $e($icon) ?> text-xl" aria-hidden="true"></i>
                </div>
                <h2 class="text-xl font-bold mb-3" style="color:#101828"><?= $e($t) ?></h2>
                <p class="text-gray-600 leading-relaxed"><?= $e($d) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="py-16 bg-white border-t border-gray-100">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold mb-4" style="color:#101828">
                <?= $isAr ? 'لماذا يهمّ أن تكون البطاقة والمسح في نظام واحد' : 'Why scanning and issuing in one system matters' ?>
            </h2>
            <p class="text-gray-600 leading-relaxed mb-4">
                <?= $isAr
                    ? 'يجمع كارديفاي ماسح بطاقات العمل وبطاقتك الرقمية وطلبات الطباعة ضمن حساب واحد. وكارديفاي جزء من مجموعة BHD التي تملك مطبعتها في مسقط، ونظام جودتها موثق وفق ISO 9001:2015. لذلك يمكنك مسح بطاقة ومشاركة بطاقتك وطلبها مطبوعة من كارديفاي.'
                    : 'Cardify combines business card scanning, your own digital card, and printed-card ordering within one account. Cardify is part of BHD Group, which owns its press in Muscat and documents its quality system to ISO 9001:2015. You can scan a card, share yours, and order it in print through Cardify.' ?>
            </p>
            <p class="text-gray-600 leading-relaxed">
                <?= $isAr
                    ? 'وهذا يعني عملياً: تمسح بطاقة من اجتماع، فتُحفظ لديك؛ وتشارك بطاقتك بـ QR أو NFC أو Apple Wallet؛ وحين تحتاج نسخة مطبوعة تطلبها من الشاشة نفسها دون ملفّ تصميم ولا رسالة إلى مورّد.'
                    : 'In practice: you scan a card from a meeting and it is saved; you share your own by QR, NFC or Apple Wallet; and when you need it on paper you order it from the same screen, with no artwork file and no email to a supplier.' ?>
            </p>
        </div>
    </section>

    <section class="py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold mb-8" style="color:#101828"><?= $isAr ? 'أسئلة شائعة' : 'Common questions' ?></h2>
            <div class="space-y-4">
                <?php foreach ($faq as [$q, $a]): ?>
                <div class="bg-white rounded-2xl p-6 border border-gray-100">
                    <h3 class="font-bold mb-2" style="color:#101828"><?= $e($q) ?></h3>
                    <p class="text-gray-600 leading-relaxed"><?= $e($a) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-10 text-center">
                <a href="<?= $isAr ? '/ar/app' : '/app' ?>" class="font-semibold" style="color:#00718c">
                    <?= $isAr ? 'كل ما يفعله التطبيق' : 'Everything the app does' ?> &rarr;
                </a>
            </div>
        </div>
    </section>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
