<?php
return [
    // HTTP errors
    'missing_company'   => 'لم يتم تحديد الشركة',
    'company_not_found' => 'الشركة غير موجودة',

    // Validation errors
    'invalid_csrf'      => 'رمز CSRF غير صالح',
    'invalid_proof'     => 'نوع الإثبات غير صالح',
    'upload_cr'         => 'يرجى رفع السجل التجاري (PDF/JPG/PNG، بحد أقصى 5 ميغابايت)',
    'bad_file'          => 'يجب أن يكون ملف الإثبات بصيغة PDF/JPG/PNG وأقل من 5 ميغابايت',
    'save_failed'       => 'تعذر حفظ ملف الإثبات. يرجى المحاولة لاحقاً.',

    // Page head
    'page_title'        => 'المطالبة بـ :name، مكتبة الشعارات',
    'page_description'  => 'أثبت ملكيتك لـ :name لتفعيل تنزيل الشعارات.',

    // Back link
    'back_to_company'   => 'العودة إلى :name',

    // Header banner
    'badge'             => 'مطالبة، إثبات الملكية',
    'h1'                => 'المطالبة بـ :name',
    'subtitle'          => 'أثبت أنك تمثل هذه الشركة لتفعيل تنزيل الشعارات وإدارة ملفها.',

    // Success (auto-verified)
    'auto_h2'           => 'تم التحقق فوراً',
    'auto_sub'          => 'تطابق نطاق شركتك. الشعار متاح الآن للتنزيل.',
    'auto_back_profile' => 'العودة للملف',
    'auto_order_cards'  => 'اطلب بطاقات عمل لفريقك',

    // Success (queued for review)
    'queued_h2'         => 'تم إرسال المطالبة',
    'queued_sub'        => 'مطالبتك في قائمة المراجعة. سنرد عليك خلال 48 ساعة.',
    'queued_back'       => 'العودة للملف',

    // Form labels
    'proof_type_label'     => 'نوع الإثبات',
    'proof_email_option'   => 'بريد الشركة (:email)، الأسرع، يتم التحقق تلقائياً إذا تطابق النطاق',
    'proof_cr_option'      => 'رفع السجل التجاري (PDF/صورة)',
    'proof_dns_option'     => 'إضافة سجل DNS TXT (التعليمات عبر البريد)',
    'proof_other_option'   => 'آخر (اذكره في الملاحظة)',

    'proof_file_label'     => 'ملف الإثبات (PDF/JPG/PNG، بحد أقصى 5 ميغابايت)',

    'role_label'           => 'دورك في الشركة',
    'role_placeholder'     => '-',
    'role_owner'           => 'مالك',
    'role_ceo'             => 'الرئيس التنفيذي / المدير العام',
    'role_marketing'       => 'التسويق',
    'role_admin'           => 'الإدارة',
    'role_legal'           => 'الشؤون القانونية',
    'role_other'           => 'آخر',

    'note_label'           => 'ملاحظة (اختياري)',

    'submit'               => 'إرسال المطالبة',
    'cancel'               => 'إلغاء',
];
