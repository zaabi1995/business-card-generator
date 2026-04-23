<?php
/** @var string $contactName       Customer contact name */
/** @var string $companyName       Customer company name */
/** @var string $shopName          Print shop that approved the account */
/** @var string $limitFormatted    e.g. "500.000 OMR" */
/** @var string $currency          Currency code */
/** @var string $paymentTerms      e.g. "Net 30", "Net 60" */
/** @var string|null $exposureFormatted  e.g. "250.000 OMR", optional */
/** @var string $dashboardUrl      Link to company billing/credit page */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */

$firstName  = htmlspecialchars(trim(strtok(trim($contactName), ' ')) ?: 'there', ENT_QUOTES, 'UTF-8');
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
    $exposureBlock = '<p style="color:#6b7280;font-size:14px;margin:4px 0;">Exposure cap (max outstanding at once): <strong style="color:#1f2937;">' . $exposureEsc . '</strong></p>';
}

$subject = "Your credit account with {$shopName} is approved";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">Credit account approved</h1>
  <p>Hi {$firstName},</p>
  <p><strong>{$shopEsc}</strong> has approved a credit account for <strong>{$companyEsc}</strong>. You can now place print orders on credit with the terms below.</p>

  <table role="presentation" width="100%" style="border-collapse:collapse;margin:20px 0;background:#f8fafc;border-radius:8px;">
    <tr>
      <td style="padding:16px;">
        <div style="font-size:13px;color:#6b7280;">Credit limit</div>
        <div style="font-size:22px;font-weight:700;color:{$colorEsc};">{$limitEsc}</div>
      </td>
      <td style="padding:16px;text-align:right;">
        <div style="font-size:13px;color:#6b7280;">Payment terms</div>
        <div style="font-size:22px;font-weight:700;color:#1f2937;">{$termsEsc}</div>
      </td>
    </tr>
  </table>
  {$exposureBlock}

  <p style="margin:24px 0;">
    <a href="{$dashEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">View my credit account</a>
  </p>

  <p style="color:#6b7280;font-size:13px;">Invoices will be issued per order. Keep an eye on the terms so your account stays in good standing.</p>
  <p style="color:#6b7280;font-size:13px;">Questions about this account? Reply to this email and the {$shopEsc} team will get back to you.</p>
</div>
HTML;
