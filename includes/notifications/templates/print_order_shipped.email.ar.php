<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $trackingUrl */
/** @var string $carrier */
$trackingUrl = $trackingUrl ?? '';
$carrier = $carrier ?? 'شركة الشحن';
$subject = "تمّ شحن الطلب {$orderNumber}";
$trackingBlock = '';
if (!empty($trackingUrl)) {
    $trackingBlock = <<<HTML
  <p style="margin:24px 0;">
    <a href="{$trackingUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">تتبّع الشحنة</a>
  </p>
HTML;
}
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#1e40af;margin-bottom:8px;">طلبك في طريقه إليك</h1>
  <p>مرحباً {$name}،</p>
  <p>تمّ شحن طلبك <strong>{$orderNumber}</strong> مع {$carrier}.</p>
  {$trackingBlock}
  <p style="color:#6b7280;font-size:14px;">— فريق كارديفاي</p>
</div>
HTML;
