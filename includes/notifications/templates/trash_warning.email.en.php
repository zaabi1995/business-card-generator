<?php
/** @var string $contactName      Admin contact name */
/** @var string $companyName      Company name */
/** @var int    $totalCount       Total items scheduled for deletion */
/** @var int    $daysRemaining    Days until permanent deletion */
/** @var array  $breakdown        ['employees'=>3, 'templates'=>1, ...], zeros omitted */
/** @var string $trashUrl         Link to the recycle bin page */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = htmlspecialchars(trim(strtok(trim($contactName), ' ')) ?: 'there', ENT_QUOTES, 'UTF-8');
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$trashEsc   = htmlspecialchars($trashUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#b45309'; // amber-700, signals warning
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:48px;"></div>';
}

$typeLabels = [
    'employees'       => 'employees',
    'departments'     => 'departments',
    'templates'       => 'templates',
    'print_orders'    => 'print orders',
    'credit_accounts' => 'credit accounts',
    'card_requests'   => 'card requests',
];

$breakdownRows = '';
foreach ($breakdown as $key => $n) {
    $n = (int) $n;
    if ($n <= 0) continue;
    $label = htmlspecialchars($typeLabels[$key] ?? $key, ENT_QUOTES, 'UTF-8');
    $breakdownRows .= '<tr><td style="padding:8px 0;color:#1f2937;">' . $label . '</td>'
                   . '<td style="padding:8px 0;text-align:right;font-weight:600;color:#1f2937;">' . number_format($n) . '</td></tr>';
}

$totalFmt = number_format((int) $totalCount);
$subject = "{$totalCount} items in {$companyName}'s recycle bin will be permanently deleted in {$daysRemaining} days";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">Items scheduled for permanent deletion</h1>
  <p>Hi {$firstName},</p>
  <p>A heads-up: <strong>{$totalFmt}</strong> items in <strong>{$companyEsc}</strong>'s recycle bin will be permanently deleted in <strong>{$daysRemaining}</strong> days. Restore anything you still need before then, after that the data is gone.</p>
HTML;
if ($breakdownRows !== '') {
    $body .= '<h2 style="font-size:16px;margin:20px 0 8px;color:#1f2937;">What is in the bin</h2>'
           . '<table role="presentation" width="100%" style="border-collapse:collapse;border-top:1px solid #e5e7eb;">'
           . $breakdownRows
           . '</table>';
}
$body .= <<<HTML
  <p style="margin:24px 0;">
    <a href="{$trashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Review recycle bin</a>
  </p>
  <p style="color:#6b7280;font-size:13px;">If you meant to delete these items, you can ignore this email, they will be cleaned up automatically.</p>
</div>
HTML;
