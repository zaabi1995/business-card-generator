<?php
/** Public account and data deletion instructions for Cardify mobile users. */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = $lang === 'ar';
$pageTitle = $isAr
    ? 'حذف حساب وبيانات كارديفاي'
    : 'Cardify account and data deletion';
$pageDescription = $isAr
    ? 'خطوات حذف حساب كارديفاي والبيانات المرتبطة به، وما يتم الاحتفاظ به مؤقتاً.'
    : 'How to delete a Cardify account and its associated data, including temporary retention details.';
$canonicalUrl = 'https://cardify.om' . ($isAr ? '/ar' : '') . '/delete-account';
$showNavigation = true;
$bodyClass = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes = $isAr ? 'dir="rtl" lang="ar"' : 'lang="en"';

require_once INCLUDES_DIR . '/ui-header.php';

Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', $isAr ? '/ar/' : '/'],
    [$pageTitle, $canonicalUrl],
]);

$content = $isAr ? [
    'eyebrow' => 'الخصوصية والتحكم',
    'intro' => 'يمكنك حذف حساب كارديفاي وبيانات التطبيق المرتبطة به في أي وقت. لا تحتاج إلى مراسلتنا إذا كان بإمكانك الدخول إلى التطبيق.',
    'steps_title' => 'الحذف من داخل التطبيق',
    'steps' => [
        'افتح تطبيق كارديفاي وسجّل الدخول إلى الحساب المطلوب.',
        'افتح الإعدادات، ثم انتقل إلى قسم الحساب.',
        'اضغط حذف الحساب، وراجع التنبيه، ثم أكّد الحذف.',
        'يسجّل التطبيق خروجك بعد تأكيد الخادم اكتمال العملية.',
    ],
    'external_title' => 'إذا لم تتمكن من الدخول',
    'external' => 'أرسل طلبك من البريد أو رقم الهاتف المرتبط بالحساب إلى privacy@cardify.om. اكتب "طلب حذف حساب كارديفاي" واذكر وسيلة الدخول المرتبطة بالحساب. سنطلب فقط ما يلزم للتحقق من ملكيتك للحساب.',
    'deleted_title' => 'البيانات التي يتم حذفها',
    'deleted' => [
        'حساب تطبيق كارديفاي ومعرّفات تسجيل الدخول ورموز الجلسات.',
        'البطاقات وجهات الاتصال والملاحظات والوسوم والصور التي تمت مزامنتها مع الحساب.',
        'تفضيلات البطاقة والمحفظة وبيانات الاشتراك المرتبطة بحساب التطبيق.',
        'رموز الإشعارات وبيانات المزامنة الخاصة بالحساب.',
    ],
    'retained_title' => 'البيانات التي يتم الاحتفاظ بها',
    'retained' => [
        'قد نحتفظ بسجل أمني محدود للعملية لمدة تصل إلى 30 يوماً لمنع إعادة التنفيذ والاحتيال، ثم تتم إزالته تلقائياً.',
        'سجلات الشركة والموظف وحساب الويب لا تُحذف إذا كانت مُدارة بشكل مستقل عن حساب تطبيق كارديفاي. يمكن لمسؤول الشركة إدارتها من نظام كارديفاي.',
        'قد نحتفظ ببيانات محدودة مدة أطول فقط عندما يفرض القانون ذلك، مثل سجلات معاملات الدفع أو الفواتير.',
    ],
    'help' => 'هل تحتاج إلى مساعدة؟',
    'contact' => 'راسل privacy@cardify.om أو استخدم صفحة التواصل.',
    'contact_link' => 'تواصل معنا',
] : [
    'eyebrow' => 'Privacy and control',
    'intro' => 'You can delete your Cardify account and its associated app data at any time. You do not need to contact us when you can access the app.',
    'steps_title' => 'Delete in the app',
    'steps' => [
        'Open Cardify and sign in to the account you want to delete.',
        'Open Settings, then go to the Account section.',
        'Tap Delete account, review the warning, and confirm deletion.',
        'The app signs you out after the server confirms completion.',
    ],
    'external_title' => 'If you cannot access the app',
    'external' => 'Send a request from the email address or phone number linked to the account to privacy@cardify.om. Use the subject "Cardify account deletion request" and include the sign-in identifier linked to the account. We ask only for the information required to verify account ownership.',
    'deleted_title' => 'Data deleted',
    'deleted' => [
        'Your Cardify app account, sign-in identifiers, and session tokens.',
        'Cards, contacts, notes, tags, and images synced to the account.',
        'Card, Wallet, and subscription preferences associated with the app account.',
        'Push-notification tokens and account-specific sync data.',
    ],
    'retained_title' => 'Data retained',
    'retained' => [
        'A limited security record of the deletion operation may be retained for up to 30 days to prevent replay and fraud, then removed automatically.',
        'Company, employee, and web-account records are not deleted when they are managed separately from the Cardify app account. A company administrator can manage those records in Cardify.',
        'Limited records may be kept longer only when legally required, such as payment transaction or invoice records.',
    ],
    'help' => 'Need help?',
    'contact' => 'Email privacy@cardify.om or use the contact page.',
    'contact_link' => 'Contact us',
];
?>

