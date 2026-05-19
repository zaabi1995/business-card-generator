<?php
/** @var string $employeeName */
/** @var string $employeePosition */
/** @var string $employeeNameAr */
/** @var string $employeeEmail */
/** @var string $employeeMobile */
/** @var string $companyName */
/** @var string $editUrl */
/** @var string $publicUrl */
/** @var string|null $brandColor */
/** @var string|null $secondaryColor */
/** @var string|null $logoUrl */
/** @var string|null $supportEmail */
/** @var string|null $printDeadline */

$firstName    = trim(strtok(trim($employeeName), ' ')) ?: 'there';
$firstNameEsc = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
$fullNameEsc  = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
$positionEsc  = htmlspecialchars($employeePosition, ENT_QUOTES, 'UTF-8');
$arabicEsc    = htmlspecialchars($employeeNameAr, ENT_QUOTES, 'UTF-8');
$emailEsc     = htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8');
$mobileEsc    = htmlspecialchars($employeeMobile, ENT_QUOTES, 'UTF-8');
$companyEsc   = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$editUrlEsc   = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
$publicUrlEsc = htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8');
$color        = $brandColor ?: '#2d13ea';
$colorEsc     = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$accent       = $secondaryColor ?: '#ff7800';
$accentEsc    = htmlspecialchars($accent, ENT_QUOTES, 'UTF-8');
$supportEsc   = htmlspecialchars($supportEmail ?: 'info@cardify.om', ENT_QUOTES, 'UTF-8');
$deadlineEsc  = htmlspecialchars($printDeadline ?: '28 May 2026', ENT_QUOTES, 'UTF-8');

$logoBlock = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:36px;display:block;margin:0 0 20px 0;border:0;">';
}

$roleLine = $positionEsc !== ''
    ? "As <strong>{$positionEsc}</strong> at {$companyEsc}, your card is on the way."
    : "{$companyEsc} has set up your business card.";

$subject = "Welcome, {$firstName}, please confirm your details before we print your {$companyName} card";

