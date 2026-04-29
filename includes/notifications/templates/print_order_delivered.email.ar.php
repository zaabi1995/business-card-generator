<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $reorderUrl */
$reorderUrl = $reorderUrl ?? '';
$subject = "تمّ تسليم الطلب {$orderNumber}";
$reorderBlock = '';
if (!empty($reorderUrl)) {
    $reorderBlock = <<<HTML
  <p style="margin:24px 0;">
    <a href="{$reorderUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">أعد الطلب بنقرة واحدة</a>
  </p>
HTML;
}
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#16a34a;margin-bottom:8px;">تمّ التسليم</h1>
  <p>مرحباً {$name}،</p>
  <p>تمّ تسليم طلبك <strong>{$orderNumber}</strong>. شكراً لاختيارك كارديفاي.</p>
  {$reorderBlock}
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
