<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $retryUrl */
$body = "Hi {$name}, payment did not go through for order {$orderNumber}.\n\nRetry here: {$retryUrl}\n\nReply to this message if you need help.";
