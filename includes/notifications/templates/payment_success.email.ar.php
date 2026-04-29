<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $displayAmount */
/** @var string $omrAmount */
$subject = "تمّ استلام الدفع للطلب {$orderNumber}";
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#16a34a;margin-bottom:8px;">تمّ استلام الدفع</h1>
  <p>مرحباً {$name}،</p>
  <p>استلمنا الدفع للطلب {$orderNumber}. بطاقاتك الآن قيد الإنتاج.</p>
  <div style="background:#f0fdf4;border-radius:12px;padding:16px;margin:16px 0;border:1px solid #86efac;">
    <div style="font-size:14px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;">المبلغ المدفوع</div>
    <div style="font-size:28px;font-weight:800;color:#111827;">{$displayAmount}</div>
    <div style="font-size:14px;color:#6b7280;margin-top:4px;">{$omrAmount}</div>
  </div>
  <p>سنُعلِمك عند شحن الطلب.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
