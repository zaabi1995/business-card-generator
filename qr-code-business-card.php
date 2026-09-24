<?php
/**
 * Landing page: "QR code business card" (EN + /ar/ twin).
 *
 * Facts, all read from the code on 24 Sep 2026:
 *   - The printed-card QR encodes the employee's share URL, built only by
 *     CardifyConvention::employeeShareUrl() (cardify skill rule 69), so a scan
 *     opens the card page, and Save Contact on that page downloads a .vcf from
 *     vcf.php (rule 39).
 *   - QR style: module shape square | dots | rounded, finder-eye shape square |
 *     rounded | circle, module and background colour, set in the card designer
 *     (admin/index.php QR Style panel, rule 64). Imported designs sample the
 *     QR colours from the source PDF (rule 20).
 *   - Scan and view analytics drop bots, scripts and the server's own traffic,
 *     and count repeat opens by the same person within one hour once
 *     (QRTracker::isBotOrSelfTraffic + the 1-hour dedupe, rule 33).
 *   - The free vCard QR generator lives at /tools/vcard-qr-generator.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/IntentLanding.php';

$lang = IntentLanding::lang();
$isAr = $lang === 'ar';
$L = static function (string $slug) use ($isAr): string { return IntentLanding::link($slug, $isAr); };
$stdPrice = IntentLanding::price('standard', $isAr);
$premPrice = IntentLanding::price('premium', $isAr);

if (!$isAr) {
    $c = [
        'title' => 'QR Code Business Card That Saves Your Contact',
        'desc'  => 'A QR code business card opens your live contact page and saves your details to the phone in one tap. Cardify makes one for every employee, in Arabic and English, with scan analytics. Free.',
        'crumb' => 'QR code business card',
        'h1'    => 'QR code business cards that save straight to the phone',
        'lede'  => 'Print a QR code on your card, show it on your phone, or put it on a badge. One scan opens your card page, and one tap saves your name, number and email into the other person\'s contacts.',
        'cta_primary'   => ['Create your team\'s cards free', $L('company/register.php')],
        'cta_secondary' => ['Try the free vCard QR generator', $L('tools/vcard-qr-generator')],
        'sections' => [
            ['id' => 'how-it-works', 'h2' => 'How a QR code business card works',
             'p' => [
                'A QR code on a business card is a link that a phone camera can read. On a Cardify card the link goes to the person\'s own card page, for example <em>yourcompany.cardify.om/yourname</em>. The page shows the name, title, phone, email and company, in Arabic and English, with a <strong>Save contact</strong> button.',
                'Save contact downloads a standard vCard file, the <code>.vcf</code> format that the contacts app on iPhone and Android already reads. The receiver does not install an app, does not create an account and does not type your number by hand.',
             ]],
            ['id' => 'two-kinds', 'h2' => 'Two kinds of QR business card, and which one to choose',
             'p' => ['There are two ways to put a contact in a QR code. They look the same on paper, but they behave differently after you print them.'],
             'table' => [
                'head' => ['', 'QR that holds the contact (static vCard)', 'QR that opens a card page (Cardify)'],
                'rows' => [
                    ['What the code contains', 'The name, number and email, written into the code', 'A short link to your card page'],
                    ['If your number changes', 'The printed code is wrong. You print again.', 'You edit the record. The same code shows the new number.'],
                    ['Arabic and English', 'One version, whatever you typed', 'Both languages on one page'],
                    ['Scan count', 'None', 'Views and scans in the dashboard'],
                    ['Needs internet to save', 'No', 'Yes, to open the page'],
                ],
             ],
             'after' => [
                'A static vCard code is the right tool for a single person who wants a quick code and never changes details. Cardify\'s <a href="' . $L('tools/vcard-qr-generator') . '">free vCard QR generator</a> makes one with no sign-up. For a company, the card-page code is better, because printed cards stay correct when people change numbers, titles or departments.',
             ]],
            ['id' => 'design', 'h2' => 'A QR code that matches your brand',
             'p' => ['A plain black square can look out of place on a designed card. In the Cardify card designer you can set:'],
             'ul' => [
                'the colour of the code and of its background,',
                'the shape of the small squares: square, dots or rounded,',
                'the shape of the three corner markers: square, rounded or circle.',
             ],
             'after' => [
                'If you import your existing card design as a PDF, Cardify reads the colours of the QR code in your artwork and uses them for every employee. Test a styled code with a few phones before you print a large run. Strong contrast between the code and its background scans best.',
             ]],
            ['id' => 'analytics', 'h2' => 'Know when your card is scanned',
             'p' => [
                'Every Cardify card page records views and clicks, so a sales manager can see which cards people open after an event. The count is kept honest: Cardify ignores bots, scripts and its own server checks, and it counts repeat opens by the same person within one hour as one visit. A number that looks too good is not useful to anyone.',
             ]],
            ['id' => 'where-to-use', 'h2' => 'Where teams in Oman put the QR code',
             'ul' => [
                'On the back of the printed business card, next to the logo.',
                'On an Apple Wallet or Google Wallet pass, so it can be shown from the phone. See <a href="' . $L('apple-wallet-business-card') . '">Apple Wallet business cards</a>.',
                'On an NFC card, as the backup for phones that do not read NFC. See <a href="' . $L('nfc-business-card') . '">NFC business cards</a>.',
                'On exhibition badges, reception desks and company vehicles.',
                'In an email signature, where the card link does the same job.',
             ]],
            ['id' => 'print', 'h2' => 'Printed cards with the QR code already placed',
             'p' => [
                'Cardify places each employee\'s own QR code on the print file, so a print run of two hundred people has two hundred different, correct codes. Printed cards are ordered from the same dashboard and printed in Oman: Standard cards cost ' . $stdPrice . ' per 100 and Premium cards cost ' . $premPrice . ' per 100. The digital cards and the QR codes are free. See <a href="' . $L('pricing') . '">pricing</a>.',
             ]],
        ],
        'faq_h2' => 'Questions about QR code business cards',
        'faq' => [
            ['Does the person who scans the QR code need an app?', 'No. The built-in camera on iPhone and Android reads the code and opens the card page in the browser. The Save contact button then adds the details to the phone\'s contacts.'],
            ['What happens to the QR code if an employee leaves?', 'The card belongs to the company. An administrator can change or remove the card record, and the QR codes already printed do not send people to another company\'s page.'],
            ['Can the QR code be in our brand colours?', 'Yes. You can set the code colour, the background colour, the dot shape and the corner shape in the card designer. Keep strong contrast so every phone can read it.'],
            ['Is a QR code business card free?', 'Yes. The digital card, the QR code and the scan analytics are part of the free Cardify web platform. You pay only when you order printed or NFC cards.'],
            ['Can I make a QR code for one person without a company account?', 'Yes. The free vCard QR generator on Cardify makes a code that holds your contact details directly, with no sign-up.'],
        ],
        'related_h2' => 'Keep reading',
        'related' => [
            ['What is a digital business card', $L('digital-business-card')],
            ['Apple Wallet business cards', $L('apple-wallet-business-card')],
            ['Business cards for companies and HR teams', $L('business-cards-for-companies')],
            ['QR vCard, definition', $L('glossary/qr-vcard')],
            ['Free vCard QR generator', $L('tools/vcard-qr-generator')],
        ],
    ];
} else {
    $c = [
        'title' => 'بطاقة أعمال برمز QR تُحفظ في الهاتف | كارديفاي',
        'desc'  => 'بطاقة الأعمال برمز QR تفتح صفحة بياناتك الحية وتحفظها في الهاتف بضغطة واحدة. كارديفاي تنشئ واحدة لكل موظف بالعربية والإنجليزية مع إحصاءات المسح، مجاناً.',
        'crumb' => 'بطاقة أعمال برمز QR',
        'h1'    => 'بطاقات أعمال برمز QR تُحفظ في الهاتف مباشرة',
        'lede'  => 'اطبع رمز QR على بطاقتك، أو اعرضه على هاتفك، أو ضعه على بطاقة المعرض. مسحة واحدة تفتح صفحة بطاقتك، وضغطة واحدة تحفظ اسمك ورقمك وبريدك في جهات اتصال الطرف الآخر.',
        'cta_primary'   => ['أنشئ بطاقات فريقك مجاناً', $L('company/register.php')],
        'cta_secondary' => ['جرّب مولد رمز QR لبطاقة vCard مجاناً', $L('tools/vcard-qr-generator')],
        'sections' => [
            ['id' => 'how-it-works', 'h2' => 'كيف تعمل بطاقة الأعمال برمز QR',
             'p' => [
                'رمز QR على بطاقة الأعمال رابط تقرؤه كاميرا الهاتف. وفي بطاقة كارديفاي يفتح هذا الرابط صفحة البطاقة الخاصة بالشخص، مثل <em>yourcompany.cardify.om/yourname</em>. تعرض الصفحة الاسم والمسمى الوظيفي والهاتف والبريد واسم الشركة بالعربية والإنجليزية، مع زر <strong>حفظ جهة الاتصال</strong>.',
                'زر الحفظ ينزّل ملف vCard قياسياً بصيغة <code>.vcf</code>، وهي الصيغة التي يقرؤها تطبيق جهات الاتصال في الآيفون وأندرويد. لا يثبّت المستلم أي تطبيق، ولا ينشئ حساباً، ولا يكتب رقمك بيده.',
             ]],
            ['id' => 'two-kinds', 'h2' => 'نوعان من بطاقات QR، وأيهما تختار',
             'p' => ['هناك طريقتان لوضع جهة اتصال في رمز QR. تبدوان متشابهتين على الورق، لكن سلوكهما يختلف بعد الطباعة.'],
             'table' => [
                'head' => ['', 'رمز يحمل البيانات نفسها (vCard ثابت)', 'رمز يفتح صفحة البطاقة (كارديفاي)'],
                'rows' => [
                    ['محتوى الرمز', 'الاسم والرقم والبريد مكتوبة داخل الرمز', 'رابط قصير إلى صفحة بطاقتك'],
                    ['إذا تغير رقمك', 'الرمز المطبوع يصبح خاطئاً وتعيد الطباعة', 'تعدّل السجل، والرمز نفسه يعرض الرقم الجديد'],
                    ['العربية والإنجليزية', 'نسخة واحدة كما كتبتها', 'اللغتان في صفحة واحدة'],
                    ['عدد مرات المسح', 'لا يوجد', 'المشاهدات وعمليات المسح في لوحة التحكم'],
                    ['يحتاج إلى إنترنت للحفظ', 'لا', 'نعم، لفتح الصفحة'],
                ],
             ],
             'after' => [
                'رمز vCard الثابت مناسب لشخص واحد يريد رمزاً سريعاً ولا تتغير بياناته. ويصنع <a href="' . $L('tools/vcard-qr-generator') . '">مولد رمز QR لبطاقة vCard</a> المجاني في كارديفاي هذا الرمز دون تسجيل. أما للشركات فرمز صفحة البطاقة أفضل، لأن البطاقات المطبوعة تبقى صحيحة عندما يغيّر الموظفون أرقامهم أو مسمياتهم أو أقسامهم.',
             ]],
            ['id' => 'design', 'h2' => 'رمز QR يتناسب مع هوية شركتك',
             'p' => ['المربع الأسود العادي قد لا يناسب بطاقة مصممة بعناية. في مصمم البطاقات في كارديفاي يمكنك ضبط:'],
             'ul' => [
                'لون الرمز ولون خلفيته،',
                'شكل المربعات الصغيرة: مربعة أو نقاط دائرية أو مستديرة الحواف،',
                'شكل علامات الزوايا الثلاث: مربعة أو مستديرة الحواف أو دائرية.',
             ],
             'after' => [
                'إذا رفعت تصميم بطاقتك الحالي بصيغة PDF، تقرأ كارديفاي ألوان رمز QR في التصميم وتستخدمها لكل الموظفين. جرّب الرمز المصمم على عدة هواتف قبل طباعة كمية كبيرة، فالتباين القوي بين الرمز وخلفيته يضمن قراءة أفضل.',
             ]],
            ['id' => 'analytics', 'h2' => 'اعرف متى تُمسح بطاقتك',
             'p' => [
                'تسجل كل صفحة بطاقة في كارديفاي المشاهدات والنقرات، فيرى مدير المبيعات البطاقات التي فتحها الناس بعد المعرض. والأرقام صادقة: تتجاهل كارديفاي الروبوتات والبرامج الآلية وفحوصات خوادمها، وتحسب الزيارات المتكررة من الشخص نفسه خلال ساعة واحدة زيارة واحدة. فالرقم المبالغ فيه لا يفيد أحداً.',
             ]],
            ['id' => 'where-to-use', 'h2' => 'أين تضع الفرق في عُمان رمز QR',
             'ul' => [
                'على ظهر بطاقة الأعمال المطبوعة بجانب الشعار.',
                'على بطاقة في Apple Wallet أو Google Wallet لعرضها من الهاتف. اطلع على <a href="' . $L('apple-wallet-business-card') . '">بطاقات الأعمال في Apple Wallet</a>.',
                'على بطاقة NFC كبديل للهواتف التي لا تقرأ NFC. اطلع على <a href="' . $L('nfc-business-card') . '">بطاقات NFC</a>.',
                'على بطاقات المعارض ومكاتب الاستقبال ومركبات الشركة.',
                'في توقيع البريد الإلكتروني، حيث يؤدي رابط البطاقة الدور نفسه.',
             ]],
            ['id' => 'print', 'h2' => 'بطاقات مطبوعة ورمز QR في مكانه',
             'p' => [
                'تضع كارديفاي رمز QR الخاص بكل موظف في ملف الطباعة، فطلب طباعة لمئتي موظف يحمل مئتي رمز مختلف وصحيح. تُطلب البطاقات المطبوعة من لوحة التحكم نفسها وتُطبع في عُمان: البطاقات العادية بسعر ' . $stdPrice . ' لكل 100 بطاقة، والبطاقات المميزة بسعر ' . $premPrice . ' لكل 100 بطاقة. أما البطاقات الرقمية ورموز QR فمجانية. اطلع على <a href="' . $L('pricing') . '">الأسعار</a>.',
             ]],
        ],
        'faq_h2' => 'أسئلة عن بطاقات الأعمال برمز QR',
        'faq' => [
            ['هل يحتاج من يمسح الرمز إلى تطبيق؟', 'لا. كاميرا الآيفون وأندرويد المدمجة تقرأ الرمز وتفتح صفحة البطاقة في المتصفح، ثم يضيف زر حفظ جهة الاتصال البيانات إلى جهات الاتصال في الهاتف.'],
            ['ماذا يحدث لرمز QR إذا غادر الموظف الشركة؟', 'البطاقة ملك للشركة. يمكن للمسؤول تعديل سجل البطاقة أو حذفه، ولا توجه الرموز المطبوعة الناس إلى صفحة شركة أخرى.'],
            ['هل يمكن أن يكون رمز QR بألوان شركتنا؟', 'نعم. يمكنك ضبط لون الرمز ولون الخلفية وشكل النقاط وشكل الزوايا في مصمم البطاقات. حافظ على تباين قوي حتى تقرأه كل الهواتف.'],
            ['هل بطاقة الأعمال برمز QR مجانية؟', 'نعم. البطاقة الرقمية ورمز QR وإحصاءات المسح جزء من منصة كارديفاي المجانية على الويب، وتدفع فقط عند طلب بطاقات مطبوعة أو بطاقات NFC.'],
            ['هل يمكنني صنع رمز QR لشخص واحد دون حساب شركة؟', 'نعم. مولد رمز QR لبطاقة vCard المجاني في كارديفاي يصنع رمزاً يحمل بياناتك مباشرة دون تسجيل.'],
        ],
        'related_h2' => 'اقرأ أيضاً',
        'related' => [
            ['بطاقات الأعمال في Apple Wallet', $L('apple-wallet-business-card')],
            ['بطاقات أعمال للشركات وفرق الموارد البشرية', $L('business-cards-for-companies')],
            ['بطاقات NFC في عُمان', $L('nfc-business-card')],
            ['أدوات مجانية', $L('tools')],
        ],
    ];
}

$c['path']   = '/qr-code-business-card';
$c['file']   = __FILE__;
$c['crumbs'] = [[$isAr ? 'الرئيسية' : 'Home', $isAr ? '/ar/' : '/']];
IntentLanding::render($c, $isAr);
