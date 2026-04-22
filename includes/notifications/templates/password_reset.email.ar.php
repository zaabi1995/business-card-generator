<?php
/** @var string $name */
/** @var string $resetUrl */
/** @var int    $expiresInMinutes */
$subject = "إعادة تعيين كلمة مرور كارديفاي";
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#1e40af;margin-bottom:8px;">إعادة تعيين كلمة المرور</h1>
  <p>مرحباً {$name}،</p>
  <p>اضغط الزر أدناه لإعادة تعيين كلمة مرور كارديفاي. الرابط صالح لمدة {$expiresInMinutes} دقيقة.</p>
  <p style="margin:24px 0;">
    <a href="{$resetUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">إعادة تعيين كلمة المرور</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">إن لم تطلب ذلك، يمكنك تجاهل هذه الرسالة.</p>
  <p style="color:#6b7280;font-size:14px;">— فريق كارديفاي</p>
</div>
HTML;
