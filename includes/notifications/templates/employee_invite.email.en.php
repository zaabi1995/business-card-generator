<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var int    $expiresInDays */
/** @var string|null $brandColor */
/** @var string|null $logoUrl */
$firstName  = htmlspecialchars(trim(strtok(trim($employeeName), ' ')) ?: 'there', ENT_QUOTES, 'UTF-8');
$companyEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$editUrlEsc = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
$color      = $brandColor ?: '#1e40af';
$colorEsc   = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$logoBlock  = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="text-align:center;margin-bottom:16px;"><img src="' . $logoEsc . '" alt="' . $companyEsc . '" style="max-height:48px;"></div>';
}
$subject = "{$companyName} has a digital business card ready for you";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  {$logoBlock}
  <h1 style="color:{$colorEsc};margin-bottom:8px;">Your Cardify card is ready</h1>
  <p>Hi {$firstName},</p>
  <p><strong>{$companyEsc}</strong> has set up a digital business card for you on Cardify. Finish it in about 2 minutes: add your photo, LinkedIn, and anything else you want on it.</p>
  <p style="margin:24px 0;">
    <a href="{$editUrlEsc}" style="background:{$colorEsc};color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Finish my card</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">Or open this private link: <a href="{$editUrlEsc}" style="color:{$colorEsc};">{$editUrlEsc}</a></p>
  <p style="color:#6b7280;font-size:14px;">This link expires in {$expiresInDays} days. If you did not expect this email, you can safely ignore it.</p>
  <p style="color:#6b7280;font-size:14px;">, The {$companyEsc} team, on Cardify</p>
</div>
HTML;
