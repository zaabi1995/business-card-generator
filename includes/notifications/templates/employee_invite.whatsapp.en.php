<?php
/** @var string $employeeName */
/** @var string $companyName */
/** @var string $editUrl */
/** @var int    $expiresInDays */
$firstName = trim(strtok(trim($employeeName), ' ')) ?: 'there';
$body = "Hi {$firstName}, {$companyName} has set up a digital business card for you on Cardify.\n\n"
      . "Finish setting it up in 2 minutes (add your photo, LinkedIn, and anything else you want on it):\n"
      . "{$editUrl}\n\n"
      . "This private link expires in {$expiresInDays} days. If you did not expect this, ignore this message.";
