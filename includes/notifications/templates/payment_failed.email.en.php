<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $retryUrl */
$subject = "Payment issue with order {$orderNumber}";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#dc2626;margin-bottom:8px;">Payment did not go through</h1>
  <p>Hi {$name},</p>
  <p>We were not able to complete payment for order {$orderNumber}. This usually happens because of a card decline or an expired session.</p>
  <p style="margin:24px 0;">
    <a href="{$retryUrl}" style="background:#dc2626;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Try again</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">If the problem persists, reply to this email and we will help you out.</p>
  <p style="color:#6b7280;font-size:14px;">, The Cardify Team</p>
</div>
HTML;
