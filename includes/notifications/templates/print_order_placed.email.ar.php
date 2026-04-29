<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $displayAmount */
/** @var string $omrAmount */
/** @var int    $quantity */
$subject = "تمّ استلام الطلب {$orderNumber}";
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#1e40af;margin-bottom:8px;">تمّ استلام الطلب</h1>
  <p>مرحباً {$name}،</p>
  <p>استلمنا طلبك {$orderNumber} لعدد {$quantity} بطاقة عمل مطبوعة.</p>
  <div style="background:#f9fafb;border-radius:12px;padding:16px;margin:16px 0;">
    <div style="font-size:14px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">الإجمالي</div>
    <div style="font-size:28px;font-weight:800;color:#111827;">{$displayAmount}</div>
    <div style="font-size:14px;color:#6b7280;margin-top:4px;">{$omrAmount}</div>
  </div>
  <p>سنؤكّد بمجرد إتمام الدفع.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
