<?php
/** @var string $code */
/** @var int    $expiresInMinutes */
$subject = "رمز التحقق الخاص بك في كارديفاي";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#1e40af;margin-bottom:8px;">رمز التحقق</h1>
  <p>استخدم الرمز أدناه لإكمال تسجيل الدخول إلى كارديفاي.</p>
  <div dir="ltr" style="font-size:32px;font-weight:700;letter-spacing:6px;background:#f1f5f9;padding:16px;text-align:center;border-radius:8px;margin:24px 0;">{$code}</div>
  <p style="color:#6b7280;font-size:14px;">ينتهي هذا الرمز خلال {$expiresInMinutes} دقيقة.</p>
  <p style="color:#6b7280;font-size:14px;">إذا لم تطلب هذا الرمز، يمكنك تجاهل هذه الرسالة دون الحاجة لأي إجراء.</p>
  <p style="color:#6b7280;font-size:14px;">, فريق كارديفاي</p>
</div>
HTML;
