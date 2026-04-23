<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var int    $expiresInDays */
$firstName = trim(strtok(trim($employeeName), ' ')) ?: '';
$greeting = $firstName !== '' ? "مرحباً {$firstName}،" : "مرحباً،";
$body = "{$greeting} أعدّت {$companyName} لك بطاقة عمل رقمية على كارديفاي.\n\n"
      . "أكمل إعدادها خلال دقيقتين (أضف صورتك، رابط لينكدإن، وأي تفاصيل أخرى تريدها):\n"
      . "{$editUrl}\n\n"
      . "هذا الرابط الخاص ينتهي خلال {$expiresInDays} يوماً. إذا لم تتوقّع هذه الرسالة، تجاهلها.";
