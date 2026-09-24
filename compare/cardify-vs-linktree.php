<?php
/**
 * Cardify vs Linktree (EN + /ar/compare/cardify-vs-linktree twin).
 *
 * Every Linktree fact below was read off https://linktr.ee/s/pricing on
 * 24 September 2026 (fetched as HTML, footer and FAQ included). Rules for
 * edits, carried over from the Popl lesson in cardify-vs-popl.php:
 *   1. NEVER state a Linktree price. The plan amounts are not in the served
 *      HTML (the page renders them in the browser), so we did not read one.
 *   2. NEVER make a negative claim about Linktree: no "does not support
 *      Arabic", no "cannot save a contact". We state what Cardify does and
 *      what Linktree's own page says it does, nothing about what it lacks.
 *   3. Keep the "Where Linktree is stronger" section. It is true, and a
 *      comparison that concedes nothing reads as an advert.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/IntentLanding.php';

$lang = IntentLanding::lang();
$isAr = $lang === 'ar';
$L = static function (string $slug) use ($isAr): string { return IntentLanding::link($slug, $isAr); };
$stdPrice = IntentLanding::price('standard', $isAr);
$nfcPrice = IntentLanding::price('nfc', $isAr);
$src = 'https://linktr.ee/s/pricing';

$verify = static function (bool $isAr) use ($src, $L): string {
    $h = static function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    if ($isAr) {
        return '<div class="not-prose my-10 rounded-xl border border-gray-200 bg-gray-50 px-5 py-5 text-sm text-gray-600">'
            . '<p class="font-semibold text-gray-900 mb-2">كيف تحققنا من هذه المعلومات</p>'
            . '<p class="mb-3">قرأنا كل معلومة عن Linktree في هذه الصفحة من صفحة الأسعار الرسمية لـ Linktree بتاريخ 24 سبتمبر 2026، والرابط أدناه لتتحقق بنفسك. الأسعار والميزات تتغير، فإذا وجدت معلومة قديمة أو خاطئة أخبرنا عبر <a class="text-blue-600 font-medium" href="' . $h($L('contact')) . '">صفحة التواصل</a> وسنصححها.</p>'
            . '<p class="mb-2">لا نذكر أسعار Linktree لأن مبالغ الخطط لم تكن ضمن نص الصفحة الذي قرأناه.</p>'
            . '<ul class="list-disc ps-5"><li><a class="text-blue-600" rel="nofollow noopener" target="_blank" href="' . $h($src) . '">صفحة أسعار Linktree</a></li></ul>'
            . '<p class="mt-3">كارديفاي منتج من مجموعة BHD (بن حيدر درويش ش.م.م)، مسقط. أسماء المنتجات الأخرى علامات تجارية لأصحابها، وتُذكر هنا للتعريف والمقارنة فقط.</p>'
            . '</div>';
    }
    return '<div class="not-prose my-10 rounded-xl border border-gray-200 bg-gray-50 px-5 py-5 text-sm text-gray-600">'
        . '<p class="font-semibold text-gray-900 mb-2">How we checked this</p>'
        . '<p class="mb-3">Every Linktree fact on this page was read off Linktree\'s own pricing page on 24 September 2026, linked below so you can check it. Pricing and features change. If something here is out of date or wrong, tell us on <a class="text-blue-600 font-medium" href="' . $h($L('contact')) . '">our contact page</a> and we will correct it.</p>'
        . '<p class="mb-2">We do not quote Linktree prices, because the plan amounts were not in the page text we read.</p>'
        . '<ul class="list-disc pl-5"><li><a class="text-blue-600" rel="nofollow noopener" target="_blank" href="' . $h($src) . '">Linktree pricing page</a></li></ul>'
        . '<p class="mt-3">Cardify is a product of BHD Group (Bin Haider Darwish L.L.C.), Muscat. All other product names are the trademarks of their owners, used here for identification and comparison only.</p>'
        . '</div>';
};

if (!$isAr) {
    $c = [
        'title' => 'Cardify vs Linktree for Business Cards, Compared',
        'desc'  => 'Linktree is a link-in-bio page. Cardify is a company business card system with a Save contact button, Arabic and English, and printing in Oman. What each one does, from their own pages. Checked 24 Sep 2026.',
        'crumb' => 'Cardify vs Linktree',
        'h1'    => 'Cardify vs Linktree: a link page or a business card?',
        'lede'  => 'Linktree\'s own FAQ suggests adding your Linktree to print materials with a QR code, so it often ends up on business cards. It works, but the two products solve different problems. Here is what each one is built for, using Linktree\'s own pricing page and Cardify\'s own product.',
        'cta_primary'   => ['Create your team\'s cards free', $L('company/register.php')],
        'cta_secondary' => ['All comparisons', '/compare'],
        'sections' => [
            ['id' => 'short-answer', 'h2' => 'The short answer',
             'p' => [
                'Linktree describes itself as a link in bio: "one link to share everything you create, curate and sell" from your social profiles. Its pricing page lists plans called Free, Starter, Pro and Premium, plus a custom Agency and Enterprise offer. The paid features are aimed at creators: selling digital products, affiliate links, social media scheduling and Instagram auto-replies.',
                'Cardify is a business card system for companies. Each employee gets a card page with a <strong>Save contact</strong> button that writes their details into the other person\'s phone as a vCard, in Arabic and English. The company owns the cards, sets one design, and can order printed and NFC cards in Oman from the same dashboard.',
                'If you are one creator who wants to send followers to many links, Linktree is built for you. If you are a company that wants every employee to hand out a correct, saveable contact, Cardify is built for you.',
             ]],
            ['id' => 'side-by-side', 'h2' => 'Side by side',
             'table' => [
                'head' => ['', 'Cardify', 'Linktree (from its pricing page)'],
                'rows' => [
                    ['Built for', 'Company business cards for every employee', 'Link-in-bio pages for creators, brands and businesses'],
                    ['Free plan', 'Free web platform, unlimited employees and cards', 'Free plan with "Unlimited basic links"'],
                    ['Paid plans', 'None for the web platform. You pay for printed and NFC cards only.', 'Starter, Pro and Premium, billed monthly. Pro and Premium show "Try free for 7 days".'],
                    ['Teams', 'Company account, departments, staff import from CSV or Excel', 'Team seats on the custom Agency / Enterprise offer'],
                    ['Single sign-on', 'Not offered', 'SSO listed on Agency / Enterprise'],
                    ['Printed cards', 'Printed in Oman, from ' . $stdPrice . ' per 100', 'Not on the pricing page we read'],
                    ['NFC cards', $nfcPrice . ' per card, with QR backup', 'Not on the pricing page we read'],
                ],
             ],
             'after' => ['"Not on the pricing page we read" means only that. It is not a statement about what Linktree offers elsewhere.']],
            ['id' => 'where-cardify-fits', 'h2' => 'What Cardify does for a business card',
             'ul' => [
                '<strong>Save contact in one tap.</strong> The card page downloads a standard <code>.vcf</code> contact file, so the person you met has your number in their phone, not only a link.',
                '<strong>Arabic and English on one card.</strong> Each employee record has a real Arabic field, checked by the employee, shown right-to-left.',
                '<strong>The company owns the cards.</strong> HR imports staff, sets one design, and controls what happens when someone leaves.',
                '<strong>Wallet passes.</strong> Each card can be added to <a href="' . $L('apple-wallet-business-card') . '">Apple Wallet</a> on iPhone or Google Wallet on Android.',
                '<strong>Print and NFC in Oman.</strong> Each printed card carries the employee\'s own QR code. See <a href="' . $L('qr-code-business-card') . '">QR code business cards</a>.',
             ]],
            ['id' => 'where-linktree-is-stronger', 'h2' => 'Where Linktree is stronger',
             'p' => [
                'Linktree does many things that Cardify does not try to do. Its pricing page lists selling digital products and courses, an affiliate shop, social media scheduling, unlimited Instagram auto-replies, mailing list integrations and data export. It also says it has over 70 million users, and it has apps on the App Store and Google Play. If your goal is to grow and earn from a social audience, these are real advantages, and Cardify has no equivalent.',
             ]],
            ['id' => 'use-both', 'h2' => 'Can you use both?',
             'p' => [
                'Yes. You can keep a Linktree for your social profiles and a business card for meetings. A Cardify card can list your social profiles, including a Linktree link, next to your phone and email. The difference is what happens after a scan: a link page shows links, and a Cardify card also saves you as a contact.',
             ]],
        ],
        'faq_h2' => 'Questions about Cardify and Linktree',
        'faq' => [
            ['Is Linktree a digital business card?', 'Linktree describes itself as a link in bio, one link to share everything you create, curate and sell. It can be shared by QR code. Cardify is built as a business card: its card page saves your details as a contact on the other person\'s phone.'],
            ['How much does Linktree cost?', 'Linktree\'s pricing page lists a Free plan and paid Starter, Pro and Premium plans billed monthly, plus custom pricing for Agency and Enterprise. The amounts were not in the page text we read, so check linktr.ee/s/pricing for current prices in your country.'],
            ['How much does Cardify cost?', 'The Cardify web platform is free for unlimited employees and cards. You pay only for printed cards, from ' . $stdPrice . ' per 100, and NFC cards at ' . $nfcPrice . ' each.'],
            ['Which is better for a company team?', 'For a team that needs the same card design for every employee, Arabic and English details, and printed cards in Oman, Cardify is built for that job. Linktree offers team seats on its custom Agency and Enterprise offer.'],
            ['Can I add my Linktree to a Cardify card?', 'Yes. Add it as a Website link in your social links. The card then shows it next to your phone, email and other profiles.'],
        ],
        'related_h2' => 'Other comparisons',
        'related' => [
            ['Cardify vs Popl', '/compare/cardify-vs-popl'],
            ['Cardify vs HiHello', '/compare/cardify-vs-hihello'],
            ['Cardify vs Blinq', '/compare/cardify-vs-blinq'],
            ['Best digital business card in Oman and the GCC', '/compare/best-digital-business-card-gcc'],
        ],
    ];
} else {
    $c = [
        'title' => 'كارديفاي مقابل Linktree لبطاقات الأعمال، مقارنة',
        'desc'  => 'Linktree صفحة روابط للحسابات الاجتماعية، وكارديفاي نظام بطاقات أعمال للشركات مع زر حفظ جهة الاتصال والعربية والإنجليزية والطباعة في عُمان. مقارنة من صفحاتهما الرسمية، بتاريخ 24 سبتمبر 2026.',
        'crumb' => 'كارديفاي مقابل Linktree',
        'h1'    => 'كارديفاي مقابل Linktree: صفحة روابط أم بطاقة أعمال؟',
        'lede'  => 'تقترح الأسئلة الشائعة في Linktree إضافة رابطك إلى المطبوعات برمز QR، فيصل أحياناً إلى بطاقات الأعمال. هذا ممكن، لكن المنتجين يحلان مشكلتين مختلفتين. إليك ما صُمم له كل منهما، من صفحة أسعار Linktree الرسمية ومن منتج كارديفاي نفسه.',
        'cta_primary'   => ['أنشئ بطاقات فريقك مجاناً', $L('company/register.php')],
        'cta_secondary' => ['كل المقارنات (بالإنجليزية)', '/compare'],
        'sections' => [
            ['id' => 'short-answer', 'h2' => 'الإجابة المختصرة',
             'p' => [
                'يصف Linktree نفسه بأنه رابط في السيرة التعريفية، أي رابط واحد تشارك به كل ما تنشئه وتختاره وتبيعه من حساباتك الاجتماعية. وتعرض صفحة أسعاره خططاً باسم Free وStarter وPro وPremium، إضافة إلى عرض مخصص للوكالات والمؤسسات. والميزات المدفوعة موجهة لصناع المحتوى: بيع المنتجات الرقمية، وروابط التسويق بالعمولة، وجدولة المنشورات، والردود الآلية في إنستغرام.',
                'أما كارديفاي فهي نظام بطاقات أعمال للشركات. يحصل كل موظف على صفحة بطاقة فيها زر <strong>حفظ جهة الاتصال</strong> الذي يكتب بياناته في هاتف الطرف الآخر بصيغة vCard، بالعربية والإنجليزية. والشركة تملك البطاقات، وتعتمد تصميماً واحداً، وتطلب البطاقات المطبوعة وبطاقات NFC في عُمان من لوحة التحكم نفسها.',
                'إذا كنت صانع محتوى تريد توجيه متابعيك إلى روابط كثيرة، فـ Linktree مصمم لك. وإذا كنت شركة تريد أن يقدم كل موظف جهة اتصال صحيحة قابلة للحفظ، فكارديفاي مصممة لك.',
             ]],
            ['id' => 'side-by-side', 'h2' => 'مقارنة جنباً إلى جنب',
             'table' => [
                'head' => ['', 'كارديفاي', 'Linktree (من صفحة أسعاره)'],
                'rows' => [
                    ['مصمم من أجل', 'بطاقات أعمال الشركة لكل موظف', 'صفحات روابط لصناع المحتوى والعلامات التجارية والشركات'],
                    ['الخطة المجانية', 'منصة ويب مجانية بعدد غير محدود من الموظفين والبطاقات', 'خطة مجانية مع روابط أساسية غير محدودة'],
                    ['الخطط المدفوعة', 'لا توجد لمنصة الويب، وتدفع فقط مقابل البطاقات المطبوعة وبطاقات NFC', 'Starter وPro وPremium باشتراك شهري، مع تجربة مجانية 7 أيام لخطتي Pro وPremium'],
                    ['الفرق', 'حساب للشركة وأقسام واستيراد الموظفين من CSV أو Excel', 'مقاعد للفريق ضمن عرض الوكالات والمؤسسات المخصص'],
                    ['الدخول الموحد SSO', 'غير متاح', 'مذكور ضمن عرض الوكالات والمؤسسات'],
                    ['البطاقات المطبوعة', 'تُطبع في عُمان، من ' . $stdPrice . ' لكل 100 بطاقة', 'غير مذكورة في صفحة الأسعار التي قرأناها'],
                    ['بطاقات NFC', $nfcPrice . ' للبطاقة، مع رمز QR احتياطي', 'غير مذكورة في صفحة الأسعار التي قرأناها'],
                ],
             ],
             'after' => ['عبارة "غير مذكورة في صفحة الأسعار التي قرأناها" تعني ذلك فقط، وليست حكماً على ما يقدمه Linktree في صفحات أخرى.']],
            ['id' => 'where-cardify-fits', 'h2' => 'ما تقدمه كارديفاي لبطاقة الأعمال',
             'ul' => [
                '<strong>حفظ جهة الاتصال بضغطة.</strong> تنزّل صفحة البطاقة ملف جهة اتصال قياسياً بصيغة <code>.vcf</code>، فيحفظ من التقيت به رقمك في هاتفه، لا مجرد رابط.',
                '<strong>العربية والإنجليزية في بطاقة واحدة.</strong> لكل موظف حقل عربي حقيقي يراجعه بنفسه، ويُعرض من اليمين إلى اليسار.',
                '<strong>الشركة تملك البطاقات.</strong> تستورد الموارد البشرية الموظفين، وتعتمد تصميماً واحداً، وتتحكم بما يحدث عند مغادرة أي موظف.',
                '<strong>بطاقات المحفظة.</strong> يمكن إضافة كل بطاقة إلى <a href="' . $L('apple-wallet-business-card') . '">Apple Wallet</a> على الآيفون أو Google Wallet على أندرويد.',
                '<strong>الطباعة وNFC في عُمان.</strong> تحمل كل بطاقة مطبوعة رمز QR الخاص بالموظف. اطلع على <a href="' . $L('qr-code-business-card') . '">بطاقات الأعمال برمز QR</a>.',
             ]],
            ['id' => 'where-linktree-is-stronger', 'h2' => 'أين يتفوق Linktree',
             'p' => [
                'يقدم Linktree أشياء كثيرة لا تحاول كارديفاي تقديمها. تذكر صفحة أسعاره بيع المنتجات الرقمية والدورات، ومتجر التسويق بالعمولة، وجدولة منشورات وسائل التواصل، والردود الآلية غير المحدودة في إنستغرام، والربط مع القوائم البريدية، وتصدير البيانات. ويذكر أن لديه أكثر من 70 مليون مستخدم، وله تطبيقات على App Store وGoogle Play. فإذا كان هدفك تنمية جمهورك على وسائل التواصل والربح منه، فهذه مزايا حقيقية لا يوجد ما يقابلها في كارديفاي.',
             ]],
            ['id' => 'use-both', 'h2' => 'هل يمكن استخدام الاثنين معاً؟',
             'p' => [
                'نعم. يمكنك الاحتفاظ بصفحة Linktree لحساباتك الاجتماعية وببطاقة أعمال للاجتماعات. ويمكن لبطاقة كارديفاي أن تعرض حساباتك الاجتماعية، ومنها رابط Linktree، بجانب هاتفك وبريدك. والفرق فيما يحدث بعد المسح: صفحة الروابط تعرض روابط، وبطاقة كارديفاي تحفظك أيضاً كجهة اتصال.',
             ]],
        ],
        'faq_h2' => 'أسئلة عن كارديفاي وLinktree',
        'faq' => [
            ['هل Linktree بطاقة أعمال رقمية؟', 'يصف Linktree نفسه بأنه رابط في السيرة التعريفية، رابط واحد لمشاركة كل ما تنشئه وتختاره وتبيعه، ويمكن مشاركته برمز QR. أما كارديفاي فمصممة كبطاقة أعمال، وصفحة بطاقتها تحفظ بياناتك كجهة اتصال في هاتف الطرف الآخر.'],
            ['كم تكلفة Linktree؟', 'تعرض صفحة أسعار Linktree خطة مجانية وخطط Starter وPro وPremium المدفوعة شهرياً، مع تسعير مخصص للوكالات والمؤسسات. لم تكن المبالغ ضمن نص الصفحة الذي قرأناه، فراجع linktr.ee/s/pricing لمعرفة الأسعار الحالية في بلدك.'],
            ['كم تكلفة كارديفاي؟', 'منصة كارديفاي على الويب مجانية لعدد غير محدود من الموظفين والبطاقات. تدفع فقط مقابل البطاقات المطبوعة من ' . $stdPrice . ' لكل 100 بطاقة، وبطاقات NFC بسعر ' . $nfcPrice . ' للبطاقة.'],
            ['أيهما أفضل لفريق شركة؟', 'إذا احتاج الفريق تصميماً موحداً لكل الموظفين وبيانات بالعربية والإنجليزية وبطاقات مطبوعة في عُمان، فكارديفاي مصممة لهذه المهمة. ويقدم Linktree مقاعد للفريق ضمن عرض الوكالات والمؤسسات المخصص.'],
            ['هل يمكنني إضافة رابط Linktree إلى بطاقة كارديفاي؟', 'نعم. أضفه كرابط موقع إلكتروني ضمن روابطك الاجتماعية، فتعرضه البطاقة بجانب هاتفك وبريدك وحساباتك الأخرى.'],
        ],
        'related_h2' => 'مقارنات أخرى (بالإنجليزية)',
        'related' => [
            ['كارديفاي مقابل Popl', '/compare/cardify-vs-popl'],
            ['كارديفاي مقابل HiHello', '/compare/cardify-vs-hihello'],
            ['كارديفاي مقابل Blinq', '/compare/cardify-vs-blinq'],
            ['أفضل بطاقة أعمال رقمية في عُمان والخليج', '/compare/best-digital-business-card-gcc'],
        ],
    ];
}

$c['path']   = '/compare/cardify-vs-linktree';
$c['file']   = __FILE__;
$c['verify'] = $verify($isAr);
$c['crumbs'] = [
    [$isAr ? 'الرئيسية' : 'Home', $isAr ? '/ar/' : '/'],
    [$isAr ? 'المقارنات' : 'Compare', '/compare'],
];
IntentLanding::render($c, $isAr);
