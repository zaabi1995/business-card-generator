<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */
/** @var string|null $supportEmail */

$firstName  = trim(strtok(trim($employeeName), ' ')) ?: '';
$greeting   = $firstName !== '' ? 'مرحباً ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '،' : 'مرحباً،';
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$editUrlEsc = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#2d13ea';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$supportEsc = htmlspecialchars($supportEmail ?: 'info@cardify.om', ENT_QUOTES, 'UTF-8');

$logoBlock = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:36px;display:block;margin:0 0 20px 0;">';
}

$subject = "{$companyName} · بطاقتك الرقمية";

$body = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"></head>
<body style="font-family:'IBM Plex Sans Arabic',Cairo,Tajawal,Segoe UI,Arial,sans-serif;line-height:1.7;color:#1f2937;max-width:560px;margin:0 auto;padding:24px;direction:rtl;">
{$logoBlock}
<p>{$greeting}</p>
<p>أعدّت {$companyEsc} بطاقة عمل رقمية لك. افتح رابطك الخاص أدناه لمراجعة بيانات البطاقة (الاسم، المسمّى الوظيفي، رقم الهاتف، الجوّال، البريد الإلكتروني) وتحديث أي شيء يحتاج تعديلاً.</p>
<p style="margin:24px 0;"><a href="{$editUrlEsc}" style="background:{$colorEsc};color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">مراجعة بطاقتي</a></p>
<p>أو افتح هذا الرابط: <a href="{$editUrlEsc}" style="color:{$colorEsc};" dir="ltr">{$editUrlEsc}</a></p>
<p>أي تعديل يُحفظ فوراً، وتتحدّث معه نسخة الطباعة والنسخة المشاركة. يمكنك العودة إلى هذا الرابط في أي وقت إذا تغيّر مسمّاك الوظيفي أو رقم هاتفك أو بريدك.</p>
<p style="color:#6b7280;font-size:13px;margin-top:32px;">فريق {$companyEsc} · للاستفسار، ردّ على هذه الرسالة أو راسل <span dir="ltr">{$supportEsc}</span>.</p>
</body>
</html>
HTML;
