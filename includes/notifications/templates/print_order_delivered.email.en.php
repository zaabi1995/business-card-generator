<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $reorderUrl */
$reorderUrl = $reorderUrl ?? '';
$subject = "Order {$orderNumber} delivered";
$reorderBlock = '';
if (!empty($reorderUrl)) {
    $reorderBlock = <<<HTML
  <p style="margin:24px 0;">
    <a href="{$reorderUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">Reorder with one click</a>
  </p>
HTML;
}
$body = <<<HTML
<div style="font-family:system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#16a34a;margin-bottom:8px;">Delivered</h1>
  <p>Hi {$name},</p>
  <p>Your order <strong>{$orderNumber}</strong> has been delivered. Thanks for choosing Cardify.</p>
  {$reorderBlock}
  <p style="color:#6b7280;font-size:14px;">— The Cardify Team</p>
</div>
HTML;
