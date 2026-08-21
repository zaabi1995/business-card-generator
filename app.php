<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
$isAr = (class_exists('I18n') && I18n::getLocale() === 'ar') || (($_GET['lang'] ?? '') === 'ar');
$pageTitle = $isAr
    ? 'تطبيق كارديفاي لـ iPhone: ماسح بطاقات عمل وبطاقة رقمية'
    : 'Cardify iPhone App: Business Card Scanner & Digital Card';
$pageDescription = $isAr
    ? 'حمّل كارديفاي على iPhone وiPad. امسح بطاقات العمل بالعربية والإنجليزية، راجع البيانات قبل الحفظ، وشارك بطاقتك بـ QR وNFC وApple Wallet.'
    : 'Download Cardify for iPhone and iPad. Scan Arabic and English business cards, review contacts before saving, and share your digital card by QR, NFC, or Apple Wallet.';
$canonicalUrl = 'https://cardify.om/app';
$localeUrl = $isAr ? 'https://cardify.om/ar/app' : $canonicalUrl;
$showNavigation = true;
$openUrl = normalizeCardifyUrl((string) ($_GET['url'] ?? '')) ?? '';
$nativeUrl = $openUrl !== '' ? 'cardifyscan://app/open?url=' . rawurlencode($openUrl) : 'cardifyscan://';
require_once INCLUDES_DIR . '/AppEntity.php';
// r81 / r6-99: this page named a FOURTH App Store URL, with no country
// segment and no slug, while /business-card-scanner and bhd.om named the
// full one. A redirect chain is not an identifier.
$appStoreUrl = AppEntity::APPSTORE_URL;
// r81 / r6-99 + llm20-21: /app is the app's page and the document BOTH
// competing @ids were a fragment of, and it published no app node at all.
// Now it defines the entity its own URL identifies, so a consumer that
// dereferences https://cardify.om/app#ios-app lands on the thing.
$faq = $isAr ? [
    ['ما هو تطبيق كارديفاي لـ iPhone؟',
     'كارديفاي ماسح بطاقات عمل ومنصة بطاقات رقمية لأجهزة iPhone وiPad. تستطيع مسح بطاقة، مراجعة بياناتها قبل الحفظ، وإنشاء بطاقتك لمشاركتها عبر QR أو NFC أو vCard.'],
    ['هل يمسح التطبيق بطاقات العمل بالعربية والإنجليزية؟',
     'نعم. يقرأ كارديفاي البطاقات العربية والإنجليزية والثنائية اللغة، ثم يعرض الحقول لمراجعتها قبل إضافتها إلى جهات الاتصال.'],
    ['هل يتوفر ماسح كارديفاي على Android؟',
     'تطبيق الماسح الأصلي متاح حالياً على iPhone وiPad. ويمكنك استخدام منصة كارديفاي على الويب من متصفح Android حديث لإنشاء بطاقتك الرقمية وإدارتها ومشاركتها.'],
    ['هل تغادر صورة بطاقة العمل هاتفي؟',
     'يتم المسح القياسي وتنظيف بيانات الاتصال على جهازك، ولا تُرفع صورة البطاقة. وإذا اخترت بنفسك ميزة Pro لإعادة قراءة بطاقة صعبة عبر الخادم، تُرسل صورة البطاقة التي حددتها لتلك المرة فقط.'],
    ['هل يحتاج مستلم بطاقتي إلى تطبيق كارديفاي؟',
     'لا. تفتح بطاقتك الرقمية كصفحة ويب في متصفح المستلم، ويمكنه حفظ بياناتك دون تثبيت التطبيق.'],
    ['ما الميزات المجانية؟',
     'تحميل التطبيق والمسح القياسي ومراجعة الحقول وحفظ جهات الاتصال مجانية. تضيف Pro ميزات Apple Wallet، والنسخ الاحتياطي والمزامنة بين الأجهزة، واستيراد جهات اتصال iPhone، والقوالب المميزة ورفع الشعار، وإعادة القراءة الاختيارية عبر الخادم. طلبات الطباعة منفصلة.'],
] : [
    ['What is the Cardify iPhone app?',
     'Cardify is a business card scanner and digital business card platform for iPhone and iPad. Scan a card, review its details before saving, then create your own card to share by QR, NFC, or vCard.'],
    ['Does Cardify scan Arabic and English business cards?',
     'Yes. Cardify reads Arabic, English, and bilingual business cards, then shows the fields for review before adding them to your contacts.'],
    ['Is the Cardify scanner available on Android?',
     'The native scanner is currently available for iPhone and iPad. You can use the Cardify web platform in a modern Android browser to create, manage, and share your digital business card.'],
    ['Does a business card image leave my phone?',
     'Standard scanning and contact cleanup run on your device, so the card image is not uploaded. If you explicitly choose the optional Pro server-assisted reread for a difficult card, only the card image selected for that reread is sent.'],
    ['Does someone need the Cardify app to view my card?',
     'No. Your digital card opens as a web page in the recipient\'s browser, and they can save your details without installing the app.'],
    ['What can I use for free?',
     'Downloading the app, standard scanning, reviewing fields, and saving contacts are free. Pro adds Apple Wallet, cross-device backup and sync, iPhone contact import, premium card templates and logo upload, and the optional server-assisted reread. Printed card orders are separate.'],
];
$ld = [
    '@context' => 'https://schema.org',
    '@graph' => [
        AppEntity::node(),
        [
            '@type' => 'FAQPage',
            '@id' => $localeUrl . '#faq',
            'inLanguage' => $isAr ? 'ar' : 'en',
            'mainEntity' => array_map(static fn($q) => [
                '@type' => 'Question',
                'name' => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
            ], $faq),
        ],
    ],
];
$extraHead = '<script type="application/ld+json">'
    . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . '</script>';
