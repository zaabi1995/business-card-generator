<?php
/** @var string $contactName */
/** @var string $companyName */
/** @var int    $totalCount */
/** @var int    $daysRemaining */
/** @var array  $breakdown */
/** @var string $trashUrl */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = trim(strtok(trim($contactName), ' ')) ?: '';
$greeting   = $firstName !== '' ? 'مرحباً ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '،' : 'مرحباً،';
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$trashEsc   = htmlspecialchars($trashUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#b45309';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:48px;"></div>';
}

$typeLabels = [
    'employees'       => 'موظفون',
    'departments'     => 'أقسام',
    'templates'       => 'قوالب',
    'print_orders'    => 'طلبات طباعة',
    'credit_accounts' => 'حسابات ائتمان',
    'card_requests'   => 'طلبات بطاقات',
];

$breakdownRows = '';
foreach ($breakdown as $key => $n) {
    $n = (int) $n;
    if ($n <= 0) continue;
    $label = htmlspecialchars($typeLabels[$key] ?? $key, ENT_QUOTES, 'UTF-8');
    $breakdownRows .= '<tr><td style="padding:8px 0;color:#1f2937;">' . $label . '</td>'
                   . '<td style="padding:8px 0;text-align:left;font-weight:600;color:#1f2937;" dir="ltr">' . number_format($n) . '</td></tr>';
}

$totalFmt = number_format((int) $totalCount);
$subject = "{$totalCount} عناصر في سلة مهملات {$companyName} ستُحذف نهائياً خلال {$daysRemaining} يوماً";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">عناصر مقرّر حذفها نهائياً</h1>
  <p>{$greeting}</p>
  <p>تنبيه: <strong dir="ltr">{$totalFmt}</strong> عنصراً في سلة مهملات <strong>{$companyEsc}</strong> ستُحذف نهائياً خلال <strong dir="ltr">{$daysRemaining}</strong> يوماً. استعِد أي شيء تحتاجه قبل ذلك، لأن البيانات ستُفقد بعدها.</p>
HTML;
if ($breakdownRows !== '') {
    $body .= '<h2 style="font-size:16px;margin:20px 0 8px;color:#1f2937;">ما بداخل السلة</h2>'
           . '<table role="presentation" width="100%" style="border-collapse:collapse;border-top:1px solid #e5e7eb;">'
           . $breakdownRows
           . '</table>';
}
$body .= <<<HTML
  <p style="margin:24px 0;">
    <a href="{$trashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">استعراض سلة المهملات</a>
  </p>
  <p style="color:#6b7280;font-size:13px;">إذا كنت تقصد حذف هذه العناصر، يمكنك تجاهل هذه الرسالة وستُنظَّف تلقائياً.</p>
</div>
HTML;
