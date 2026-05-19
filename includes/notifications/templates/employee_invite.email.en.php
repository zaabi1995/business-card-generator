<?php
/** @var string $employeeName */
/** @var string $employeePosition */
/** @var string $employeeNameAr */
/** @var string $employeeEmail */
/** @var string $employeeMobile */
/** @var string $companyName */
/** @var string $editUrl */
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

// Build personalised greeting variants
$roleLine = $positionEsc !== ''
    ? "As <strong>{$positionEsc}</strong> at {$companyEsc}, this is your card."
    : "We've set up your card at {$companyEsc}.";

$subject = "Welcome, {$firstName}, please review your {$companyName} business card";

// Slim, mobile-responsive HTML. Inline styles for client compat,
// plus a <style> block with @media for adaptive layout in clients
// that honour it (iOS Mail, Gmail iOS app, most modern web clients).
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
  .card-preview { padding: 18px !important; }
  .card-name { font-size: 17px !important; }
  .check-list li { font-size: 15px !important; padding: 6px 0 !important; }
}
@media (prefers-color-scheme: dark) {
  body { background: #ffffff !important; color: #1f2937 !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f7f8fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1f2937;-webkit-text-size-adjust:100%;">

<div class="container" style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;">

{$logoBlock}

<h1 class="h1" style="margin:0 0 8px 0;font-size:24px;font-weight:700;color:#0f172a;line-height:1.25;">Welcome aboard, {$firstNameEsc}.</h1>
<p style="margin:0 0 20px 0;color:#475569;font-size:15px;">{$roleLine}</p>

<div class="card-preview" style="background:{$colorEsc};color:#ffffff;padding:24px;border-radius:12px;margin:0 0 24px 0;border-bottom:4px solid {$accentEsc};">
  <p style="margin:0 0 4px 0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.75);">Your card</p>
  <p class="card-name" style="margin:0 0 4px 0;font-size:19px;font-weight:700;color:#ffffff;">{$fullNameEsc}</p>
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
    if ($mobileEsc !== '' && $emailEsc !== '') $body .= " · ";
    if ($emailEsc !== '') $body .= $emailEsc;
    $body .= "</p>";
}

$body .= <<<HTML

</div>

<p style="margin:0 0 8px 0;font-size:15px;color:#1f2937;">Before we send the first print run on <strong style="color:{$colorEsc};">{$deadlineEsc}</strong>, please open your private link and confirm everything is correct.</p>

<p style="margin:24px 0;text-align:center;">
  <a class="cta" href="{$editUrlEsc}" style="background:{$colorEsc};color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;border-bottom:3px solid {$accentEsc};">Review my card</a>
</p>

<p style="margin:16px 0 4px 0;font-size:13px;color:#64748b;">If the button does not work, copy this link into your browser:</p>
<p style="margin:0 0 24px 0;font-size:13px;word-break:break-all;background:#f3f4f6;padding:10px 12px;border-radius:6px;border-left:3px solid {$colorEsc};"><a href="{$editUrlEsc}" style="color:{$colorEsc};text-decoration:none;">{$editUrlEsc}</a></p>

<p style="margin:0 0 8px 0;font-weight:600;color:#0f172a;font-size:15px;">A quick checklist for {$firstNameEsc}:</p>
<ul class="check-list" style="margin:0 0 20px 0;padding-left:22px;color:#334155;font-size:14px;">
  <li style="padding:4px 0;">Full name spelling, English and Arabic</li>
  <li style="padding:4px 0;">Position and title</li>
  <li style="padding:4px 0;">Mobile number and direct line</li>
  <li style="padding:4px 0;">Email address</li>
</ul>

<p style="margin:0 0 12px 0;font-size:14px;color:#475569;">Every change saves immediately, and the printed card and the shared link both update with it. Reopen the same link any time later when your role or phone changes, no need to ask anyone.</p>

<p style="margin:24px 0 0 0;font-size:13px;color:#64748b;">This link is unique to you. Please don't share it publicly.</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 18px 0;">

<p style="margin:0;font-size:14px;color:#475569;">Welcome aboard,<br><strong style="color:#0f172a;">The {$companyEsc} team</strong></p>
<p style="margin:10px 0 0 0;font-size:12px;color:#94a3b8;">Questions? Reply to this email or write to <a href="mailto:{$supportEsc}" style="color:#94a3b8;">{$supportEsc}</a>.</p>

</div>

</body>
</html>
HTML;
