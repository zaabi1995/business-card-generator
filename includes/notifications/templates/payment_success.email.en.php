<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $displayAmount */
/** @var string $omrAmount */
$subject = "Payment received for order {$orderNumber}";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#16a34a;margin-bottom:8px;">Payment received</h1>
  <p>Hi {$name},</p>
  <p>We have received your payment for order {$orderNumber}. Your cards are now in production.</p>
  <div style="background:#f0fdf4;border-radius:12px;padding:16px;margin:16px 0;border:1px solid #86efac;">
    <div style="font-size:14px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">Paid</div>
    <div style="font-size:28px;font-weight:800;color:#111827;">{$displayAmount}</div>
    <div style="font-size:14px;color:#6b7280;margin-top:4px;">{$omrAmount}</div>
  </div>
  <p>We will notify you when your order ships.</p>
  <p style="color:#6b7280;font-size:14px;">, The Cardify Team</p>
</div>
HTML;
