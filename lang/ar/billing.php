<?php
return [
    // رسائل نتيجة الدفع
    'pay_success'          => 'تمّت عملية الدفع بنجاح.',
    'pay_error_default'    => 'فشل معالجة الدفع. يُرجى المحاولة مرّة أخرى.',
    'subscribe_failed'     => 'تعذّر بدء عملية الدفع',
    'buy_cards_failed'     => 'تعذّر بدء عملية شراء البطاقات',

    // الباقة الحالية
    'current_plan'         => 'الباقة الحالية',
    'monthly_price'        => 'السعر الشهري',
    'per_month_short'      => '/شهر',
    'currency_label'       => 'العملة:',
    'plan_default_desc'    => 'ميزات أساسية للفرق الصغيرة',

    // الاستخدام
    'usage_h3'             => 'الاستخدام',
    'usage_employees'      => 'الموظفون',
    'usage_templates'      => 'القوالب',
    'usage_storage'        => 'التخزين',
    'unlimited'            => 'غير محدود',
    'gb_suffix'            => ':n غيغابايت',

    // لافتة الترقية
    'unlock_premium_h3'    => 'منصة مجانية للأبد',
    'unlock_premium_body'  => 'إنشاء بطاقات HD، تحليلات QR، إنشاء بالجملة، الكل مشمول مجاناً.',
    'feat_hd_quality'      => 'بطاقات بجودة HD (أكثر من 300 DPI)',
    'feat_qr_analytics'    => 'تحليلات قراءات QR',
    'feat_bulk_gen'        => 'إنشاء البطاقات بالجملة',
    'view_plans_btn'       => 'عرض أسعار الطباعة',

    // جدول مقارنة الميزات
    'feature_matrix_h3'    => 'مقارنة ميزات الباقات',
    'col_feature'          => 'الميزة',
    'col_free'             => 'مجاني',
    'col_pro'              => 'برو',
    'col_enterprise'       => 'مؤسسات',
    'fm_employees'         => 'الموظفون',
    'fm_cards_month'       => 'البطاقات / شهر',
    'fm_preview_quality'   => 'جودة معاينة البطاقة',
    'fm_print_quality'     => 'جودة الطباعة',
    'fm_qr_analytics'      => 'تحليلات قراءات QR',
    'fm_bulk_gen'          => 'إنشاء البطاقات بالجملة',
    'fm_api_access'        => 'الوصول عبر API',
    'fm_custom_brand'      => 'علامة تجارية مخصّصة',
    'fm_priority_support'  => 'دعم الأولوية',
    'limit_up_to'          => 'حتّى :n',
    'quality_low'          => 'منخفضة (~100 DPI)',
    'quality_high'         => 'عالية (~300 DPI)',
    'matrix_note'          => '<strong>ملاحظة:</strong> جميع الباقات تشمل جودة الطباعة الكاملة عند طلب بطاقات من المطبعة. جودة المعاينة تؤثّر فقط على صور البطاقات الرقمية المعروضة في اللوحة.',

    // شبكة الباقات
    'upgrade_your_plan'    => 'ترقية الباقة',
    'cycle_monthly'        => 'شهري',
    'cycle_yearly'         => 'سنوي',
    'save_20'              => 'وفّر 20٪',
    'save_pct'             => 'وفّر :n٪',
    'no_plans_h3'          => 'لا توجد باقات اشتراك متاحة',
    'no_plans_body'        => 'لم يتمّ إعداد باقات الاشتراك بعد. تواصل مع مشرف النظام.',
    'popular_badge'        => 'الأكثر طلباً',
    'per_month'            => '/شهر',
    'per_year'             => '/سنة',
    'employees_suffix'     => 'موظف',
    'templates_suffix'     => 'قالب',
    'storage_suffix'       => 'تخزين',
    'current_plan_btn'     => 'الباقة الحالية',
    'upgrade_to'           => 'ترقية إلى :name',

    // لوحة الطلبات غير المدفوعة
    'pending_payments'     => 'طلبات طباعة بانتظار الدفع',
    'n_unpaid'             => ':n غير مدفوع',
    'order_num'            => 'طلب #:num',
    'n_cards'              => ':n بطاقة',
    'pay_now'              => 'ادفع الآن',
    'total_outstanding'    => 'الإجمالي المستحق:',
    'view_all_orders'      => 'عرض جميع الطلبات',

    // رصيد البطاقات
    'card_credits_h3'      => 'إنشاء البطاقات',
    'card_credits_sub'     => 'مجاني لجميع الفرق، بطاقات بلا حدود',
    'credits_available'    => 'الرصيد المتاح',
    'generated_this_month' => 'المُنشأة هذا الشهر',
    'monthly_limit_plan'   => 'الحد الشهري',
    'buy_credits_lead'     => '<strong>اشترِ رصيد بطاقات</strong>، كل رصيد يسمح بإنشاء بطاقة عمل واحدة. السعر: <strong>:price :cur</strong> للبطاقة.',
    'number_of_cards'      => 'عدد البطاقات',
    'cards_option'         => ':qty بطاقة, :total :cur',
    'buy_credits_btn'      => 'اشتر الرصيد عبر Paymob',
    'card_included'        => 'إنشاء البطاقات مجاني وغير محدود لجميع الفرق. لا توجد رسوم لكل بطاقة.',

    // طرق الدفع
    'payment_methods_h3'   => 'طرق الدفع',
    'payment_methods_lead' => 'نقبل طرق الدفع التالية:',
    'apple_pay'            => 'Apple Pay',
    'omannet'              => 'أومان نت',

    // أخطاء التحميل
    'err_billing_missing'  => 'وحدة الفوترة غير مُعدّة. تواصل مع مشرف النظام.',
    'err_billing_load'     => 'خطأ في تحميل وحدة الفوترة:',
    'err_billing_init'     => 'خطأ في تهيئة نظام الفوترة:',
    'invalid_request'      => 'طلب غير صالح',
];
