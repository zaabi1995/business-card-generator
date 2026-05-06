<?php
return [
    // Page chrome
    'page_title'           => ':shop, تسعير العملاء',
    'nav_label'            => 'تسعير العملاء',
    'heading'              => 'تسعير خاص لكل عميل',
    'subheading'           => 'استبدل شرائح الأسعار الافتراضية لشركات معينة. مفيد عند الالتزام بسعر سبق وعرضته دون تغيير الأسعار للجميع.',

    // Default tiers reference
    'default_tiers_h'      => 'الشرائح الافتراضية (تطبق على كل عميل ما لم يكن له تسعير خاص)',
    'no_default_tiers'     => 'لا توجد شرائح حتى الآن. اذهب إلى الإعدادات لضبط التسعير الافتراضي.',

    // Empty state
    'empty_h'              => 'لا يوجد تسعير خاص بعد',
    'empty_s'              => 'أضف شركة عميلة لتحديد أسعار خاصة تستبدل شرائحك الافتراضية لهذه الشركة فقط.',

    // Row
    'unknown_company'      => 'شركة غير معروفة',
    'no_tiers_set'         => 'لا توجد شرائح',
    'min_qty_badge'        => 'حد أدنى :n',
    'per_paper_badge'      => 'تسعير حسب نوع الورق',
    'edit_btn'             => 'تعديل',
    'reset_btn'            => 'إعادة',
    'reset_confirm'        => 'هل تريد إزالة هذا التسعير الخاص والرجوع إلى التسعير الافتراضي؟',

    // Min quantity
    'field_min_qty'        => 'أدنى كمية للطلب',
    'min_qty_help'         => 'منع الطلبات الأقل من هذه الكمية. اترك 0 للسماح بأي كمية.',

    // Pricing mode toggle
    'mode_single'          => 'جدول تسعير موحد',
    'mode_per_paper'       => 'تسعير حسب نوع الورق',
    'per_paper_help'       => 'حدد شرائح مستقلة لكل نوع ورق. السعر يتضمن الفرق بالفعل، لا يضاف معامل اللمعان فوقه. العملة: :currency.',

    // Add button + modal
    'add_btn'              => 'إضافة تسعير عميل',
    'modal_add_h'          => 'إضافة تسعير عميل',
    'modal_edit_h'         => 'تعديل تسعير عميل',

    // Form fields
    'field_company'        => 'الشركة العميلة',
    'select_company'       => 'اختر شركة...',
    'no_candidates'        => 'لا توجد شركات طلبت من متجرك بعد. بعد أن تطلب شركة، يمكنك ضبط تسعير خاص لها من هنا.',
    'field_tiers'          => 'شرائح الكمية',
    'tiers_help'           => 'الكمية، السعر الإجمالي لهذه الكمية بـ :currency. عمود السعر للبطاقة يحسب تلقائيًا.',
    'col_quantity'         => 'الكمية',
    'col_total_price'      => 'الإجمالي (:currency)',
    'col_per_card'         => 'للبطاقة',
    'add_tier_btn'         => 'إضافة شريحة',
    'copy_default_btn'     => 'نسخ من الافتراضي',

    'field_notes'          => 'ملاحظات',
    'optional'             => 'اختياري',
    'notes_placeholder'    => 'مثال: حسب عرض OTECH-2026، ساري حتى ديسمبر 2026',

    // Buttons
    'cancel_btn'           => 'إلغاء',
    'save_btn'             => 'حفظ',

    // Flash
    'flash_saved'          => 'تم حفظ تسعير العميل.',
    'flash_reset'          => 'تمت إزالة التسعير الخاص. أصبح التسعير الافتراضي ساريًا.',
    'invalid_request'      => 'طلب غير صالح',
    'error_missing_company'=> 'الرجاء اختيار شركة عميلة.',
    'error_no_tiers'       => 'الرجاء إضافة شريحة كمية واحدة على الأقل.',
    'close_btn'            => 'إغلاق',
];
