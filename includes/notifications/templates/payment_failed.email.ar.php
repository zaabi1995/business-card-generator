<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $retryUrl */
$subject = "مشكلة في الدفع للطلب {$orderNumber}";
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#dc2626;margin-bottom:8px;">لم تتمّ عملية الدفع</h1>
  <p>مرحباً {$name}،</p>
  <p>لم نتمكّن من إتمام الدفع للطلب {$orderNumber}. يحدث هذا عادةً بسبب رفض البطاقة أو انتهاء الجلسة.</p>
  <p style="margin:24px 0;">
    <a href="{$retryUrl}" style="background:#dc2626;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">حاول مرة أخرى</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">إن استمرّت المشكلة، ردّ على هذه الرسالة وسنساعدك.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
