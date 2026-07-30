<?php
/** @var string $name */
/** @var string $companyName */
/** @var string $dashboardUrl */
/** @var string $cardUrl */
$subject = "تم إعداد حسابك على Cardify";
$body = <<<HTML
<div dir="rtl" style="font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f2937;">
  <h1 style="color:#00718c;margin-bottom:8px;">حسابك أصبح مفعّلاً يا {$name}</h1>
  <p>تم إعداد {$companyName} على Cardify، وبطاقتك الرقمية الأولى جاهزة للمشاركة.</p>
  <p style="margin:20px 0;">
    <a href="{$cardUrl}" style="background:#009bc1;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">عرض بطاقتك</a>
    &nbsp;
    <a href="{$dashboardUrl}" style="background:#fff;color:#00718c;border:1px solid #009bc1;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">الانتقال إلى لوحة التحكم</a>
  </p>
  <ul style="color:#4b5563;font-size:14px;padding-right:20px;">
    <li>ادعُ بقية الفريق من تبويب الموظفين.</li>
    <li>اطلب بطاقات مطبوعة متى شئت.</li>
    <li>شارك رابط بطاقتك عبر واتساب أو لِنكد إن أو البريد.</li>
  </ul>
  <p style="color:#6b7280;font-size:13px;margin-top:24px;">أجب على هذا البريد إذا احتجت مساعدة، نقرأ كل رسالة.</p>
  <p style="color:#6b7280;font-size:13px;">، فريق Cardify</p>
</div>
HTML;
