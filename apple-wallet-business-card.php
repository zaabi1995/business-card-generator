<?php
/**
 * Landing page: "Apple Wallet business card" (EN + /ar/ twin).
 *
 * Facts, all read from the code on 24 Sep 2026:
 *   - wallet_apple.php builds a storeCard pass: name and job title on the
 *     front, phone and email in the field row, a square QR code whose message
 *     is the employee's live card URL, and tappable phone, email, website,
 *     social and card links on the back. Labels switch to Arabic with lang=ar.
 *   - wallet_google.php builds the Google Wallet version.
 *   - digital_card.php shows the Apple button on iPhone and iPad only, and the
 *     Google button everywhere else (rule 41 in the cardify skill).
 *   - AppleWalletPushNotifier exists but has NO caller, so this page makes no
 *     claim that a saved pass updates itself. It says what is true: the QR on
 *     the pass opens the live card page, and the live page is always current.
 * Prices come from CardCatalogPricing.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/IntentLanding.php';

$lang = IntentLanding::lang();
$isAr = $lang === 'ar';
$L = static function (string $slug) use ($isAr): string { return IntentLanding::link($slug, $isAr); };
$nfcPrice = IntentLanding::price('nfc', $isAr);
$stdPrice = IntentLanding::price('standard', $isAr);

if (!$isAr) {
    $c = [
        'title' => 'Apple Wallet Business Card for Your Team',
        'desc'  => 'Add a business card to Apple Wallet or Google Wallet. Cardify builds a wallet pass for every employee with a scannable QR code, in Arabic or English. Free platform, no app to install.',
        'crumb' => 'Apple Wallet business card',
        'h1'    => 'Apple Wallet business cards for every employee',
        'lede'  => 'Keep your business card in the same place as your boarding pass. Cardify gives each person on your team an Apple Wallet pass, and a Google Wallet pass for Android, that shows a QR code any phone camera can scan.',
        'cta_primary'   => ['Create your team\'s cards free', $L('company/register.php')],
        'cta_secondary' => ['See pricing', $L('pricing')],
        'sections' => [
            ['id' => 'what-it-is', 'h2' => 'What an Apple Wallet business card is',
             'p' => [
                'An Apple Wallet business card is a pass, the same kind of object as a boarding pass or a cinema ticket, that holds your contact details and a QR code. It lives in the Wallet app on iPhone. You open it from the Wallet app, so you do not have to search for an app or a photo in your gallery when someone asks for your number.',
                'On Cardify the pass is not a separate product. It is one more way to open the digital business card your company already created for you. The QR code on the pass points to your live card page, and the other person taps <strong>Save contact</strong> on that page to put your details in their phone.',
             ]],
            ['id' => 'what-is-on-the-pass', 'h2' => 'What is on the pass',
             'p' => ['Each pass is built from the employee record in your Cardify dashboard. Nobody types their details into a second system.'],
             'ul' => [
                '<strong>Front:</strong> your name, your job title, your phone number and your email, with your company logo and colours.',
                '<strong>QR code:</strong> a square code that opens your live card page. The receiver scans it with the normal camera. They do not need an app and they do not need a Cardify account.',
                '<strong>Back of the pass:</strong> tappable links for your phone, email, website, your social profiles and your full digital card.',
                '<strong>Language:</strong> the labels on the pass are in Arabic when the card is opened in Arabic, and in English when it is opened in English.',
             ]],
            ['id' => 'iphone-and-android', 'h2' => 'iPhone and Android both work',
             'p' => [
                'Apple Wallet is only on Apple devices, so Cardify also builds a Google Wallet pass. The card page decides which button to show. An iPhone or iPad shows <strong>Add to Apple Wallet</strong>. Other phones and computers show <strong>Add to Google Wallet</strong>. The page hides the button that cannot work on that device, so nobody taps a button and gets an error.',
                'The person who scans your pass can use any phone. The QR code opens an ordinary web page, so it works on iPhone, Android and any device with a camera and a browser.',
             ]],
            ['id' => 'why-a-wallet-pass', 'h2' => 'Why teams in Oman and the GCC use a wallet pass',
             'ul' => [
                '<strong>Speed at events.</strong> At an exhibition or a majlis you open Wallet and show your code in seconds. You do not look for an app or a photo.',
                '<strong>It works with a weak signal.</strong> The pass and its QR code are stored on your phone, so you can show the code in a basement hall with no signal. The person who scans it needs a connection to open your page.',
                '<strong>No lost cards.</strong> You cannot run out of a wallet pass. It sits next to your printed cards, it does not replace them.',
                '<strong>One source of truth.</strong> The QR code opens the live card page. If your mobile number or title changes, you edit the employee record once and the page shows the new details to everyone who scans after that.',
             ]],
            ['id' => 'how-to-add', 'h2' => 'How to add your card to Apple Wallet',
             'ol' => [
                'Your company creates your digital business card in Cardify. This is free for any number of employees.',
                'Open your card page on your iPhone. The link looks like <em>yourcompany.cardify.om/yourname</em>.',
                'Tap <strong>Add to Apple Wallet</strong>. iPhone shows a preview of the pass.',
                'Tap <strong>Add</strong>. The pass is now in the Wallet app.',
                'At the next meeting, open Wallet, show the QR code, and let the other person scan it.',
             ],
             'after' => ['On Android the steps are the same with <strong>Add to Google Wallet</strong>.']],
            ['id' => 'cost', 'h2' => 'What it costs',
             'p' => [
                'The wallet pass is part of the free Cardify platform. There is no fee per pass and no fee per employee. You pay only if you order physical cards: printed cards start at ' . $stdPrice . ' per 100, and an NFC tap card costs ' . $nfcPrice . ' per card. See the full list on the <a href="' . $L('pricing') . '">pricing page</a>.',
             ]],
        ],
        'faq_h2' => 'Questions about Apple Wallet business cards',
        'faq' => [
            ['Can I add a business card to Apple Wallet without an app?', 'Yes. Open your Cardify card page in Safari on your iPhone and tap Add to Apple Wallet. You do not install a Cardify app to add the pass, and the person who scans it does not install anything either.'],
            ['Does it work on Android?', 'Android phones use Google Wallet. Cardify builds a Google Wallet pass as well, and the card page shows the Google Wallet button on Android. The QR code on either pass can be scanned by any phone.'],
            ['Is the Apple Wallet pass in Arabic?', 'The labels on the pass follow the language of the card you open. Open the Arabic card and the labels are in Arabic. Open the English card and they are in English.'],
            ['What happens when my phone number changes?', 'The QR code on the pass opens your live card page, and that page always shows the current details from your employee record. Change the record once and every new scan shows the new number.'],
            ['Does Cardify charge for wallet passes?', 'No. Apple Wallet and Google Wallet passes are included in the free web platform. On the web platform, Cardify charges only for printed and NFC cards.'],
        ],
        'related_h2' => 'Keep reading',
        'related' => [
            ['What is a digital business card', $L('digital-business-card')],
            ['QR code business cards', $L('qr-code-business-card')],
            ['NFC business cards in Oman', $L('nfc-business-card')],
            ['Business cards for companies and HR teams', $L('business-cards-for-companies')],
            ['Apple Wallet pass, definition', $L('glossary/apple-wallet-pass')],
        ],
    ];
} else {
    $c = [
        'title' => 'بطاقة أعمال في Apple Wallet لكل موظف | كارديفاي',
        'desc'  => 'أضف بطاقة أعمالك إلى Apple Wallet أو Google Wallet. كارديفاي تنشئ بطاقة محفظة لكل موظف برمز QR قابل للمسح، بالعربية أو الإنجليزية. المنصة مجانية ولا تحتاج إلى تطبيق.',
        'crumb' => 'بطاقة أعمال في Apple Wallet',
        'h1'    => 'بطاقات أعمال في Apple Wallet لكل موظفيك',
        'lede'  => 'احتفظ ببطاقة أعمالك في المكان نفسه الذي تحفظ فيه بطاقة صعود الطائرة. تمنح كارديفاي كل موظف في فريقك بطاقة في Apple Wallet، وبطاقة في Google Wallet لهواتف أندرويد، تعرض رمز QR تقرأه كاميرا أي هاتف.',
        'cta_primary'   => ['أنشئ بطاقات فريقك مجاناً', $L('company/register.php')],
        'cta_secondary' => ['اطلع على الأسعار', $L('pricing')],
        'sections' => [
            ['id' => 'what-it-is', 'h2' => 'ما هي بطاقة الأعمال في Apple Wallet',
             'p' => [
                'بطاقة الأعمال في Apple Wallet هي بطاقة رقمية من نوع بطاقة صعود الطائرة أو تذكرة السينما، تحمل بيانات التواصل الخاصة بك ورمز QR. تُحفظ في تطبيق المحفظة على الآيفون، وتفتحها من تطبيق المحفظة مباشرة، فلا تحتاج إلى البحث عن تطبيق أو صورة في معرض الصور عندما يطلب منك أحد رقمك.',
                'في كارديفاي لا تُعد بطاقة المحفظة منتجاً منفصلاً، بل هي طريقة إضافية لفتح بطاقة الأعمال الرقمية التي أنشأتها شركتك لك. رمز QR على البطاقة يفتح صفحة بطاقتك الحية، ثم يضغط الشخص الآخر على <strong>حفظ جهة الاتصال</strong> لتُحفظ بياناتك في هاتفه.',
             ]],
            ['id' => 'what-is-on-the-pass', 'h2' => 'ماذا تعرض البطاقة',
             'p' => ['تُبنى كل بطاقة من سجل الموظف في لوحة تحكم كارديفاي، فلا يُعيد أحد كتابة بياناته في نظام آخر.'],
             'ul' => [
                '<strong>الواجهة:</strong> اسمك ومسماك الوظيفي ورقم هاتفك وبريدك الإلكتروني، مع شعار شركتك وألوانها.',
                '<strong>رمز QR:</strong> رمز مربع يفتح صفحة بطاقتك الحية. يمسحه الطرف الآخر بكاميرا الهاتف العادية، دون تطبيق ودون حساب في كارديفاي.',
                '<strong>ظهر البطاقة:</strong> روابط قابلة للضغط لهاتفك وبريدك وموقعك الإلكتروني وحساباتك في وسائل التواصل وبطاقتك الرقمية كاملة.',
                '<strong>اللغة:</strong> تظهر عناوين الحقول بالعربية عند فتح البطاقة بالعربية، وبالإنجليزية عند فتحها بالإنجليزية.',
             ]],
            ['id' => 'iphone-and-android', 'h2' => 'تعمل على الآيفون وأندرويد',
             'p' => [
                'تطبيق Apple Wallet متاح على أجهزة أبل فقط، لذلك تنشئ كارديفاي أيضاً بطاقة في Google Wallet. وصفحة البطاقة تختار الزر المناسب: على الآيفون والآيباد يظهر زر <strong>الإضافة إلى Apple Wallet</strong>، وعلى الهواتف والأجهزة الأخرى يظهر زر <strong>الإضافة إلى Google Wallet</strong>. وتُخفي الصفحة الزر الذي لا يعمل على الجهاز، فلا يضغط أحد زراً ثم تظهر له رسالة خطأ.',
                'أما من يمسح بطاقتك فيمكنه استخدام أي هاتف، لأن رمز QR يفتح صفحة ويب عادية تعمل على الآيفون وأندرويد وأي جهاز فيه كاميرا ومتصفح.',
             ]],
            ['id' => 'why-a-wallet-pass', 'h2' => 'لماذا تستخدم الفرق في عُمان والخليج بطاقة المحفظة',
             'ul' => [
                '<strong>السرعة في المعارض والمناسبات.</strong> في المعرض أو في المجلس تفتح المحفظة وتعرض رمزك خلال ثوانٍ، دون البحث عن تطبيق أو صورة.',
                '<strong>تعمل مع ضعف الإشارة.</strong> البطاقة ورمزها محفوظان في هاتفك، فتعرض الرمز حتى في قاعة سفلية بلا إشارة. ويحتاج من يمسح الرمز إلى اتصال لفتح صفحتك.',
                '<strong>لا تنفد أبداً.</strong> لا يمكن أن تنفد منك بطاقة المحفظة. وهي تكمل بطاقاتك المطبوعة ولا تلغيها.',
                '<strong>مصدر واحد للبيانات.</strong> رمز QR يفتح صفحة البطاقة الحية. إذا تغير رقمك أو مسماك الوظيفي، تعدّل سجل الموظف مرة واحدة، فتظهر البيانات الجديدة لكل من يمسح الرمز بعد ذلك.',
             ]],
            ['id' => 'how-to-add', 'h2' => 'كيف تضيف بطاقتك إلى Apple Wallet',
             'ol' => [
                'تنشئ شركتك بطاقة أعمالك الرقمية في كارديفاي، وهذا مجاني لأي عدد من الموظفين.',
                'افتح صفحة بطاقتك على الآيفون. يكون الرابط بهذا الشكل: <em>yourcompany.cardify.om/yourname</em>.',
                'اضغط <strong>الإضافة إلى Apple Wallet</strong>، فيعرض الآيفون معاينة للبطاقة.',
                'اضغط <strong>إضافة</strong>، فتصبح البطاقة في تطبيق المحفظة.',
                'في الاجتماع القادم افتح المحفظة واعرض رمز QR ليمسحه الطرف الآخر.',
             ],
             'after' => ['وعلى أندرويد الخطوات نفسها مع زر <strong>الإضافة إلى Google Wallet</strong>.']],
            ['id' => 'cost', 'h2' => 'التكلفة',
             'p' => [
                'بطاقة المحفظة جزء من منصة كارديفاي المجانية، فلا رسوم على البطاقة ولا على الموظف. تدفع فقط إذا طلبت بطاقات مطبوعة: تبدأ البطاقات المطبوعة من ' . $stdPrice . ' لكل 100 بطاقة، وتكلف بطاقة NFC ' . $nfcPrice . ' للبطاقة الواحدة. اطلع على القائمة الكاملة في <a href="' . $L('pricing') . '">صفحة الأسعار</a>.',
             ]],
        ],
        'faq_h2' => 'أسئلة عن بطاقات الأعمال في Apple Wallet',
        'faq' => [
            ['هل يمكنني إضافة بطاقة أعمال إلى Apple Wallet دون تطبيق؟', 'نعم. افتح صفحة بطاقتك في كارديفاي عبر متصفح Safari على الآيفون واضغط الإضافة إلى Apple Wallet. لا تحتاج إلى تثبيت تطبيق كارديفاي لإضافة البطاقة، ولا يحتاج من يمسحها إلى تثبيت أي شيء.'],
            ['هل تعمل على أندرويد؟', 'هواتف أندرويد تستخدم Google Wallet، وكارديفاي تنشئ بطاقة Google Wallet أيضاً، وتعرض صفحة البطاقة زر Google Wallet على أندرويد. ويمكن لأي هاتف أن يمسح رمز QR في البطاقتين.'],
            ['هل بطاقة Apple Wallet بالعربية؟', 'تتبع عناوين الحقول في البطاقة لغة الصفحة التي تفتحها. افتح البطاقة العربية تظهر العناوين بالعربية، وافتح البطاقة الإنجليزية تظهر بالإنجليزية.'],
            ['ماذا يحدث عندما يتغير رقم هاتفي؟', 'رمز QR في البطاقة يفتح صفحة بطاقتك الحية، وهذه الصفحة تعرض دائماً البيانات الحالية من سجلك. عدّل السجل مرة واحدة، وكل عملية مسح جديدة تعرض الرقم الجديد.'],
            ['هل تفرض كارديفاي رسوماً على بطاقات المحفظة؟', 'لا. بطاقات Apple Wallet وGoogle Wallet مشمولة في منصة الويب المجانية، وفي منصة الويب تفرض كارديفاي رسوماً على البطاقات المطبوعة وبطاقات NFC فقط.'],
        ],
        'related_h2' => 'اقرأ أيضاً',
        'related' => [
            ['بطاقات الأعمال برمز QR', $L('qr-code-business-card')],
            ['بطاقات NFC في عُمان', $L('nfc-business-card')],
            ['بطاقات أعمال للشركات وفرق الموارد البشرية', $L('business-cards-for-companies')],
            ['الأسعار', $L('pricing')],
        ],
    ];
}

$c['path']   = '/apple-wallet-business-card';
$c['file']   = __FILE__;
$c['crumbs'] = [[$isAr ? 'الرئيسية' : 'Home', $isAr ? '/ar/' : '/']];
IntentLanding::render($c, $isAr);
