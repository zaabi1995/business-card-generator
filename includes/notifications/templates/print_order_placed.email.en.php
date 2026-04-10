<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $displayAmount */
/** @var string $omrAmount */
/** @var int    $quantity */
$subject = "Order {$orderNumber} received";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">Order received</h1>
  <p>Hi {$name},</p>
  <p>We have your order {$orderNumber} for {$quantity} printed business cards.</p>
  <div style="background:#f9fafb;border-radius:12px;padding:16px;margin:16px 0;">
    <div style="font-size:14px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">Total</div>
    <div style="font-size:28px;font-weight:800;color:#111827;">{$displayAmount}</div>
    <div style="font-size:14px;color:#6b7280;margin-top:4px;">{$omrAmount}</div>
  </div>
  <p>We will confirm once payment clears.</p>
  <p style="color:#6b7280;font-size:14px;">— The Cardify Team</p>
</div>
HTML;
