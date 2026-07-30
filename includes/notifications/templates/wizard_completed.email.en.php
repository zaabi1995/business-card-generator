<?php
/** @var string $name */
/** @var string $companyName */
/** @var string $dashboardUrl */
/** @var string $cardUrl */
$subject = "Your Cardify setup is complete";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#00718c;margin-bottom:8px;">Your account is live, {$name}</h1>
  <p>{$companyName} is now set up on Cardify. Your first digital card is ready to share.</p>
  <p style="margin:20px 0;">
    <a href="{$cardUrl}" style="background:#009bc1;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">View your card</a>
    &nbsp;
    <a href="{$dashboardUrl}" style="background:#fff;color:#00718c;border:1px solid #009bc1;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Go to dashboard</a>
  </p>
  <ul style="color:#4b5563;font-size:14px;padding-left:20px;">
    <li>Invite the rest of your team from the Employees tab.</li>
    <li>Order printed cards whenever you are ready.</li>
    <li>Share your card link on WhatsApp, LinkedIn, or email.</li>
  </ul>
  <p style="color:#6b7280;font-size:13px;margin-top:24px;">Reply to this email if you need a hand, we read every message.</p>
  <p style="color:#6b7280;font-size:13px;">, The Cardify Team</p>
</div>
HTML;
