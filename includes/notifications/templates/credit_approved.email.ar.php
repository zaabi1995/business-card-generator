<?php
/** @var string $contactName */
/** @var string $companyName */
/** @var string $shopName */
/** @var string $limitFormatted */
/** @var string $currency */
/** @var string $paymentTerms */
/** @var string|null $exposureFormatted */
/** @var string $dashboardUrl */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = trim(strtok(trim($contactName), ' ')) ?: '';
$greeting   = $firstName !== '' ? 'مرحباً ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '،' : 'مرحباً،';
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$shopEsc    = htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8');
$limitEsc   = htmlspecialchars($limitFormatted, ENT_QUOTES, 'UTF-8');
$termsEsc   = htmlspecialchars($paymentTerms, ENT_QUOTES, 'UTF-8');
$dashEsc    = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#1e40af';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $shopEsc . '" style="max-height:48px;"></div>';
}
$exposureBlock = '';
if (!empty($exposureFormatted)) {
    $exposureEsc = htmlspecialchars($exposureFormatted, ENT_QUOTES, 'UTF-8');
    $exposureBlock = '<p style="color:#6b7280;font-size:14px;margin:4px 0;">سقف التعرّض (الحد الأقصى للمستحق في آن واحد): <strong style="color:#1f2937;">' . $exposureEsc . '</strong></p>';
}

$subject = "تمّ اعتماد حساب الائتمان الخاص بك لدى {$shopName}";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">تمّ اعتماد حساب الائتمان</h1>
  <p>{$greeting}</p>
  <p>اعتمدت <strong>{$shopEsc}</strong> حساب ائتمان لـ <strong>{$companyEsc}</strong>. يمكنك الآن تقديم طلبات الطباعة بالآجل وفق الشروط أدناه.</p>

  <table role="presentation" width="100%" style="border-collapse:collapse;margin:20px 0;background:#f8fafc;border-radius:8px;">
    <tr>
      <td style="padding:16px;">
        <div style="font-size:13px;color:#6b7280;">سقف الائتمان</div>
        <div style="font-size:22px;font-weight:700;color:{$colorEsc};" dir="ltr">{$limitEsc}</div>
      </td>
      <td style="padding:16px;text-align:left;">
        <div style="font-size:13px;color:#6b7280;">شروط الدفع</div>
        <div style="font-size:22px;font-weight:700;color:#1f2937;">{$termsEsc}</div>
      </td>
    </tr>
  </table>
  {$exposureBlock}

  <p style="margin:24px 0;">
    <a href="{$dashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">عرض حساب الائتمان</a>
  </p>

  <p style="color:#6b7280;font-size:13px;">ستُصدر الفواتير مع كل طلب. التزم بالشروط لبقاء حسابك بحالة جيدة.</p>
  <p style="color:#6b7280;font-size:13px;">لأي استفسار بخصوص الحساب، ردّ على هذه الرسالة وسيجيبك فريق {$shopEsc}.</p>
</div>
HTML;
