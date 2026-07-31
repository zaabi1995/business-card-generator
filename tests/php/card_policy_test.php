<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function policyCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$policyPath = $root . '/includes/CardPolicy.php';
policyCheck('Card policy exists', is_file($policyPath));
if (is_file($policyPath)) {
    require_once $policyPath;
    policyCheck(
        'managed employee design locked',
        CardPolicy::forState(true, 'member') === [
            'mode' => 'managed_company',
            'can_edit_text' => true,
            'can_edit_design' => false,
            'can_choose_design' => false,
        ]
    );
    $personal = CardPolicy::forState(false, 'owner');
    policyCheck(
        'personal owner full access',
        $personal['mode'] === 'personal'
            && $personal['can_edit_text'] === true
            && $personal['can_edit_design'] === true
            && $personal['can_choose_design'] === true
    );
    policyCheck(
        'managed owner design locked',
        CardPolicy::forState(true, 'owner') === [
            'mode' => 'managed_company',
            'can_edit_text' => true,
            'can_edit_design' => false,
            'can_choose_design' => false,
        ]
    );
    policyCheck(
        'linked super admin design locked',
        CardPolicy::forState(true, 'member', true) === [
            'mode' => 'managed_company',
            'can_edit_text' => true,
            'can_edit_design' => false,
            'can_choose_design' => false,
        ]
    );
    policyCheck(
        'unmanaged company member design locked',
        CardPolicy::forState(false, 'member') === [
            'mode' => 'unmanaged_company',
            'can_edit_text' => true,
            'can_edit_design' => false,
            'can_choose_design' => false,
        ]
    );
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
