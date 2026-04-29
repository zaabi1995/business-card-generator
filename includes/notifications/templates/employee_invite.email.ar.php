<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var int    $expiresInDays */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */
$firstName  = trim(strtok(trim($employeeName), ' ')) ?: '';
$greeting   = $firstName !== '' ? 'مرحباً ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '،' : 'مرحباً،';
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$editUrlEsc = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#1e40af';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:48px;"></div>';
}
$subject = "{$companyName} أعدّت لك بطاقة عمل رقمية";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">بطاقتك الرقمية جاهزة</h1>
  <p>{$greeting}</p>
  <p>أعدّت <strong>{$companyEsc}</strong> بطاقة عمل رقمية لك على كارديفاي. أكمل إعدادها خلال دقيقتين: أضف صورتك، رابط لينكدإن، وأي تفاصيل أخرى تريدها.</p>
  <p style="margin:24px 0;">
    <a href="{$editUrlEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">أكمل بطاقتي</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">أو افتح هذا الرابط الخاص: <a href="{$editUrlEsc}" style="color:{$colorEsc};" dir="ltr">{$editUrlEsc}</a></p>
  <p style="color:#6b7280;font-size:14px;">هذا الرابط ينتهي خلال {$expiresInDays} يوماً. إذا لم تتوقّع هذه الرسالة، يمكنك تجاهلها بأمان.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق {$companyEsc} عبر كارديفاي</p>
</div>
HTML;
