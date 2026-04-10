<?php
/** @var string $name */
/** @var string $resetUrl */
/** @var int    $expiresInMinutes */
$subject = "Reset your Cardify password";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">Password reset</h1>
  <p>Hi {$name},</p>
  <p>Click the button below to reset your Cardify password. This link expires in {$expiresInMinutes} minutes.</p>
  <p style="margin:24px 0;">
    <a href="{$resetUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Reset password</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">If you did not request this, you can safely ignore this email.</p>
  <p style="color:#6b7280;font-size:14px;">— The Cardify Team</p>
</div>
HTML;
