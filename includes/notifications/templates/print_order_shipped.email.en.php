<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $trackingUrl */
/** @var string $carrier */
$trackingUrl = $trackingUrl ?? '';
$carrier = $carrier ?? 'the carrier';
$subject = "Order {$orderNumber} has shipped";
$trackingBlock = '';
if (!empty($trackingUrl)) {
    $trackingBlock = <<<HTML
  <p style="margin:24px 0;">
    <a href="{$trackingUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Track your shipment</a>
  </p>
HTML;
}
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">Your order is on its way</h1>
  <p>Hi {$name},</p>
  <p>Your order <strong>{$orderNumber}</strong> has shipped with {$carrier}.</p>
  {$trackingBlock}
  <p style="color:#6b7280;font-size:14px;">— The Cardify Team</p>
</div>
HTML;
