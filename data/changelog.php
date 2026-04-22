<?php
/**
 * Cardify public changelog (data source for /changelog).
 *
 * Each entry: { date, version?, tag, title_en, body_en, title_ar,
 * body_ar }. Keep newest first. Concise — this is the marketing
 * timeline, not the git log.
 */
return [
    [
        'date'     => '2026-04-23',
        'version'  => 'v2.0',
        'tag'      => 'release',
        'title_en' => 'Cardify v2.0 — bilingual from top to bottom',
        'body_en'  => "60+ pages bilingual (EN + AR with proper RTL). New onboarding wizard, employee self-service magic-link editor, PWA, bilingual invoice receipts with 5% VAT, offsite backups, incident runbook, status page, E2E Playwright suite across chromium + Safari iOS + Chrome Android, and a long-overdue deploy script with pre-flight lint + post-flight smoke + one-command rollback.",
        'title_ar' => 'كارديفاي v2.0 — ثنائي اللغة من الأعلى إلى الأسفل',
        'body_ar'  => 'أكثر من 60 صفحة ثنائية اللغة (إنجليزي + عربي باتجاه صحيح). معالج إعداد جديد، محرِّر الموظف الذاتي برابط موقَّع، تطبيق ويب متقدّم (PWA)، إيصالات فواتير ثنائية اللغة مع ضريبة 5%، نسخ احتياطية خارجية، دليل الحوادث، صفحة حالة، مجموعة اختبارات Playwright شاملة عبر Chromium + Safari iOS + Chrome Android، وسكربت نشر بفحص مسبق وتدخين لاحق وسحب فوري بأمر واحد.',
    ],
    [
        'date'     => '2026-04-22',
        'tag'      => 'feature',
        'title_en' => 'Save Paymob card tokens for MOTO + one-click repeat',
        'body_en'  => "Every successful card payment now returns a reusable Paymob token into a new saved_cards table. Unlocks phone-order (MOTO) charges, one-click subscription renewals, and card-credit top-ups. Token-only storage — PCI scope stays at Paymob.",
        'title_ar' => 'حفظ بطاقات Paymob لطلبات الهاتف وإعادة الشراء بنقرة واحدة',
        'body_ar'  => 'كل دفعة بطاقة ناجحة تُعيد رمزاً (Token) قابلاً لإعادة الاستخدام إلى جدول saved_cards جديد. يفتح الباب لشحن الطلبات الهاتفية (MOTO)، تجديد الاشتراكات بنقرة، وشحن رصيد البطاقات بنقرة. تخزين الرمز فقط، معايير PCI تبقى لدى Paymob.',
    ],
    [
        'date'     => '2026-04-16',
        'tag'      => 'feature',
        'title_en' => 'Oman Business Index live — 2,414 companies',
        'body_en'  => 'Free public directory of the largest Omani enterprises, bilingual, sourced from the MoCIIP register. Sector + wilayat filters. 4,974 indexable URLs, each with canonical logo hero + Save contact + JSON-LD organisation schema.',
        'title_ar' => 'دليل الأعمال العُماني، 2,414 شركة',
        'body_ar'  => 'دليل عام مجاني لأكبر الشركات العُمانية، ثنائي اللغة، من سجل وزارة التجارة والصناعة. فلاتر القطاع والولاية. 4,974 رابطاً قابلاً للفهرسة، كل منها مع شعار رسمي وحفظ جهة الاتصال وسكيما JSON-LD.',
    ],
    [
        'date'     => '2026-04-06',
        'tag'      => 'feature',
        'title_en' => 'BHD-ERP sync live — Cardify → ERP invoices',
        'body_en'  => 'Print-order payments now auto-create a Quote → Invoice → Payment chain in BHD-ERP. Your receipt shows the ERP invoice number next to the Cardify order number so finance can reconcile without leaving Cardify.',
        'title_ar' => 'مزامنة BHD-ERP مباشرة — كارديفاي إلى فواتير ERP',
        'body_ar'  => 'مدفوعات طلبات الطباعة تُنشئ الآن سلسلة عرض سعر ← فاتورة ← دفعة تلقائياً في BHD-ERP. يظهر الإيصال مع رقم فاتورة ERP إلى جانب رقم طلب كارديفاي ليسهل على المحاسبة المطابقة.',
    ],
];
