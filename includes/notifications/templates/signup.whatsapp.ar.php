<?php
/** @var string $name */
/** @var string $companyName */
/** @var string $dashboardUrl */
/** @var string $companySlug */
$cardPageUrl = getTenantUrl($companySlug ?? null);
$body = "مرحبًا {$name}، أهلاً بك في Cardify!\n\nشركتكم *{$companyName}* جاهزة.\n\nالخطوات التالية:\n1. أضف موظفيك\n2. صمم قالب البطاقة\n3. شارك البطاقات الرقمية فورًا\n\nلوحة التحكم: {$dashboardUrl}\nصفحة الشركة: {$cardPageUrl}\n\nكل الميزات مجانية للأبد — تدفع فقط عند طباعة البطاقات.";