$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<style>
@media only screen and (max-width: 480px) {
  .container { padding: 16px !important; }
  .cta { display: block !important; width: 100% !important; padding: 16px 0 !important; font-size: 16px !important; box-sizing: border-box; }
  .h1 { font-size: 20px !important; line-height: 1.3 !important; }
  .details-box { padding: 18px !important; }
  .details-name { font-size: 17px !important; }
  .step-row { padding: 14px 0 !important; }
  .step-num { width: 28px !important; height: 28px !important; line-height: 28px !important; font-size: 14px !important; }
  .link-pill { font-size: 12px !important; padding: 8px 10px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f7f8fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1f2937;-webkit-text-size-adjust:100%;">

<div class="container" style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">

{$logoBlock}

<h1 class="h1" style="margin:0 0 8px 0;font-size:24px;font-weight:700;color:#0f172a;line-height:1.25;">Welcome aboard, {$firstNameEsc}.</h1>
<p style="margin:0 0 24px 0;color:#475569;font-size:15px;">{$roleLine}</p>

<p style="margin:0 0 12px 0;font-size:13px;letter-spacing:1px;text-transform:uppercase;color:#64748b;font-weight:600;">Your details for the card</p>
<div class="details-box" style="background:{$colorEsc};color:#ffffff;padding:22px 24px;border-radius:12px;margin:0 0 12px 0;border-bottom:4px solid {$accentEsc};">
  <p class="details-name" style="margin:0 0 4px 0;font-size:19px;font-weight:700;color:#ffffff;">{$fullNameEsc}</p>
HTML;

if ($arabicEsc !== '') {
    $body .= "\n  <p style=\"margin:0 0 4px 0;font-size:15px;color:#ffffff;\" dir=\"rtl\">{$arabicEsc}</p>";
}
if ($positionEsc !== '') {
    $body .= "\n  <p style=\"margin:0 0 12px 0;font-size:14px;color:rgba(255,255,255,0.9);\">{$positionEsc}</p>";
}
if ($mobileEsc !== '' || $emailEsc !== '') {
    $body .= "\n  <p style=\"margin:0;font-size:13px;color:rgba(255,255,255,0.85);\">";
    if ($mobileEsc !== '') $body .= $mobileEsc;
    if ($mobileEsc !== '' && $emailEsc !== '') $body .= " &middot; ";
    if ($emailEsc !== '') $body .= $emailEsc;
    $body .= "</p>";
}

$body .= <<<HTML

</div>
<p style="margin:0 0 28px 0;font-size:13px;color:#64748b;">These are the details we'll print on your card on <strong style="color:{$colorEsc};">{$deadlineEsc}</strong>. Please review them now.</p>

<p style="margin:0 0 8px 0;font-weight:600;color:#0f172a;font-size:16px;">Getting started with Cardify, 3 steps:</p>

<div class="step-row" style="padding:14px 0;border-top:1px solid #e5e7eb;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;"><tr>
    <td valign="top" style="width:36px;padding-right:14px;">
      <span class="step-num" style="display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;background:{$colorEsc};color:#ffffff;border-radius:50%;font-weight:700;font-size:14px;">1</span>
    </td>
    <td valign="top">
      <p style="margin:0 0 4px 0;font-weight:600;color:#0f172a;font-size:15px;">Review and update your details</p>
      <p style="margin:0;font-size:14px;color:#475569;">Open your private edit link. Every change saves instantly.</p>
      <p style="margin:8px 0 0 0;text-align:center;"><a class="cta" href="{$editUrlEsc}" style="background:{$colorEsc};color:#ffffff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;border-bottom:3px solid {$accentEsc};">Open my edit link</a></p>
      <p class="link-pill" style="margin:8px 0 0 0;font-size:12px;word-break:break-all;background:#f3f4f6;padding:8px 10px;border-radius:6px;color:#475569;">{$editUrlEsc}</p>
    </td>
  </tr></table>
</div>

<div class="step-row" style="padding:14px 0;border-top:1px solid #e5e7eb;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;"><tr>
    <td valign="top" style="width:36px;padding-right:14px;">
      <span class="step-num" style="display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;background:{$colorEsc};color:#ffffff;border-radius:50%;font-weight:700;font-size:14px;">2</span>
    </td>
    <td valign="top">
      <p style="margin:0 0 4px 0;font-weight:600;color:#0f172a;font-size:15px;">Share your digital card</p>
      <p style="margin:0;font-size:14px;color:#475569;">Your card has a public page anyone can open from a tap, QR scan, or shared link. Share it instead of typing your details every time.</p>
      <p style="margin:8px 0 0 0;text-align:center;"><a class="cta" href="{$publicUrlEsc}" style="background:#ffffff;color:{$colorEsc};padding:11px 23px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;font-size:14px;border:1px solid {$colorEsc};">See my public card</a></p>
      <p class="link-pill" style="margin:8px 0 0 0;font-size:12px;word-break:break-all;background:#f3f4f6;padding:8px 10px;border-radius:6px;color:#475569;">{$publicUrlEsc}</p>
    </td>
  </tr></table>
</div>

<div class="step-row" style="padding:14px 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;"><tr>
    <td valign="top" style="width:36px;padding-right:14px;">
      <span class="step-num" style="display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;background:{$colorEsc};color:#ffffff;border-radius:50%;font-weight:700;font-size:14px;">3</span>
    </td>
    <td valign="top">
      <p style="margin:0 0 4px 0;font-weight:600;color:#0f172a;font-size:15px;">Print arrives at the office</p>
      <p style="margin:0;font-size:14px;color:#475569;">We'll have the printed cards ready for collection shortly after {$deadlineEsc}. Your digital card stays editable for life.</p>
    </td>
  </tr></table>
</div>

<p style="margin:20px 0 0 0;font-size:13px;color:#64748b;">Save the edit link somewhere you can find it later, you can come back any time when your role, phone, or email changes.</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 16px 0;">

<p style="margin:0;font-size:14px;color:#475569;">Welcome aboard,<br><strong style="color:#0f172a;">The {$companyEsc} team</strong></p>
<p style="margin:10px 0 0 0;font-size:12px;color:#94a3b8;">Questions? Reply to this email or write to <a href="mailto:{$supportEsc}" style="color:#94a3b8;">{$supportEsc}</a>.</p>

</div>

</body>
</html>
HTML;
