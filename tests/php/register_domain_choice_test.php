<?php
/**
 * Database-free contract for company signup when the email domain is already
 * claimed by another tenant.
 *
 * The behaviour this pins down replaced a silent hijack: a submission that said
 * "create Gauntlet QA Co at /gauntletqa" was turned into a pending employee row
 * inside an unrelated company, the typed name and slug were discarded, the
 * password hash was written into that other tenant, and the reply named the
 * company to an anonymous submitter. Verified live on 4 Sep 2026 against
 * cardify.om before the fix.
 *
 * Two rules are asserted here:
 *   1. Nothing is written until the person picks join or create.
 *   2. No response path names the other tenant to an unauthenticated visitor.
 */
$root = dirname(__DIR__, 2);
$reg   = file_get_contents($root . '/company/register.php');
$en    = require $root . '/lang/en/register.php';
$ar    = require $root . '/lang/ar/register.php';

$failures = 0;
function regCheck(bool $cond, string $label, string $detail = ''): void
{
    global $failures;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$cond && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$cond) $failures++;
}

regCheck(
    str_contains($reg, "\$domainChoice = \$_POST['domain_choice'] ?? '';")
        && str_contains($reg, "if (\$existingCompany && \$domainChoice !== 'join' && \$domainChoice !== 'create') {")
        && str_contains($reg, '$needsDomainChoice = true;'),
    'a claimed domain asks which outcome was meant instead of picking one'
);

regCheck(
    str_contains($reg, "if (\$existingCompany && !\$needsDomainChoice) {"),
    'the join branch cannot run while the question is still open'
);

regCheck(
    str_contains($reg, 'if (!$existingCompany && !$error && !$needsDomainChoice) {'),
    'the create-company branch cannot run while the question is still open'
);

regCheck(
    str_contains($reg, "} elseif (\$existingCompany && \$domainChoice === 'create') {")
        && str_contains($reg, '$existingCompany = null;'),
    'choosing "create" honours the typed company name and slug'
);

regCheck(
    str_contains($reg, 'data-domain-choice="join"')
        && str_contains($reg, 'data-domain-choice="create"'),
    'both outcomes are offered as explicit submits, neither is the default'
);

regCheck(
    str_contains($reg, '<input type="hidden" name="domain_choice" id="domain_choice" value="">')
        && !str_contains($reg, 'name="domain_choice" value='),
    'the answer rides in a hidden field, not a named submit button',
    'form.submit() in the reCAPTCHA path drops button name/value'
);

regCheck(
    str_contains($reg, "\$info = t('register.info_join_submitted');")
        && !str_contains($reg, "info_join_submitted', ['name'"),
    'the join confirmation does not interpolate the other tenant name'
);

regCheck(
    !str_contains($reg, "htmlspecialchars(\$existingCompany['name'])"),
    'no template path prints the other tenant name to an anonymous visitor'
);

foreach (['en' => $en, 'ar' => $ar] as $loc => $dict) {
    regCheck(
        !str_contains($dict['existing_company'], ':name')
            && !str_contains($dict['info_join_submitted'], ':name'),
        "{$loc} strings carry no :name placeholder for the other tenant"
    );
    regCheck(
        isset($dict['domain_choice_title'], $dict['domain_choice_body'],
              $dict['domain_choice_join'], $dict['domain_choice_create']),
        "{$loc} has the four domain-choice strings"
    );
}

regCheck(
    str_contains($reg, 'if (!empty($error) || $needsDomainChoice) {'),
    'asking the question refunds the per-IP signup slot'
);

$emDash = "\xE2\x80\x94";
regCheck(!str_contains($reg, $emDash), 'company/register.php contains no em dash');

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