require_once INCLUDES_DIR . '/ui-header.php';
?>
<style>
/* These utilities are used by app.php but exist in NO loaded stylesheet
   (verified: text-gray-950, bg-gray-950, hover:bg-indigo-700,
   hover:border-indigo-300 -> 0 definitions). Unstyled, the primary CTA in the
   brand band inherited text-white and rendered white-on-white, and both phone
   frames rendered transparent. Defined here against the real Cardify brand
   (#009bc1 cyan / #824598 purple) rather than the indigo they used. */
.app-cta-brand{background:#009bc1}
.app-cta-brand:hover{background:#007e9e}
.app-cta-ghost:hover{border-color:#7fd0e3}
.app-device{background:#101828}
.app-badge{background:#e6f5f9;color:#00708c}
.app-feature-icon{background:#e6f5f9;color:#00718c}
.app-cta-onbrand{color:#0d1b21}
.app-heading{color:#101828}
/* The purge/subset tailwind build on this site omits these too. Same bug class
   as the four above, found by diffing every class on this page against the six
   loaded stylesheets rather than only checking the ones already suspected. */
.rounded-3xl{border-radius:1.5rem}
.p-7{padding:1.75rem}
.pb-20{padding-bottom:5rem}
.pb-24{padding-bottom:6rem}
.gap-14{gap:3.5rem}
.mt-auto{margin-top:auto}
.opacity-70{opacity:.7}
.text-white\/70{color:rgba(255,255,255,.7)}
.text-white\/85{color:rgba(255,255,255,.85)}
@media (min-width:640px){.sm\:p-10{padding:2.5rem}.sm\:text-6xl{font-size:3.75rem;line-height:1}}
/* .hidden sm:block never showed this at any width. Hide it below 640px only. */
.app-phone-2{display:block}
@media (max-width:639px){.app-phone-2{display:none}}
</style>
<main class="min-h-screen bg-gray-50" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
    <section class="relative overflow-hidden pt-32 pb-20">
        <div class="absolute inset-0" style="background:radial-gradient(circle at top left,rgba(79,70,229,.18),transparent 42%),radial-gradient(circle at bottom right,rgba(37,99,235,.12),transparent 38%)"></div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-2 gap-14 items-center">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full app-badge px-4 py-2 text-sm font-semibold mb-6">
                    <i class="fa-brands fa-apple" aria-hidden="true"></i>
                    <?= $isAr ? 'تطبيق أصلي لأجهزة iPhone وiPad' : 'Native on iPhone and iPad' ?>
                </div>
                <h1 class="text-5xl sm:text-6xl font-extrabold tracking-tight app-heading leading-tight mb-6">
                    <?= $isAr ? 'امسح بطاقات العمل وشارك بطاقتك.' : 'Scan business cards. Share yours.' ?>
                </h1>
                <p class="text-xl text-gray-600 leading-relaxed max-w-xl mb-8">
                    <?= $isAr
                        ? 'كارديفاي تطبيق مجاني للتحميل يمسح بطاقات العمل بالعربية والإنجليزية على iPhone وiPad. راجع الحقول قبل الحفظ، ثم أنشئ بطاقتك الرقمية لمشاركتها عبر QR أو NFC أو vCard.'
                        : 'Cardify is a free-to-download business card scanner for iPhone and iPad. Read Arabic and English cards on your device, review every field before saving, then create your own digital card to share by QR, NFC, or vCard.' ?>
                </p>
                <div class="flex flex-wrap gap-3">
                    <?php if ($openUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($nativeUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-3 rounded-2xl text-white px-6 py-4 font-bold app-cta-brand transition-colors">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                            <?= $isAr ? 'فتح البطاقة في التطبيق' : 'Open card in the app' ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($appStoreUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-3 rounded-2xl px-6 py-4 font-bold transition-colors" style="background:#101828;color:#fff">
                        <i class="fa-brands fa-apple text-xl" aria-hidden="true"></i>
                        <span><span class="block uppercase tracking-wider opacity-70" style="font-size:10px"><?= $isAr ? 'حمّله من' : 'Download on the' ?></span>App Store</span>
                    </a>
                    <a href="/login.php" class="inline-flex items-center gap-3 rounded-2xl bg-white border border-gray-200 text-gray-900 px-6 py-4 font-bold app-cta-ghost transition-colors">
                        <?= $isAr ? 'إدارة الحساب على الويب' : 'Manage on the web' ?>
                    </a>
                </div>
                <p class="mt-5 text-sm text-gray-500">
                    <?= $isAr ? 'نفس الحساب والبطاقة والاشتراك على التطبيق والموقع.' : 'The same account, card, and subscription across the app and website.' ?>
                </p>
            </div>
            <div class="relative flex justify-center gap-5">
                <div class="rounded-3xl app-device p-2 shadow-2xl" style="width:min(260px,42vw);transform:rotate(-3deg)">
                    <img src="/assets/images/mobile/cardify-native-onboarding.png" alt="<?= $isAr ? 'شاشة الترحيب في تطبيق Cardify' : 'Cardify native onboarding screen' ?>" class="w-full rounded-3xl">
                </div>
                <div class="app-phone-2 rounded-3xl app-device p-2 shadow-2xl" style="width:260px;transform:rotate(3deg) translateY(3rem)">
                    <img src="/assets/images/mobile/cardify-native-contacts.png" alt="<?= $isAr ? 'جهات الاتصال في تطبيق Cardify' : 'Cardify native contacts screen' ?>" class="w-full rounded-3xl">
                </div>
            </div>
        </div>
    </section>
    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pb-24">
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php
            $features = $isAr ? [
                ['camera', 'مسح أصلي مع مراجعة', 'التقط وجهي البطاقة واقرأ QR وأكّد النوع والبيانات قبل الحفظ.'],
                ['wave-square', 'قارئ وكاتب NFC', 'اقرأ الوسوم القريبة أو اكتب رابط بطاقتك وبيانات التواصل على وسم NFC.'],
                ['vault', 'خزنة خاصة على الجهاز', 'احتفظ ببطاقات الدفع والولاء والهوية محليًا مع حماية للصور الحساسة.'],
                ['address-book', 'جهات اتصال أذكى', 'اعرض بطاقات كارديفاي وجهات اتصال آيفون معًا ورتّب التكرارات وأخطاء الأرقام.'],
                ['wallet', 'مشاركة وApple Wallet', 'شارك بطاقتك عبر QR أو الرابط أو vCard أو NFC وأضفها إلى Apple Wallet.'],
                ['arrows-rotate', 'حساب واحد ومزامنة حقيقية', 'البطاقات والتصاميم والفريق والتحليلات تستخدم نفس حساب cardify.om.'],
            ] : [
                ['camera', 'Native scanning with review', 'Capture both sides, read QR codes, then confirm the card type and every field before saving.'],
                ['wave-square', 'NFC reader and writer', 'Read nearby tags or write your card link and contact details to an NFC tag.'],
                ['vault', 'Private on-device Vault', 'Keep payment, loyalty, and ID cards locally with protection for sensitive photos.'],
                ['address-book', 'Smarter contacts', 'See Cardify cards and iPhone contacts together, then tidy duplicates and number issues.'],
                ['wallet', 'Sharing and Apple Wallet', 'Share by QR, link, vCard, or NFC, and add your digital card to Apple Wallet.'],
                ['arrows-rotate', 'One account, real sync', 'Cards, designs, teams, and analytics use the same cardify.om account.'],
            ];
            foreach ($features as [$icon, $title, $body]): ?>
                <article class="rounded-3xl bg-white border border-gray-100 p-7 shadow-sm">
                    <div class="w-12 h-12 rounded-2xl app-feature-icon flex items-center justify-center mb-5"><i class="fa-solid fa-<?= $icon ?>"></i></div>
                    <h2 class="text-xl font-bold app-heading mb-2"><?= htmlspecialchars($title) ?></h2>
                    <p class="text-gray-600 leading-relaxed"><?= htmlspecialchars($body) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <!-- Internal link to the scanner surface. Without it that page is an
             orphan: nothing on the site pointed at it, and a page no page
             links to is a page crawlers reach late and rank low. -->
        <p class="mt-8 text-center text-gray-600">
            <?= $isAr ? 'تفاصيل القراءة الضوئية للبطاقات العربية والإنجليزية على الجهاز: ' : 'How the on-device Arabic and English card recognition works: ' ?>
            <a href="<?= $isAr ? '/ar/business-card-scanner' : '/business-card-scanner' ?>" class="font-semibold" style="color:#00718c"><?= $isAr ? 'ماسح بطاقات العمل' : 'the business card scanner' ?></a>
        </p>
        <div class="mt-10 rounded-3xl p-8 sm:p-10 text-white overflow-hidden relative" style="background:linear-gradient(135deg,#009bc1,#824598)">
            <div class="relative grid lg:grid-cols-[1fr_auto] gap-8 items-center">
                <div class="max-w-3xl">
                    <p class="text-sm font-bold uppercase tracking-widest text-white/70 mb-3">
                        <?= $isAr ? 'التطبيق والموقع، معًا' : 'The app and website, together' ?>
                    </p>
                    <h2 class="text-3xl sm:text-4xl font-extrabold mb-4">
                        <?= $isAr ? 'حدّث مرة واحدة، وشارك النسخة الأحدث في كل مكان.' : 'Update once, share the latest version everywhere.' ?>
                    </h2>
                    <p class="text-lg leading-relaxed text-white/85">
                        <?= $isAr
                            ? 'عدّل بطاقتك وتصميمك وفريقك على التطبيق أو cardify.om. تفتح روابط QR وNFC البطاقة الرقمية الصحيحة، وتبقى صلاحيات الحساب والشركات والاشتراك موحّدة.'
                            : 'Edit your card, design, and team in the app or on cardify.om. QR and NFC links open the right live digital card, while account, company, and subscription access stays consistent.' ?>
                    </p>
                </div>
                <a href="/login.php" class="inline-flex items-center justify-center gap-3 rounded-2xl bg-white app-cta-onbrand px-6 py-4 font-bold hover:bg-gray-50 transition-colors">
                    <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                    <?= $isAr ? 'فتح حسابي على الويب' : 'Open my web account' ?>
                </a>
            </div>
        </div>
        <div class="mt-16 max-w-4xl mx-auto">
            <h2 class="text-3xl font-bold app-heading mb-8"><?= $isAr ? 'أسئلة عن تطبيق كارديفاي' : 'Questions about the Cardify app' ?></h2>
            <div class="space-y-4">
                <?php foreach ($faq as [$question, $answer]): ?>
                    <article class="rounded-2xl bg-white border border-gray-100 p-6 shadow-sm">
                        <h3 class="font-bold app-heading mb-2"><?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="text-gray-600 leading-relaxed"><?= htmlspecialchars($answer, ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
