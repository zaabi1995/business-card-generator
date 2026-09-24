<?php
/**
 * Landing page: "business cards for companies" / HR bulk ordering
 * (EN + /ar/ twin).
 *
 * Facts, all read from the code on 24 Sep 2026:
 *   - admin/employees.php: CSV or Excel import (email + name columns, skip
 *     duplicates, optional Arabic-digit conversion for phone numbers), CSV and
 *     Excel export, bulk "send edit links" by email (EmployeeEditToken).
 *   - portal/employee-edit.php: private per-employee link to correct own
 *     details and photo without template access.
 *   - The company portal at <slug>.cardify.om lets staff request a card; the
 *     request waits for admin approval (lang/en/portal.php "Pending admin").
 *   - admin/departments.php: a department can use its own card design
 *     ("Employees in this department will use this card design instead of the
 *     company default").
 *   - admin/print-tracking.php: printed quantities tracked against the
 *     purchase-order pool.
 *   - Leaving: lang/en/portal.php "Once approved your card URL stops working
 *     and your edit link is revoked."
 * No customer names and no headcounts: none are verified for publication.
 * Prices come from CardCatalogPricing.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/IntentLanding.php';

$lang = IntentLanding::lang();
$isAr = $lang === 'ar';
$L = static function (string $slug) use ($isAr): string { return IntentLanding::link($slug, $isAr); };
$p = static function (string $k) use ($isAr): string { return IntentLanding::price($k, $isAr); };

if (!$isAr) {
    $c = [
        'title' => 'Business Cards for Companies: Bulk Orders for HR',
        'desc'  => 'Order business cards for the whole company from one spreadsheet. Cardify imports your staff list, lets each employee check their own Arabic and English details, and prints in Oman. Digital cards are free.',
        'crumb' => 'Business cards for companies',
        'h1'    => 'Business cards for the whole company, from one staff list',
        'lede'  => 'HR uploads the staff list once. Every employee gets a digital card and checks their own details. You print only the cards you approve, with one design for the whole company.',
        'cta_primary'   => ['Start with your staff list', $L('company/register.php')],
        'cta_secondary' => ['See print prices', $L('pricing')],
        'sections' => [
            ['id' => 'the-problem', 'h2' => 'Why bulk business card orders go wrong',
             'p' => [
                'In most companies a business card order is an email chain. HR collects names in a spreadsheet, a designer types each card by hand, the proofs go back and forth, and a spelling mistake in an Arabic name is found after five hundred cards are printed. When someone changes title six months later, the whole process starts again for one person.',
                'Cardify replaces the chain with one system. The staff list is the source. The design is approved once. Each employee checks their own card. Printing uses the same records, so the print file matches what the employee approved.',
             ]],
            ['id' => 'how-it-works', 'h2' => 'How it works for an HR or admin team',
             'ol' => [
                '<strong>Import the staff list.</strong> Upload a CSV or Excel file with at least an email and a name column. You can add Arabic names, titles, phone, mobile and department. Duplicate emails can be skipped, and phone numbers can be converted to Arabic digits for the Arabic side.',
                '<strong>Set the design once.</strong> Upload your current card as a PDF, or build one in the designer. Every employee card uses this design, so nobody changes the logo, the fonts or the layout.',
                '<strong>Send each person a private link.</strong> One button sends every employee an email with a link to check and correct their own details and photo. They cannot edit the design.',
                '<strong>Approve and print.</strong> Order printed or NFC cards from the same dashboard. The print file carries each person\'s own QR code.',
             ]],
            ['id' => 'control', 'h2' => 'The controls a company needs',
             'ul' => [
                '<strong>Departments with their own design.</strong> A department can use a different card design from the company default, for example a subsidiary or a division with its own brand.',
                '<strong>Staff requests with approval.</strong> Staff can request a card on your company portal at <em>yourcompany.cardify.om</em>. The request waits for an admin to approve it.',
                '<strong>Print tracking against a purchase order.</strong> Record a purchase order and log each print run against it, so you see how many cards are ordered, printed and remaining.',
                '<strong>Export at any time.</strong> Download the full staff list as CSV or Excel, with Arabic names intact.',
                '<strong>When someone leaves.</strong> After an admin approves the leaving request, the person\'s card URL stops working and their edit link is revoked. The company keeps the record.',
             ]],
            ['id' => 'bilingual', 'h2' => 'Arabic and English for every employee',
             'p' => [
                'Each employee record holds a real Arabic field next to the English one. The Arabic name is not a transliteration made by software. The employee who owns the name checks it through their private link. The card shows right-to-left Arabic and left-to-right English, and the saved contact carries both spellings. This matters in Oman and the GCC, where one card often has to serve a ministry meeting in Arabic and a supplier call in English on the same day.',
             ]],
            ['id' => 'cost', 'h2' => 'Cost for a company',
             'p' => ['The Cardify web platform is free for any number of employees, designs and digital cards. You pay only for physical cards:'],
             'table' => [
                'head' => ['Product', 'Price', 'Unit'],
                'rows' => [
                    ['Digital cards, QR codes, wallet passes', 'OMR 0.000', 'unlimited employees'],
                    ['Standard printed cards, 300gsm matt', $p('standard'), 'per 100 cards'],
                    ['Premium printed cards, 350gsm soft-touch', $p('premium'), 'per 100 cards'],
                    ['Luxury printed cards, 450gsm with spot UV or foil', $p('luxury'), 'per 100 cards'],
                    ['NFC tap card with QR backup', $p('nfc'), 'per card'],
                ],
             ],
             'after' => [
                'Print prices are shown before you confirm an order. Delivery across Oman takes 2 to 4 working days, and pickup in Muscat is same-day. Full details are on the <a href="' . $L('pricing') . '">pricing page</a>.',
             ]],
            ['id' => 'sectors', 'h2' => 'Guides for your sector',
             'p' => ['Different teams use cards differently. These guides cover the details for each sector:'],
             'ul' => [
                '<a href="' . $L('industries/banking') . '">Banks and finance teams</a>',
                '<a href="' . $L('industries/government') . '">Government teams</a>',
                '<a href="' . $L('industries/real-estate') . '">Real estate agencies</a>',
                '<a href="' . $L('industries/oil-gas') . '">Oil and gas companies</a>',
             ]],
        ],
        'faq_h2' => 'Questions from HR and admin teams',
        'faq' => [
            ['Can we import our staff from Excel?', 'Yes. The employee import accepts a CSV or Excel file. An email and a name column are enough to start. Arabic names, titles, phone numbers and departments can be added in the same file.'],
            ['Do employees need to create an account?', 'No. HR creates the records. Each employee receives a private link by email to check their own details. They do not need a password to do this.'],
            ['Can employees change the card design?', 'No. The design is set by the company. Employees can correct their own details and photo, but not the logo, fonts or layout.'],
            ['Is there a minimum number of employees?', 'No. The platform is free for one employee or for thousands. Print orders are priced per 100 cards, and NFC cards per card.'],
            ['What happens when an employee leaves?', 'An admin approves the leaving request. The card URL then stops working and the edit link is revoked. The company keeps its records.'],
        ],
        'related_h2' => 'Keep reading',
        'related' => [
            ['What is a digital business card', $L('digital-business-card')],
            ['QR code business cards', $L('qr-code-business-card')],
            ['Apple Wallet business cards', $L('apple-wallet-business-card')],
            ['NFC business cards in Oman', $L('nfc-business-card')],
            ['Pricing', $L('pricing')],
        ],
    ];
} else {
    $c = [
        'title' => 'بطاقات أعمال للشركات بالجملة | كارديفاي',
        'desc'  => 'اطلب بطاقات الأعمال لكل موظفي الشركة من جدول واحد. كارديفاي تستورد قائمة الموظفين، ويراجع كل موظف بياناته بالعربية والإنجليزية، والطباعة في عُمان. البطاقات الرقمية مجانية.',
        'crumb' => 'بطاقات أعمال للشركات',
        'h1'    => 'بطاقات أعمال لكل الشركة من قائمة موظفين واحدة',
        'lede'  => 'ترفع الموارد البشرية قائمة الموظفين مرة واحدة، فيحصل كل موظف على بطاقة رقمية ويراجع بياناته بنفسه. وتطبع فقط البطاقات التي تعتمدها، بتصميم واحد لكل الشركة.',
        'cta_primary'   => ['ابدأ بقائمة موظفيك', $L('company/register.php')],
        'cta_secondary' => ['اطلع على أسعار الطباعة', $L('pricing')],
        'sections' => [
            ['id' => 'the-problem', 'h2' => 'لماذا تتعثر طلبات بطاقات الأعمال بالجملة',
             'p' => [
                'في أغلب الشركات يتحول طلب بطاقات الأعمال إلى سلسلة رسائل بريد. تجمع الموارد البشرية الأسماء في جدول، ويكتب المصمم كل بطاقة يدوياً، وتتنقل النماذج ذهاباً وإياباً، ثم يُكتشف خطأ إملائي في اسم عربي بعد طباعة خمسمئة بطاقة. وعندما يتغير مسمى موظف بعد ستة أشهر، تبدأ العملية كلها من جديد لشخص واحد.',
                'تستبدل كارديفاي هذه السلسلة بنظام واحد: قائمة الموظفين هي المصدر، والتصميم يُعتمد مرة واحدة، وكل موظف يراجع بطاقته بنفسه، والطباعة تستخدم السجلات نفسها، فيطابق ملف الطباعة ما اعتمده الموظف.',
             ]],
            ['id' => 'how-it-works', 'h2' => 'كيف تعمل مع فريق الموارد البشرية أو الإدارة',
             'ol' => [
                '<strong>استورد قائمة الموظفين.</strong> ارفع ملف CSV أو Excel يحتوي على عمود للبريد الإلكتروني وعمود للاسم على الأقل، ويمكنك إضافة الأسماء العربية والمسميات والهاتف والجوال والقسم. يمكن تخطي البريد المكرر، وتحويل أرقام الهاتف إلى الأرقام العربية للجهة العربية من البطاقة.',
                '<strong>اعتمد التصميم مرة واحدة.</strong> ارفع بطاقتك الحالية بصيغة PDF أو صمم واحدة في المصمم. كل بطاقات الموظفين تستخدم هذا التصميم، فلا يغيّر أحد الشعار أو الخطوط أو التخطيط.',
                '<strong>أرسل لكل موظف رابطاً خاصاً.</strong> بضغطة زر واحدة يصل لكل موظف بريد برابط لمراجعة بياناته وصورته وتصحيحها، دون أن يستطيع تعديل التصميم.',
                '<strong>اعتمد واطبع.</strong> اطلب البطاقات المطبوعة أو بطاقات NFC من لوحة التحكم نفسها، ويحمل ملف الطباعة رمز QR الخاص بكل موظف.',
             ]],
            ['id' => 'control', 'h2' => 'أدوات التحكم التي تحتاجها الشركة',
             'ul' => [
                '<strong>أقسام بتصاميم خاصة.</strong> يمكن لقسم ما أن يستخدم تصميماً مختلفاً عن تصميم الشركة الافتراضي، مثل شركة تابعة أو قطاع له هوية مستقلة.',
                '<strong>طلبات الموظفين مع الاعتماد.</strong> يطلب الموظفون بطاقاتهم من بوابة شركتكم على <em>yourcompany.cardify.om</em>، ويبقى الطلب بانتظار اعتماد المسؤول.',
                '<strong>متابعة الطباعة مقابل أمر الشراء.</strong> سجّل أمر الشراء وسجّل كل دفعة طباعة عليه، فترى عدد البطاقات المطلوبة والمطبوعة والمتبقية.',
                '<strong>التصدير في أي وقت.</strong> نزّل قائمة الموظفين كاملة بصيغة CSV أو Excel مع بقاء الأسماء العربية سليمة.',
                '<strong>عند مغادرة موظف.</strong> بعد أن يعتمد المسؤول طلب المغادرة يتوقف رابط بطاقة الموظف ويُلغى رابط التعديل الخاص به، وتحتفظ الشركة بالسجل.',
             ]],
            ['id' => 'bilingual', 'h2' => 'العربية والإنجليزية لكل موظف',
             'p' => [
                'يحتوي سجل كل موظف على حقل عربي حقيقي بجانب الحقل الإنجليزي، فالاسم العربي ليس نقلاً حرفياً آلياً، بل يراجعه صاحبه عبر رابطه الخاص. تعرض البطاقة العربية من اليمين إلى اليسار والإنجليزية من اليسار إلى اليمين، وتحمل جهة الاتصال المحفوظة الكتابتين. وهذا مهم في عُمان والخليج، حيث تخدم البطاقة الواحدة اجتماعاً في وزارة بالعربية ومكالمة مع مورّد بالإنجليزية في اليوم نفسه.',
             ]],
            ['id' => 'cost', 'h2' => 'التكلفة على الشركة',
             'p' => ['منصة كارديفاي على الويب مجانية لأي عدد من الموظفين والتصاميم والبطاقات الرقمية، وتدفع فقط مقابل البطاقات الملموسة:'],
             'table' => [
                'head' => ['المنتج', 'السعر', 'الوحدة'],
                'rows' => [
                    ['البطاقات الرقمية ورموز QR وبطاقات المحفظة', '0.000 ريال عماني', 'عدد غير محدود من الموظفين'],
                    ['بطاقات مطبوعة عادية، ورق مطفي 300 غرام', $p('standard'), 'لكل 100 بطاقة'],
                    ['بطاقات مطبوعة مميزة، ملمس ناعم 350 غرام', $p('premium'), 'لكل 100 بطاقة'],
                    ['بطاقات فاخرة 450 غرام مع طلاء UV موضعي أو رقائق', $p('luxury'), 'لكل 100 بطاقة'],
                    ['بطاقة NFC باللمس مع رمز QR احتياطي', $p('nfc'), 'للبطاقة الواحدة'],
                ],
             ],
             'after' => [
                'تظهر أسعار الطباعة قبل تأكيد الطلب. التوصيل داخل عُمان من يومي عمل إلى أربعة أيام، والاستلام من مسقط في اليوم نفسه. التفاصيل كاملة في <a href="' . $L('pricing') . '">صفحة الأسعار</a>.',
             ]],
            ['id' => 'sectors', 'h2' => 'أدلة حسب القطاع',
             'p' => ['تختلف طريقة استخدام البطاقات من فريق لآخر، وهذه الأدلة تشرح تفاصيل كل قطاع (بالإنجليزية):'],
             'ul' => [
                '<a href="' . $L('industries/banking') . '">البنوك وفرق التمويل</a>',
                '<a href="' . $L('industries/government') . '">الجهات الحكومية</a>',
                '<a href="' . $L('industries/real-estate') . '">شركات العقارات</a>',
                '<a href="' . $L('industries/oil-gas') . '">شركات النفط والغاز</a>',
             ]],
        ],
        'faq_h2' => 'أسئلة من فرق الموارد البشرية والإدارة',
        'faq' => [
            ['هل يمكننا استيراد الموظفين من Excel؟', 'نعم. يقبل استيراد الموظفين ملف CSV أو Excel، ويكفي عمود للبريد الإلكتروني وعمود للاسم للبدء. ويمكن إضافة الأسماء العربية والمسميات وأرقام الهاتف والأقسام في الملف نفسه.'],
            ['هل يحتاج الموظفون إلى إنشاء حساب؟', 'لا. تنشئ الموارد البشرية السجلات، ويصل لكل موظف رابط خاص بالبريد لمراجعة بياناته، دون حاجة إلى كلمة مرور.'],
            ['هل يستطيع الموظفون تغيير تصميم البطاقة؟', 'لا. التصميم تحدده الشركة. يستطيع الموظف تصحيح بياناته وصورته فقط، دون الشعار أو الخطوط أو التخطيط.'],
            ['هل هناك حد أدنى لعدد الموظفين؟', 'لا. المنصة مجانية لموظف واحد أو لآلاف الموظفين. وتُسعّر الطباعة لكل 100 بطاقة، وبطاقات NFC للبطاقة الواحدة.'],
            ['ماذا يحدث عند مغادرة موظف؟', 'يعتمد المسؤول طلب المغادرة، فيتوقف رابط البطاقة ويُلغى رابط التعديل، وتحتفظ الشركة بسجلاتها.'],
        ],
        'related_h2' => 'اقرأ أيضاً',
        'related' => [
            ['بطاقات الأعمال برمز QR', $L('qr-code-business-card')],
            ['بطاقات الأعمال في Apple Wallet', $L('apple-wallet-business-card')],
            ['بطاقات NFC في عُمان', $L('nfc-business-card')],
            ['الأسعار', $L('pricing')],
        ],
    ];
}

$c['path']   = '/business-cards-for-companies';
$c['file']   = __FILE__;
$c['crumbs'] = [[$isAr ? 'الرئيسية' : 'Home', $isAr ? '/ar/' : '/']];
IntentLanding::render($c, $isAr);
