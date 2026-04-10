<?php
/** @var string $name */
/** @var string $orderNumber */
/** @var string $displayAmount */
/** @var string $omrAmount */
$body = "Hi {$name}, we received your order {$orderNumber}.\n\nTotal: {$displayAmount}\n({$omrAmount})\n\nWe will confirm once payment clears.";
