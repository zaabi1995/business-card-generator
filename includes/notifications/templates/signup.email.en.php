<?php
/** @var string $name */
/** @var string $loginUrl */
$subject = 'Welcome to Cardify';
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">Welcome to Cardify</h1>
  <p>Hi {$name},</p>
  <p>Your Cardify account is ready. Every feature is free forever, you only pay when you print physical cards.</p>
  <p style="margin:24px 0;">
    <a href="{$loginUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Start designing your first card</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">If you have any questions, just reply to this email.</p>
  <p style="color:#6b7280;font-size:14px;">, The Cardify Team</p>
</div>
HTML;
