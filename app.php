<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
$isAr = (class_exists('I18n') && I18n::getLocale() === 'ar') || (($_GET['lang'] ?? '') === 'ar');
$pageTitle = $isAr ? 'تطبيق Cardify لأجهزة iPhone' : 'Cardify for iPhone';
$pageDescription = $isAr
    ? 'امسح بطاقات العمل، احفظ جهات الاتصال، وشارك بطاقتك الرقمية من تطبيق Cardify الأصلي.'
    : 'Scan business cards, save contacts, and share your digital card from the native Cardify app.';
$canonicalUrl = 'https://cardify.om/app';
$showNavigation = true;
$openUrl = normalizeCardifyUrl((string) ($_GET['url'] ?? '')) ?? '';
$nativeUrl = $openUrl !== '' ? 'cardifyscan://app/open?url=' . rawurlencode($openUrl) : 'cardifyscan://';
$appStoreUrl = 'https://apps.apple.com/app/id6790749589';
require_once INCLUDES_DIR . '/ui-header.php';
?>
<main class="min-h-screen bg-gray-50" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
    <section class="relative overflow-hidden pt-32 pb-20">
        <div class="absolute inset-0" style="background:radial-gradient(circle at top left,rgba(79,70,229,.18),transparent 42%),radial-gradient(circle at bottom right,rgba(37,99,235,.12),transparent 38%)"></div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-2 gap-14 items-center">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full bg-indigo-50 text-indigo-700 px-4 py-2 text-sm font-semibold mb-6">
                    <i class="fa-brands fa-apple" aria-hidden="true"></i>
                    <?= $isAr ? 'تطبيق أصلي لأجهزة iPhone وiPad' : 'Native on iPhone and iPad' ?>
                </div>
                <h1 class="text-5xl sm:text-6xl font-extrabold tracking-tight text-gray-950 leading-tight mb-6">
                    <?= $isAr ? 'كل بطاقة عمل، جاهزة للتواصل.' : 'Every business card, ready to connect.' ?>
                </h1>
                <p class="text-xl text-gray-600 leading-relaxed max-w-xl mb-8">
                    <?= $isAr
                        ? 'امسح البطاقة بالكاميرا، راجع البيانات، واحفظها كجهة اتصال. بطاقتك الرقمية وفريقك وتصاميمك متزامنة مع cardify.om.'
                        : 'Scan with the camera, review the details, and save the contact. Your digital card, team, designs, and analytics stay in sync with cardify.om.' ?>
                </p>
                <div class="flex flex-wrap gap-3">
                    <?php if ($openUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($nativeUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-3 rounded-2xl bg-indigo-600 text-white px-6 py-4 font-bold hover:bg-indigo-700 transition-colors">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                            <?= $isAr ? 'فتح البطاقة في التطبيق' : 'Open card in the app' ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($appStoreUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-3 rounded-2xl px-6 py-4 font-bold transition-colors" style="background:#101828;color:#fff">
                        <i class="fa-brands fa-apple text-xl" aria-hidden="true"></i>
                        <span><span class="block uppercase tracking-wider opacity-70" style="font-size:10px"><?= $isAr ? 'حمّله من' : 'Download on the' ?></span>App Store</span>
                    </a>
                    <a href="/login.php" class="inline-flex items-center gap-3 rounded-2xl bg-white border border-gray-200 text-gray-900 px-6 py-4 font-bold hover:border-indigo-300 transition-colors">
                        <?= $isAr ? 'إدارة الحساب على الويب' : 'Manage on the web' ?>
                    </a>
                </div>
                <p class="mt-5 text-sm text-gray-500">
                    <?= $isAr ? 'نفس الحساب والبطاقة والاشتراك على التطبيق والموقع.' : 'The same account, card, and subscription across the app and website.' ?>
                </p>
            </div>
            <div class="relative flex justify-center gap-5">
                <div class="rounded-3xl bg-gray-950 p-2 shadow-2xl" style="width:min(260px,42vw);transform:rotate(-3deg)">
                    <img src="/assets/images/mobile/cardify-native-onboarding.png" alt="<?= $isAr ? 'شاشة الترحيب في تطبيق Cardify' : 'Cardify native onboarding screen' ?>" class="w-full rounded-3xl">
                </div>
                <div class="hidden sm:block rounded-3xl bg-gray-950 p-2 shadow-2xl" style="width:260px;transform:rotate(3deg) translateY(3rem)">
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
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-5"><i class="fa-solid fa-<?= $icon ?>"></i></div>
                    <h2 class="text-xl font-bold text-gray-950 mb-2"><?= htmlspecialchars($title) ?></h2>
                    <p class="text-gray-600 leading-relaxed"><?= htmlspecialchars($body) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
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
                <a href="/login.php" class="inline-flex items-center justify-center gap-3 rounded-2xl bg-white text-gray-950 px-6 py-4 font-bold hover:bg-gray-50 transition-colors">
                    <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                    <?= $isAr ? 'فتح حسابي على الويب' : 'Open my web account' ?>
                </a>
            </div>
        </div>
    </section>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
