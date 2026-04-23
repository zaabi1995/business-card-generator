<?php
return [
    // رسائل فوريّة
    'invalid_request'   => 'طلب غير صالح',
    'need_msg_recipient'=> 'يُرجى تعبئة الرسالة واسم مستلم واحد على الأقل.',
    'sent_summary'      => 'أُرسلت الحملة: :sent تمّ إرسالها، :failed فشلت.',

    // الرأس
    'page_h1'           => 'إدارة حملات BHD',
    'page_sub'          => 'التواصل، تتبّع الإحالات، وتحليلات التحويل',

    // نتائج الإرسال
    'send_results_h'    => 'نتائج الإرسال',
    'sent_label'        => 'مُرسلة',
    'failed_label'      => 'فشلت',

    // نموذج الإرسال
    'send_campaign_h'   => 'إرسال حملة',
    'field_campaign_name' => 'اسم الحملة',
    'field_channel'     => 'القناة',
    'channel_whatsapp'  => 'واتساب (عبر درداشة)',
    'channel_email'     => 'البريد (قالب فقط)',
    'field_msg_tpl'     => 'قالب الرسالة',
    'msg_tpl_hint'      => 'تُرسل رسائل الواتساب واحدة تلو الأخرى بتأخير 0.5 ثانية لتجنّب اعتبارها إزعاج.',
    'field_recipients'  => 'المستلمون',
    'recipients_sub'    => '(أرقام الهواتف، رقم لكل سطر)',
    'recipients_hint'   => 'الأرقام العمانية: 8 أرقام (تُضاف 968 تلقائياً).',
    'send_warning'      => 'تُرسل الرسائل فوراً. تحقّق من قائمة المستلمين قبل الإرسال.',
    'confirm_send'      => 'إرسال حملة واتساب إلى جميع المستلمين المدرجين؟',
    'btn_send'          => 'إرسال الحملة',
    'default_campaign'  => 'حملة BHD :date',

    // قالب البريد
    'email_tpl_h'       => 'قالب البريد',
    'email_tpl_sub'     => 'انسخه واستخدمه في برنامج بريدك',

    // إحصائيات الإحالة
    'stats_h'           => 'إحصائيات إحالة BHD',
    'stat_signups'      => 'التسجيلات من BHD',
    'stat_converted'    => 'حوّلوا إلى طلبات',
    'stat_conversion'   => 'نسبة التحويل',
    'stat_revenue'      => 'إيرادات BHD',
    'omr'               => 'ر.ع.',
    'view_landing'      => 'عرض صفحة BHD',

    // المصادر
    'signups_by_source' => 'التسجيلات حسب المصدر',
    'companies_suffix'  => 'شركة',

    // سجل الحملات
    'history_h'         => 'سجل الحملات',
    'history_empty'     => 'لا توجد حملات بعد',
    'n_sent'            => ':n مُرسلة',
    'n_failed'          => ':n فشلت',

    // جدول التسجيلات
    'signups_table_h'   => 'تسجيلات إحالة BHD (:n)',
    'col_company'       => 'الشركة',
    'col_contact'       => 'التواصل',
    'col_orders'        => 'الطلبات',
    'col_revenue'       => 'الإيرادات',
    'col_joined'        => 'تاريخ الانضمام',
    'col_status'        => 'الحالة',
    'status_converted'  => 'تحوّل',
    'status_onboarded'  => 'اكتمل الإعداد',
    'status_signed_up'  => 'سجّل',
];
