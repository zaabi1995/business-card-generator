<?php
return [
    // رسائل فوريّة
    'invalid_csrf'         => 'رمز الحماية غير صالح',
    'domain_added'         => 'تمّت إضافة النطاق. أعدّ إعدادات DNS أدناه ثمّ اضغط تحقّق.',
    'domain_add_failed'    => 'فشلت إضافة النطاق',
    'verified_prefix'      => 'تمّ التحقّق: :msg',
    'not_verified_prefix'  => 'لم يتمّ التحقّق بعد: :msg',
    'domain_removed'       => 'تمّت إزالة النطاق',
    'ssl_status_updated'   => 'تمّ تحديث حالة SSL',

    // الرأس
    'page_h1'              => 'النطاقات المخصّصة',
    'page_sub'             => 'اربط نطاقك الخاص ببطاقة أي موظف الرقمية.',
    'pro_enabled'          => 'النطاقات المخصّصة مفعّلة',
    'pro_required'         => 'النطاقات المخصّصة متاحة',

    // بوّابة قديمة (النطاقات المخصّصة و HD مجانية لكل الفرق منذ تحديث أسعار أبريل 2026).
    'gate_h2'              => 'النطاقات المخصّصة',
    'gate_body'            => 'اعرض بطاقات فريقك الرقمية من نطاقك الخاص، مثل <code>ceo.acme.com</code>. مجاناً لكل فرق كارديفاي.',
    'gate_cta'             => 'أضف نطاقاً',

    // نموذج الإضافة
    'add_h2'               => 'إضافة نطاق مخصّص',
    'field_employee'       => 'الموظف',
    'select_employee_ph'   => 'اختر موظفاً...',
    'field_domain'         => 'النطاق',
    'add_btn'              => 'إضافة النطاق',
    'add_note'             => 'بعد الإضافة، أعدّ DNS تماماً كما هو موضّح أدناه ثم اضغط <strong>تحقّق</strong>. نُصدر شهادات SSL يدوياً حالياً، فتواصل مع الدعم فور التحقّق من نطاقك.',

    // قائمة النطاقات
    'list_h2'              => 'نطاقاتك',
    'empty'                => 'لا توجد نطاقات مخصّصة بعد. أضف واحداً أعلاه.',
    'col_domain'           => 'النطاق',
    'col_employee'         => 'الموظف',
    'col_dns'              => 'DNS',
    'col_ssl'              => 'SSL',
    'col_actions'          => 'الإجراءات',
    'status_verified'      => 'تمّ التحقّق',
    'status_pending'       => 'قيد الانتظار',
    'ssl_active'           => 'مفعّل',
    'ssl_failed'           => 'فشل',
    'btn_verify'           => 'تحقّق',
    'btn_delete'           => 'حذف',
    'confirm_remove'       => 'هل تريد إزالة هذا النطاق المخصّص؟',

    // تعليمات DNS (لغة بسيطة)
    'dns_setup_label'      => 'إعداد DNS:',
    'dns_instruction'      => 'ادخل إلى مزوّد نطاقك (مثل GoDaddy أو Cloudflare أو Namecheap)، افتح إعدادات DNS، وأضف <strong>سجل CNAME</strong> لـ <code>:host</code> يُشير إلى <code class="px-1.5 py-0.5 bg-white border border-gray-200 rounded">cardify.om</code>. احفظ، انتظر من 5 إلى 60 دقيقة حتى تنتشر الإعدادات، ثم اضغط تحقّق أعلاه.',
    'copy'                 => 'نسخ',

    // لوحة SSL (لغة بسيطة)
    'ssl_panel_title'      => 'كيف تعمل شهادة SSL حالياً',
    'ssl_panel_body'       => 'تحتاج بطاقتك الرقمية إلى شهادة SSL (رمز القفل في المتصفّح) ليتصل بها الزوّار بأمان. نُصدرها يدوياً حالياً: فور ظهور DNS بحالة <strong>تمّ التحقّق</strong>، راسل <a href="mailto:support@cardify.om" class="underline">support@cardify.om</a> وسنُفعّل SSL خلال 24 ساعة. SSL التلقائي قادم قريباً.',
];
