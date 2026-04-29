<?php
/** @var string $name */
/** @var string $orderNumber */
$subject = "الطلب {$orderNumber} قيد الإنتاج";
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#1e40af;margin-bottom:8px;">قيد الإنتاج</h1>
  <p>مرحباً {$name}،</p>
  <p>طلبك <strong>{$orderNumber}</strong> قيد الطباعة الآن. سنُعلِمك فور شحنه.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
