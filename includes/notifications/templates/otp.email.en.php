<?php
/** @var string $code */
/** @var int    $expiresInMinutes */
$subject = "Your Cardify verification code";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">Your verification code</h1>
  <p>Use the one-time code below to finish signing in to Cardify.</p>
  <div style="font-size:32px;font-weight:700;letter-spacing:6px;background:#f1f5f9;padding:16px;text-align:center;border-radius:8px;margin:24px 0;">{$code}</div>
  <p style="color:#6b7280;font-size:14px;">This code expires in {$expiresInMinutes} minutes.</p>
  <p style="color:#6b7280;font-size:14px;">If you did not request this code, you can safely ignore this email, no action is needed.</p>
  <p style="color:#6b7280;font-size:14px;">, The Cardify Team</p>
</div>
HTML;
