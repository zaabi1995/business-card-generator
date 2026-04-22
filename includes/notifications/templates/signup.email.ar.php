<?php
/** @var string $name */
/** @var string $loginUrl */
$subject = 'أهلاً بك في كارديفاي';
$body = <<<HTML
<div style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;" dir="rtl">
  <h1 style="color:#1e40af;margin-bottom:8px;">أهلاً بك في كارديفاي</h1>
  <p>مرحباً {$name}،</p>
  <p>حسابك في كارديفاي جاهز. جميع الميزات مجانية للأبد، أنت تدفع فقط عند طباعة البطاقات الفعلية.</p>
  <p style="margin:24px 0;">
    <a href="{$loginUrl}" style="background:#1e40af;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">ابدأ تصميم بطاقتك الأولى</a>
  </p>
  <p style="color:#6b7280;font-size:14px;">إن كان لديك أي سؤال، ردّ على هذه الرسالة.</p>
  <p style="color:#6b7280;font-size:14px;">— فريق كارديفاي</p>
</div>
HTML;
