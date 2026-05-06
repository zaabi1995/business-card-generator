<?php
return [
    // رسائل فوريّة
    'invalid_request'   => 'طلب غير صالح',
    'order_updated'     => 'تمّ تحديث الطلب #:id إلى :status',
    'update_error'      => 'خطأ: :msg',
    'unknown_error'     => 'غير معروف',

    // شريط التنقّل
    'nav_verified'      => 'مُوثَّق',
    'nav_dashboard'     => 'اللوحة',
    'nav_orders'        => 'الطلبات',
    'nav_analytics'     => 'التحليلات',
    'nav_credit'        => 'الائتمان',
    'nav_settings'      => 'الإعدادات',

    // بانرات الحالة
    'pending_approval_h' => 'قيد الموافقة',
    'pending_approval_b' => 'المطبعة بانتظار موافقة الإدارة. سيتمّ إشعارك فور الموافقة.',
    'suspended_h'       => 'تمّ إيقاف المطبعة',
    'suspended_b'       => 'تمّ إيقاف مطبعتك. تواصل مع الدعم لمزيد من المعلومات.',

    // مؤشّرات KPI
    'kpi_total_orders'  => 'إجمالي الطلبات',
    'kpi_pending'       => 'قيد الانتظار',
    'kpi_completed'     => 'المكتملة',
    'kpi_revenue'       => 'الإيرادات',

    // إجراءات سريعة
    'qa_view_orders_h'  => 'عرض جميع الطلبات',
    'qa_view_orders_s'  => 'إدارة طلبات الطباعة الواردة',
    'qa_settings_h'     => 'إعدادات المطبعة',
    'qa_settings_s'     => 'تحديث الأسعار والخدمات',
    'qa_profile_h'      => 'ملف المطبعة',
    'qa_profile_s'      => 'تعديل معلومات المطبعة العامة',

    // آخر الطلبات
    'recent_orders_h'   => 'آخر الطلبات',
    'view_all'          => 'عرض الكل',
    'empty_h'           => 'لا توجد طلبات بعد',
    'empty_s'           => 'ستظهر طلبات الشركات هنا',
    'unknown_company'   => 'شركة غير معروفة',
    'order_meta'        => ':n بطاقة، :paper',

    // شارات الحالة
    'status_pending'    => 'قيد الانتظار',
    'status_submitted'  => 'مُقدَّم',
    'status_processing' => 'قيد المعالجة',
    'status_printing'   => 'قيد الطباعة',
    'status_shipped'    => 'قيد الشحن',
    'status_delivered'  => 'تمّ التسليم',
    'status_cancelled'  => 'ملغى',

    // تحديث سريع للحالة
    'btn_update'        => 'تحديث',

    // شريط التحية
    'greeting_morning'        => 'صباح الخير، :name',
    'greeting_afternoon'      => 'مساء الخير، :name',
    'greeting_evening'        => 'مساء الخير، :name',
    'greeting_today_summary'  => ':n طلبات اليوم، :rev حتى الآن',
    'greeting_today_empty'    => 'لا توجد طلبات اليوم بعد',
    'badge_internal_provider' => 'مزوّد داخلي',

    // شريط مؤشّرات الأداء
    'kpi_today_h'         => 'اليوم',
    'kpi_awaiting_h'      => 'بانتظار إجراء',
    'kpi_in_production_h' => 'قيد الإنتاج',
    'kpi_shipped_week_h'  => 'تم الشحن (7 أيام)',
    'kpi_revenue_30d_h'   => 'الإيراد (30 يومًا)',
    'kpi_outstanding_h'   => 'الائتمان المستحق',
    'kpi_delta_up'        => '+:pct% مقارنة بـ 30 يومًا سابقة',
    'kpi_delta_down'      => ':pct% مقارنة بـ 30 يومًا سابقة',
    'kpi_delta_flat'      => 'بدون تغيير عن 30 يومًا سابقة',
    'kpi_delta_new'       => 'إيراد جديد',

    // قائمة الإجراءات
    'queue_h'                  => 'قائمة الإجراءات',
    'queue_sub'                => 'الطلبات التي تحتاج اهتمامك، مُجمّعة حسب المرحلة.',
    'queue_stage_submitted'    => 'مُقدَّمة',
    'queue_stage_processing'   => 'قيد المعالجة',
    'queue_stage_printing'     => 'في الطباعة',
    'queue_stage_shipped'      => 'جاهزة للتسليم',
    'queue_count_one'          => 'طلب واحد',
    'queue_count_many'         => ':n طلبات',
    'queue_empty'              => 'لا شيء هنا.',
    'queue_advance_processing' => 'بدء المعالجة',
    'queue_advance_printing'   => 'إرسال للطباعة',
    'queue_advance_shipped'    => 'تم الشحن',
    'queue_advance_delivered'  => 'تم التسليم',
    'queue_open'               => 'فتح',

    // لوحة المزوّد الداخلي
    'internal_h'                => 'العملاء في قائمتك',
    'internal_top_clients'      => 'أعلى 5 حسب عدد الطلبات، خلال 30 يومًا',
    'internal_order_on_behalf'  => 'إنشاء طلب نيابة',
    'internal_browse_clients'   => 'تصفّح جميع العملاء',
    'internal_dormant'          => ':n عملاء لم يطلبوا منذ 60 يومًا أو أكثر',
    'internal_active_count'     => ':n عميل نشط خلال 30 يومًا',
    'internal_no_recent'        => 'لا توجد طلبات من العملاء خلال 30 يومًا الأخيرة.',

    // لوحة مخاطر الائتمان
    'credit_risk_h'           => 'تعرّض الائتمان',
    'credit_risk_total'       => ':used من :limit مستخدم',
    'credit_risk_review'      => 'مراجعة الحسابات',
    'credit_risk_top_exposed' => 'الأعلى تعرّضًا',
    'credit_risk_no_accounts' => 'لا توجد حسابات ائتمان معتمدة بعد.',
    'credit_risk_terms'       => 'صافي :n',

    // لوحة نشاط المُشغّلين
    'operator_activity_h'      => 'نشاط المُشغّلين (7 أيام)',
    'operator_orders_unit_one' => 'طلب واحد',
    'operator_orders_unit'     => ':n طلبات',
    'operator_no_activity'     => 'لا توجد طلبات بعد',
    'operator_view_team'       => 'إدارة الفريق',

    // اتجاه الإيرادات
    'revenue_widget_h'      => 'اتجاه الإيرادات',
    'revenue_widget_sub'    => 'إيرادات الطلبات اليومية لمطبعتك',
    'revenue_period_today'  => 'اليوم',
    'revenue_period_7d'     => 'آخر 7 أيام',
    'revenue_period_30d'    => 'آخر 30 يومًا',
    'revenue_full_analytics'=> 'التحليلات الكاملة',
    'revenue_widget_empty'  => 'لا توجد بيانات كافية لعرض الاتجاه بعد.',

    // إضافات لوحة Press Floor
    'console_label'             => 'وحدة العمليات',
    'jump_to_tenants'           => 'الانتقال إلى العملاء',
    'nav_clients'               => 'العملاء',

    // وحدة العملاء
    'tenants_h'                 => 'وحدة العملاء',
    'tenants_lede'              => 'جميع عملاء Cardify. ابحث، ادخل، أنشئ صفحات الطباعة، وأرسل الطلبات نيابةً عنهم.',
    'tenants_total'             => 'عميل',
    'tenants_search_ph'         => 'ابحث بالاسم أو المعرّف',
    'tenants_filter_all'        => 'الكل',
    'tenants_filter_active'     => 'نشط خلال 30 يومًا',
    'tenants_filter_dormant'    => 'خامل أكثر من 60 يومًا',
    'tenants_filter_unprinted'  => 'بطاقات غير مطبوعة',
    'tenants_col_name'          => 'العميل',
    'tenants_col_employees'     => 'الموظفون',
    'tenants_col_cards'         => 'بطاقات جاهزة',
    'tenants_col_last_order'    => 'آخر طلب',
    'tenants_col_actions'       => 'الإجراءات',
    'tenants_unit_tpl'          => 'قوالب',
    'tenants_orders_n'          => ':n طلبات إجماليًا',
    'tenants_no_orders'         => 'لم يطلب أبدًا',
    'tenants_btn_open'          => 'فتح',
    'tenants_btn_sheet'         => 'صفحة',
    'tenants_btn_order'         => 'طلب',
    'tenants_btn_upload_pdf'    => 'رفع PDF',

    // إلغاء الطلب
    'cancel_btn'                => 'إلغاء الطلب',
    'cancel_confirm_h'          => 'إلغاء الطلب رقم :id؟',
    'cancel_confirm_b'          => 'سيُحدَّد الطلب كملغى ويُبلَّغ العميل بالبريد و واتساب وتُسجَّل عملية تدقيق. لا يمكن التراجع إلا بواسطة مدير عام.',
    'cancel_reason_label'       => 'السبب (مطلوب)',
    'cancel_reason_ph'          => 'مثلًا: طلب العميل الإلغاء، التصميم يحتاج تعديل، الورق غير متوفّر...',
    'cancel_submit'             => 'إلغاء هذا الطلب',
    'cancel_busy'               => 'جارٍ الإلغاء...',
    'cancel_success'            => 'تمّ إلغاء الطلب رقم :id.',
    'cancel_denied'             => 'ليس لديك صلاحية إلغاء الطلبات.',
    'cancel_missing_order'      => 'رقم الطلب مطلوب.',
    'cancel_reason_required'    => 'يرجى ذكر سبب الإلغاء.',
    'cancel_not_found'          => 'الطلب غير موجود في هذه المطبعة.',
    'cancel_frozen'             => 'هذا الطلب :status ولا يمكن إلغاؤه. تواصل مع الدعم إذا لزم.',
    'cancel_keep'               => 'الاحتفاظ بالطلب',

    // نافذة رفع ملف PDF لعميل
    'upload_modal_lede'         => 'ارفع ملف PDF المصدر الذي استلمته من العميل. تُدمج صفحتا الوجه والظهر، وتُحلَّل، وتُصنَّف، وتُحفظ كتصميم بطاقة جديد لدى العميل.',
    'upload_field_name'         => 'اسم التصميم',
    'upload_field_name_ph'      => 'مثلًا: تحديث هوية حصن 2026',
    'upload_field_front'        => 'PDF الوجه (مطلوب)',
    'upload_field_back'         => 'PDF الظهر (اختياري)',
    'upload_cancel'             => 'إلغاء',
    'upload_submit'             => 'رفع ومعالجة',
    'upload_busy'               => 'جارٍ المعالجة',
    'upload_success'            => 'تمّ حفظ تصميم البطاقة لدى العميل.',
    'upload_open_tenant'        => 'فتح العميل',
    'upload_another'            => 'رفع آخر',
    'upload_missing_fonts_h'    => 'بعض الخطوط غير متوفّرة على الخادم',
    'upload_missing_fonts_b'    => 'استخدم ملف PDF عائلات الخطوط التالية. ستُعرض البطاقة بخط بديل مشابه إلى حين رفع النسخ الأصلية إلى uploads/fonts/library/.',
    'tenants_view_all'          => 'عرض جميع العملاء',
    'tenants_empty'             => 'لا توجد نتائج.',
    'tenant_status_active'      => 'نشط',
    'tenant_status_dormant'     => 'خامل',
    'tenant_status_empty'       => 'بدون فريق',

    // تدفّق النشاط الأخير
    'activity_h'                  => 'النشاط الأخير',
    'activity_view_all'           => 'عرض الكل',
    'activity_empty'              => 'لا يوجد نشاط حديث بعد.',
    'activity_erp_failed'         => 'فشل مزامنة ERP للطلب :ref',
    'activity_credit_requested'   => 'طلب ائتمان من :ref',
    'activity_template_requested' => 'طلب قالب من :ref',
    'activity_new_order'          => 'طلب جديد :ref',
    'activity_just_now'           => 'الآن',
    'activity_minutes_ago'        => 'منذ :n دقيقة',
    'activity_hours_ago'          => 'منذ :n ساعة',
    'activity_days_ago'           => 'منذ :n يوم',
];
