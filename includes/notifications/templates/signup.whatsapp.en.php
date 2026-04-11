<?php
/** @var string $name */
/** @var string $companyName */
/** @var string $dashboardUrl */
/** @var string $companySlug */
$cardPageUrl = 'https://cardify.om/' . ($companySlug ?? '');
$body = "Welcome to Cardify, {$name}!\n\nYour company *{$companyName}* is set up and ready.\n\nNext steps:\n1. Add your employees\n2. Design your card template\n3. Share digital cards instantly\n\nYour dashboard: {$dashboardUrl}\nYour company page: {$cardPageUrl}\n\nEvery feature is free forever. You only pay when you print physical cards.";
