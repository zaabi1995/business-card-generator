<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var int    $expiresInDays  // kept for back-compat, no longer surfaced */
/** @var string|null $brandColor */
/** @var string|null $secondaryColor */
/** @var string|null $logoUrl */
/** @var string|null $supportEmail */
/** @var string|null $companyDomain */

$firstName    = htmlspecialchars(trim(strtok(trim($employeeName), ' ')) ?: 'there', ENT_QUOTES, 'UTF-8');
$companyEsc   = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
$editUrlEsc   = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
$color        = $brandColor ?: '#2d13ea';
$colorEsc     = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
$accent       = $secondaryColor ?: '#ff7800';
$accentEsc    = htmlspecialchars($accent, ENT_QUOTES, 'UTF-8');
$supportEsc   = htmlspecialchars($supportEmail ?: 'support@cardify.om', ENT_QUOTES, 'UTF-8');
$domainEsc    = htmlspecialchars($companyDomain ?: '', ENT_QUOTES, 'UTF-8');
$year         = date('Y');

// Tenant logo (absolute URL). When missing, fall back to the company
// initial in a coloured circle so the header still feels branded.
$logoBlock = '';
if (!empty($logoUrl)) {
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $logoBlock = '<img src="' . $logoEsc . '" alt="' . $companyEsc . '" height="40" style="display:block;margin:0 auto;max-height:48px;height:auto;width:auto;">';
} else {
    $initial = htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1)), ENT_QUOTES, 'UTF-8');
    $logoBlock = '<div style="width:56px;height:56px;border-radius:50%;background:#ffffff;color:' . $colorEsc . ';font-weight:700;font-size:28px;line-height:56px;text-align:center;margin:0 auto;">' . $initial . '</div>';
}

$subject = "{$companyName} digital business card · update your details";

// Full HTML email (Mailer skips wrapInTemplate when $options['skip_wrap']=true)
$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light only">
  <meta name="supported-color-schemes" content="light only">
  <title>{$companyEsc} · digital business card</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f5f7;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
      <tr>
        <td style="background:{$colorEsc};padding:32px 32px 28px;text-align:center;">
          {$logoBlock}
          <p style="margin:14px 0 0;color:rgba(255,255,255,0.85);font-size:13px;letter-spacing:0.6px;text-transform:uppercase;">{$companyEsc} · Digital Business Card</p>
        </td>
      </tr>
      <tr>
        <td style="padding:36px 36px 8px;">
          <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#0f172a;font-weight:700;">Hi {$firstName}, your {$companyEsc} card is ready</h1>
          <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">{$companyEsc} has set up a digital business card for you. Open your private link to review the design and make sure the details on the card are correct, name, position, phone, mobile, and email.</p>
          <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">Anything you change is saved immediately, and the printed and shared versions of your card update with it. You can come back to this link any time later if your role, phone, or email changes.</p>
          <p style="margin:0 0 32px;text-align:center;">
            <a href="{$editUrlEsc}" style="background:{$colorEsc};color:#ffffff;padding:14px 28px;border-radius:10px;text-decoration:none;display:inline-block;font-weight:600;font-size:15px;border-bottom:3px solid {$accentEsc};">Review my card</a>
          </p>
          <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748b;">Or open this private link directly:</p>
          <p style="margin:0 0 24px;font-size:13px;word-break:break-all;"><a href="{$editUrlEsc}" style="color:{$colorEsc};text-decoration:underline;">{$editUrlEsc}</a></p>
          <p style="margin:0 0 24px;font-size:13px;line-height:1.5;color:#64748b;">This link is unique to you, please don't share it. If you didn't expect this email, you can safely ignore it.</p>
        </td>
      </tr>
      <tr>
        <td style="padding:0 36px 28px;">
          <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 18px;">
          <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">Questions? Reply to this email or contact <a href="mailto:{$supportEsc}" style="color:{$colorEsc};">{$supportEsc}</a>.</p>
        </td>
      </tr>
      <tr>
        <td style="background:#f9fafb;padding:20px 36px;text-align:center;border-top:1px solid #e5e7eb;">
          <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">© {$year} {$companyEsc}. Card management powered by <a href="https://cardify.om/" style="color:#94a3b8;text-decoration:underline;">Cardify</a>.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
