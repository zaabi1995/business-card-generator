<?php
/** @var string $contactName */
/** @var string $companyName */
/** @var string $month           Human month label, e.g. "April 2026" */
/** @var int    $taps            Total card taps */
/** @var int    $saves           Contacts saved */
/** @var int    $leads           Lead form submissions */
/** @var int    $activeCards     Cards viewed at least once */
/** @var int    $newEmployees    Employees onboarded this month */
/** @var string $dashboardUrl    Link back to admin dashboard */
/** @var array  $topEmployees    [['name'=>..., 'taps'=>int], ...] up to 5 */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = htmlspecialchars(trim(strtok(trim($contactName), ' ')) ?: 'there', ENT_QUOTES, 'UTF-8');
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
                  . '<td style="padding:8px 0;text-align:right;font-weight:600;color:#1f2937;">' . number_format($t) . '</td></tr>';
    }
}

$tapsFmt   = number_format((int) $taps);
$savesFmt  = number_format((int) $saves);
$leadsFmt  = number_format((int) $leads);
$activeFmt = number_format((int) $activeCards);
$newFmt    = number_format((int) $newEmployees);

$subject = "Your {$month} engagement report, {$companyName} on Cardify";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:4px;">{$monthEsc} recap</h1>
  <p style="color:#6b7280;margin-top:0;">Hi {$firstName}, here is how {$companyEsc} performed on Cardify this month.</p>

  <table role="presentation" width="100%" style="border-collapse:collapse;margin:20px 0;">
    <tr>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};">{$tapsFmt}</div>
        <div style="font-size:13px;color:#6b7280;">Card taps</div>
      </td>
      <td style="width:12px;"></td>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};">{$savesFmt}</div>
        <div style="font-size:13px;color:#6b7280;">Contacts saved</div>
      </td>
      <td style="width:12px;"></td>
      <td style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;width:33%;">
        <div style="font-size:24px;font-weight:700;color:{$colorEsc};">{$leadsFmt}</div>
        <div style="font-size:13px;color:#6b7280;">Leads</div>
      </td>
    </tr>
  </table>

  <p style="color:#6b7280;margin:0 0 12px;">Active cards this month: <strong style="color:#1f2937;">{$activeFmt}</strong> · New employees onboarded: <strong style="color:#1f2937;">{$newFmt}</strong></p>

HTML;
if ($topRows !== '') {
    $body .= '<h2 style="font-size:16px;margin:20px 0 8px;color:#1f2937;">Top performers</h2>'
           . '<table role="presentation" width="100%" style="border-collapse:collapse;border-top:1px solid #e5e7eb;">'
           . $topRows
           . '</table>';
}
$body .= <<<HTML
  <p style="margin:28px 0;">
    <a href="{$dashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Open the full dashboard</a>
  </p>
  <p style="color:#6b7280;font-size:13px;">You are receiving this because you are an admin on {$companyEsc}. Manage notification settings from your account page.</p>
</div>
HTML;
