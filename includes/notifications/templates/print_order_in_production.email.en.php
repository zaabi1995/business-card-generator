<?php
/** @var string $name */
/** @var string $orderNumber */
$subject = "Order {$orderNumber} is in production";
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">In production</h1>
  <p>Hi {$name},</p>
  <p>Your order <strong>{$orderNumber}</strong> is now being printed. We will notify you the moment it ships.</p>
  <p style="color:#6b7280;font-size:14px;">, The Cardify Team</p>
</div>
HTML;
