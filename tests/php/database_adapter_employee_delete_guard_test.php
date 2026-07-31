<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/DatabaseAdapter.php';

final class EmployeeDeleteGuardDb
{
    public int $deleteCount = 0;
    public bool $accountBound = true;

    public function isConnected(): bool
    {
        return true;
    }

    public function isSetup(): bool
    {
        return true;
    }

    public function fetchOne(string $sql, array $params = [])
    {
        if (strpos($sql, 'FROM scan_account_memberships') === false) {
            throw new RuntimeException('Unexpected query');
        }
        if (($params['employee_id'] ?? null) !== 'employee-a') {
            throw new RuntimeException('Employee scope was not preserved');
        }
        return $this->accountBound ? ['account_id' => 'account-a'] : false;
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        if (
            $table !== 'employees'
            || $where !== 'id = :id AND company_id = :cid'
            || $params !== ['id' => 'employee-a', 'cid' => 'company-a']
        ) {
            throw new RuntimeException('Unexpected delete');
        }
        $this->deleteCount++;
        return 1;
    }
}

$db = new EmployeeDeleteGuardDb();
$dbProperty = new ReflectionProperty(DatabaseAdapter::class, 'db');
$dbProperty->setValue(null, $db);
$enabledProperty = new ReflectionProperty(DatabaseAdapter::class, 'useDatabase');
$enabledProperty->setValue(null, true);

$bound = DatabaseAdapter::deleteEmployee('employee-a', 'company-a');
$boundProtected = empty($bound['success'])
    && ($bound['error'] ?? null) === 'native_account_linked'
    && $db->deleteCount === 0;

$db->accountBound = false;
$unbound = DatabaseAdapter::deleteEmployee('employee-a', 'company-a');
$unboundDeleted = !empty($unbound['success']) && $db->deleteCount === 1;

$ok = $boundProtected && $unboundDeleted;
echo ($ok ? 'PASS' : 'FAIL')
    . ": native account memberships cannot be orphaned by employee deletion\n";
exit($ok ? 0 : 1);
