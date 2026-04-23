<?php
/** @var string $contactName */
/** @var string $companyName */
/** @var string $month           Human month label, e.g. "أبريل 2026" */
/** @var int    $taps            Total card taps */
/** @var int    $saves           Contacts saved */
/** @var int    $leads           Lead form submissions */
/** @var int    $activeCards     Cards viewed at least once */
/** @var int    $newEmployees    Employees onboarded this month */
/** @var string $dashboardUrl    Link back to admin dashboard */
/** @var array  $topEmployees    [['name'=>..., 'taps'=>int], ...] up to 5 */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = trim(strtok(trim($contactName), ' ')) ?: '';
$greeting   = $firstName !== '' ? 'مرحباً ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '،' : 'مرحباً،';
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$monthEsc   = htmlspecialchars($month, ENT_QUOTES, 'UTF-8');
$dashEsc    = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#1e40af';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:48px;"></div>';
}

$topRows = '';
if (!empty($topEmployees)) {
    foreach ($topEmployees as $emp) {
        $n = htmlspecialchars((string) ($emp['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $t = (int) ($emp['taps'] ?? 0);
        $topRows .= '<tr><td style="padding:8px 0;color:#1f2937;">' . $n . '</td>'
                  . '<td style="padding:8px 0;text-align:left;font-weight:600;color:#1f2937;" dir="ltr">' . number_format($t) . '</td></tr>';
    }
}

$tapsFmt   = number_format((int) $taps);
$savesFmt  = number_format((int) $saves);
$leadsFmt  = number_format((int) $leads);
$activeFmt = number_format((int) $activeCards);
$newFmt    = number_format((int) $newEmployees);

$subject = "تقرير تفاعل {$month}, {$companyName} على كارديفاي";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:640px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:4px;">ملخّص {$monthEsc}</h1>
  <p style="color:#6b7280;margin-top:0;">{$greeting} هذا أداء {$companyEsc} على كارديفاي خلال الشهر.</p>

  <table role="presentation" width="100%" style="border-collapse:collapse;margin:20px 0;">
    <tr>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};" dir="ltr">{$tapsFmt}</div>
        <div style="font-size:13px;color:#6b7280;">نقرات البطاقات</div>
      </td>
      <td style="width:12px;"></td>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};" dir="ltr">{$savesFmt}</div>
        <div style="font-size:13px;color:#6b7280;">جهات اتصال محفوظة</div>
      </td>
      <td style="width:12px;"></td>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};" dir="ltr">{$leadsFmt}</div>
        <div style="font-size:13px;color:#6b7280;">عملاء محتملون</div>
      </td>
    </tr>
  </table>

  <p style="color:#6b7280;margin:0 0 12px;">البطاقات النشطة هذا الشهر: <strong style="color:#1f2937;" dir="ltr">{$activeFmt}</strong> · الموظفون المُضافون: <strong style="color:#1f2937;" dir="ltr">{$newFmt}</strong></p>

HTML;
if ($topRows !== '') {
    $body .= '<h2 style="font-size:16px;margin:20px 0 8px;color:#1f2937;">أفضل الموظفين أداءً</h2>'
           . '<table role="presentation" width="100%" style="border-collapse:collapse;border-top:1px solid #e5e7eb;">'
           . $topRows
           . '</table>';
}
$body .= <<<HTML
  <p style="margin:28px 0;">
    <a href="{$dashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">افتح لوحة التحكم</a>
  </p>
  <p style="color:#6b7280;font-size:13px;">تتلقّى هذه الرسالة لأنّك مشرف على {$companyEsc}. يمكنك تعديل إعدادات الإشعارات من صفحة حسابك.</p>
</div>
HTML;
