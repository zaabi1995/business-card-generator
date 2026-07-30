<?php
/** @var string $adminName */
/** @var string $employeeName */
/** @var string $companyName */
/** @var array  $changedFields */
/** @var string $employeeUrl */
$subject = "{$employeeName} حدّث بطاقته";
$labelMap = [
    'name_en' => 'الاسم (EN)', 'name_ar' => 'الاسم (AR)',
    'position_en' => 'المسمّى الوظيفي', 'phone' => 'الهاتف',
    'mobile' => 'الجوّال', 'email' => 'البريد', 'website' => 'الموقع',
    'photo' => 'الصورة', 'socials' => 'روابط التواصل',
];
$fieldList = '';
foreach ($changedFields as $f) {
    $label = $labelMap[$f] ?? $f;
    $fieldList .= "<li>{$label}</li>";
}
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h2 style="color:#00718c;margin-bottom:8px;">قام أحد أعضاء الفريق بتحديث بياناته</h2>
  <p>مرحباً {$adminName}،</p>
  <p>قام <strong>{$employeeName}</strong> في {$companyName} للتوّ بتعديل بطاقته من البوّابة الذاتية.</p>
  <p><strong>الحقول المعدّلة:</strong></p>
  <ul style="color:#4b5563;font-size:14px;padding-right:20px;">{$fieldList}</ul>
  <p style="margin:20px 0;">
    <a href="{$employeeUrl}" style="background:#009bc1;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">عرض في لوحة الإدارة</a>
  </p>
  <p style="color:#6b7280;font-size:12px;">يمكنك إيقاف هذه الرسالة من إعدادات الشركة، الإشعارات.</p>
</div>
HTML;
