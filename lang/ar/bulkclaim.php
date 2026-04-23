<?php
return [
    // رسائل فوريّة
    'invalid_csrf'        => 'رمز الحماية غير صالح',
    'parse_failed'        => 'تعذّر تحليل أي صفوف. الصف الأول يجب أن يحوي: name, phone, company_name, title, email.',
    'batch_too_large'     => 'الدفعة كبيرة (:n صف). الحد الأقصى :max لكل دفعة، جزّئ ملف CSV.',
    'invalid_batch'       => 'دفعة غير صالحة أو كبيرة. أعد رفع CSV واستخدم المعاينة أولاً.',
    'company_not_found'   => 'الشركة المختارة غير موجودة.',
    'dispatched_summary'  => 'تمّ إرسال الدفعة، أُنشئ :created، أُرسل :sent عبر واتساب، فشل :failed، تُخطّي :skipped.',

    // الرأس
    'page_h1'             => 'المطالبات الجماعية',
    'page_sub'            => 'أنشئ بطاقات جاهزة للعملاء الباردين وأرسل روابط المطالبة السحرية عبر واتساب.',

    // نتائج الإرسال
    'send_results'        => 'نتائج الإرسال',
    'view_dashboard'      => 'عرض لوحة الدفعة ←',
    'sent_label'          => 'مُرسلة',
    'skipped_label'       => 'مُتَخطّى (:reason)',
    'failed_label'        => 'فشلت',

    // لوحة الرفع
    'step_1'              => '1. الصق أو ارفع CSV',
    'step_1_hint'         => 'الأعمدة: <code class="text-[11px] bg-gray-50 px-1 rounded">name, phone, company_name, title, email</code>. الصف الأول عنوان.',
    'field_batch_label'   => 'اسم الدفعة (اختياري)',
    'batch_label_ph'      => 'مثال: توسّع كارديفاي أبريل 2026',
    'field_host_company'  => 'الشركة المستضيفة (ستُنشأ البطاقات تحتها)',
    'field_upload'        => 'ارفع ملف .csv',
    'field_paste'         => '…أو الصق CSV',
    'max_leads_note'      => 'الحد الأقصى :max عميل لكل دفعة.',
    'btn_preview'         => 'معاينة',

    // الدفعات الأخيرة
    'recent_batches'      => 'الدفعات الأخيرة',
    'no_batches'          => 'لا توجد دفعات بعد.',
    'unnamed'             => '(بدون اسم)',
    'batch_counts'        => ':sent/:total أُرسلت · :claimed طُولبت · :active نشطة',

    // جدول المعاينة
    'step_2'              => '2. المعاينة (:n صف)',
    'count_ready'         => ':n جاهز',
    'count_duplicate'     => ':n مكرّر',
    'count_invalid'       => ':n غير صالح',
    'confirm_send'        => 'إرسال :n رابط سحري الآن عبر واتساب؟ سيتمّ تخطي المكرّرات وغير الصالحة تلقائياً.',
    'btn_send_n'          => 'إرسال إلى :n عميل',
    'btn_send_n_plural'   => 'إرسال إلى :n عميل',
    'col_name'            => 'الاسم',
    'col_phone'           => 'الهاتف',
    'col_company'         => 'الشركة',
    'col_title'           => 'المنصب',
    'col_status'          => 'الحالة',
    'status_ready'        => 'جاهز',
    'status_already_card' => 'يملك بطاقة بالفعل',
    'status_already_lead' => 'تمّ التواصل معه',

    // أسباب التخطّي
    'reason_missing_name'      => 'اسم ناقص',
    'reason_invalid_phone'     => 'هاتف غير صالح',
    'reason_already_in_leads'  => 'تمّ التواصل معه',
    'reason_already_has_card'  => 'يملك بطاقة بالفعل',
    'reason_employee_insert_failed' => 'فشل إدراج الموظف',
    'reason_lead_insert_failed'     => 'فشل إدراج العميل',
    'reason_wa_send_failed'    => 'فشل إرسال واتساب',

    // لوحة الدفعة
    'batch_prefix'        => 'الدفعة: :label',
    'batch_meta'          => 'أُنشئت :date · :sent/:total أُرسلت · الحالة: :status',
    'col_wa'              => 'واتساب',
    'col_opened'          => 'فُتحت',
    'col_claimed'         => 'طُولبت',
    'col_active'          => 'نشطة',
    'wa_sent'             => 'أُرسلت',
    'wa_failed'           => 'فشلت',
    'wa_pending'          => 'قيد الإرسال',
];
