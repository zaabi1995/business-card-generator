<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
$isAr = (class_exists('I18n') && I18n::getLocale() === 'ar') || (($_GET['lang'] ?? '') === 'ar');
$pageTitle = $isAr ? 'تطبيق Cardify لأجهزة iPhone' : 'Cardify for iPhone';
$pageDescription = $isAr
    ? 'امسح بطاقات العمل، احفظ جهات الاتصال، وشارك بطاقتك الرقمية من تطبيق Cardify الأصلي.'
    : 'Scan business cards, save contacts, and share your digital card from the native Cardify app.';
$canonicalUrl = 'https://cardify.om/app';
$showNavigation = true;
$openUrl = trim((string) ($_GET['url'] ?? ''));
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
                    <a href="<?= htmlspecialchars($appStoreUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-3 rounded-2xl bg-gray-950 text-white px-6 py-4 font-bold hover:bg-black transition-colors">
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
        <div class="grid md:grid-cols-3 gap-5">
            <?php
            $features = $isAr ? [
                ['camera', 'مسح أصلي وسريع', 'التقاط أمام وخلف البطاقة، قراءة QR، ومراجعة البيانات قبل الحفظ.'],
                ['arrows-rotate', 'مزامنة حقيقية', 'البطاقات والتصاميم والفريق والتحليلات تستخدم نفس حساب cardify.om.'],
                ['wallet', 'بطاقتك في Wallet', 'شارك بطاقتك الرقمية وأضفها إلى Apple Wallet من التطبيق.'],
            ] : [
                ['camera', 'Fast native scanning', 'Capture both sides, read QR codes, and review every field before saving.'],
                ['arrows-rotate', 'Real account sync', 'Cards, designs, teams, and analytics use the same cardify.om account.'],
                ['wallet', 'Your card in Wallet', 'Share your digital card and add it to Apple Wallet from the app.'],
            ];
            foreach ($features as [$icon, $title, $body]): ?>
                <article class="rounded-3xl bg-white border border-gray-100 p-7 shadow-sm">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-5"><i class="fa-solid fa-<?= $icon ?>"></i></div>
                    <h2 class="text-xl font-bold text-gray-950 mb-2"><?= htmlspecialchars($title) ?></h2>
                    <p class="text-gray-600 leading-relaxed"><?= htmlspecialchars($body) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