<main class="min-h-screen bg-gray-50">
    <header class="bg-white pt-28 pb-14">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-semibold uppercase tracking-wider text-cyan-700 mb-4"><?= htmlspecialchars($content['eyebrow']) ?></p>
            <h1 class="text-4xl sm:text-5xl font-bold text-gray-950 mb-5"><?= htmlspecialchars($pageTitle) ?></h1>
            <p class="text-lg text-gray-600 leading-relaxed max-w-3xl"><?= htmlspecialchars($content['intro']) ?></p>
        </div>
    </header>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 space-y-8">
        <section class="bg-white rounded-2xl shadow-sm p-7 sm:p-10">
            <h2 class="text-2xl font-bold text-gray-950 mb-5"><?= htmlspecialchars($content['steps_title']) ?></h2>
            <ol class="list-decimal <?= $isAr ? 'pr-6' : 'pl-6' ?> space-y-3 text-gray-700 leading-relaxed">
                <?php foreach ($content['steps'] as $step): ?>
                    <li><?= htmlspecialchars($step) ?></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="bg-cyan-50 border border-cyan-100 rounded-2xl p-7 sm:p-10">
            <h2 class="text-2xl font-bold text-gray-950 mb-4"><?= htmlspecialchars($content['external_title']) ?></h2>
            <p class="text-gray-700 leading-relaxed"><?= htmlspecialchars($content['external']) ?></p>
        </section>

        <div class="grid md:grid-cols-2 gap-8">
            <section class="bg-white rounded-2xl shadow-sm p-7 sm:p-8">
                <h2 class="text-2xl font-bold text-gray-950 mb-5"><?= htmlspecialchars($content['deleted_title']) ?></h2>
                <ul class="list-disc <?= $isAr ? 'pr-6' : 'pl-6' ?> space-y-3 text-gray-700 leading-relaxed">
                    <?php foreach ($content['deleted'] as $item): ?>
                        <li><?= htmlspecialchars($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="bg-white rounded-2xl shadow-sm p-7 sm:p-8">
                <h2 class="text-2xl font-bold text-gray-950 mb-5"><?= htmlspecialchars($content['retained_title']) ?></h2>
                <ul class="list-disc <?= $isAr ? 'pr-6' : 'pl-6' ?> space-y-3 text-gray-700 leading-relaxed">
                    <?php foreach ($content['retained'] as $item): ?>
                        <li><?= htmlspecialchars($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>

        <section class="bg-gray-950 text-white rounded-2xl p-7 sm:p-10">
            <h2 class="text-2xl font-bold mb-3"><?= htmlspecialchars($content['help']) ?></h2>
            <p class="text-gray-300 mb-5"><?= htmlspecialchars($content['contact']) ?></p>
            <a href="<?= $isAr ? '/ar/contact' : '/contact' ?>" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 font-semibold text-gray-950 hover:bg-gray-100">
                <?= htmlspecialchars($content['contact_link']) ?>
            </a>
        </section>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
