<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $retryUrl */
$body = "مرحباً {$name}، لم تتمّ عملية الدفع للطلب {$orderNumber}.\n\nحاول مرة أخرى من هنا: {$retryUrl}\n\nردّ على هذه الرسالة إن احتجت مساعدة.";
