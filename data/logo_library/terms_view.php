<?php /** @var bool $isAr; @var string $title; */ ?>
<!DOCTYPE html>
<html lang="<?= $isAr ? 'ar' : 'en' ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($title) ?></title>
<link rel="canonical" href="https://cardify.om/logos/terms">
<meta name="robots" content="index,follow">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/../../includes/partials/nav.php'; ?>
<main class="max-w-3xl mx-auto px-4 py-10 prose prose-slate">
  <h1><?= esc($title) ?></h1>

  <?php if ($isAr): ?>
    <p>جميع الشعارات المعروضة في مكتبة الشعارات العمانية هي علامات تجارية لأصحابها. تقوم Cardify بفهرستها لأغراض التعريف والبحث ولا تطالب بأي ملكية.</p>
    <h2>الاستخدام المرجعي</h2>
    <p>يُسمح بعرض الشعارات لأغراض التعريف فقط. لا يعني وجود شعار في المكتبة أي ترخيص لإعادة الاستخدام التجاري.</p>
    <h2>الشعارات الموثّقة</h2>
    <p>عندما يقوم صاحب العلامة التجارية بتوثيق شعاره عبر عملية المطالبة، يصبح الشعار متاحاً للتنزيل بموافقة المالك.</p>
    <h2>طلبات الإزالة</h2>
    <p>يمكن لأصحاب العلامات التجارية تقديم طلب إزالة عبر <a href="/logo-takedown">نموذج الإزالة</a>. نستجيب خلال 48 ساعة ونقوم بإخفاء الشعار خلال 24 ساعة من التحقق الأولي.</p>
    <h2>الإسناد</h2>
    <p>تم إعداد هذه المكتبة جزئياً من مصادر عامة مفهرسة بما في ذلك 2oman.net، بالإضافة إلى مواقع الشركات.</p>
  <?php else: ?>
    <p>All logos shown in the Omani Logo Library are trademarks of their respective owners. Cardify indexes them for identification and research purposes and claims no ownership.</p>
    <h2>Reference use</h2>
    <p>Logos are displayed for identification only. Presence in the library does not imply any license for commercial reuse.</p>
    <h2>Verified logos</h2>
    <p>When a brand owner verifies their logo via the claim process, the logo becomes downloadable with the owner's consent.</p>
    <h2>Takedown</h2>
    <p>Brand owners may request removal via the <a href="/logo-takedown">takedown form</a>. We acknowledge within 48 hours and hide logos within 24 hours of prima-facie validation.</p>
    <h2>Attribution</h2>
    <p>This library was seeded in part from publicly indexed sources including 2oman.net, with additional sourcing from company websites.</p>
    <h2>Contact</h2>
    <p>Questions? Email <a href="mailto:contact@cardify.om">contact@cardify.om</a>.</p>
  <?php endif; ?>
</main>
</body>
</html>
