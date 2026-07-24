<?php
function getCurrentCompanyId()
{
    return null;
}

function generateUUID(): string
{
    return 'unused-test-uuid';
}

require_once __DIR__ . '/../../includes/CardifyConvention.php';
require_once __DIR__ . '/../../includes/DatabaseAdapter.php';

final class EmployeeRaceDb
{
    public array $inserted = [];
    private array $occupied = [];
    private bool $firstInsert = true;

    public function isConnected(): bool
    {
        return true;
    }

    public function isSetup(): bool
    {
        return true;
    }

    public function columnExists(string $table, string $column): bool
    {
        return false;
    }

    public function fetchOne(string $sql, array $params = [])
    {
        if (strpos($sql, 'LOWER(email)') !== false) {
            return false;
        }
        if (strpos($sql, 'FROM employees WHERE id = :i') !== false) {
            return isset($this->occupied[$params['i']])
                ? ['id' => $params['i']]
                : false;
        }
        return false;
    }

    public function insert(string $table, array $row)
    {
        if ($table !== 'employees') {
            throw new RuntimeException('unexpected table');
        }
        if ($this->firstInsert) {
            $this->firstInsert = false;
            $this->occupied[$row['id']] = true;
            $error = new PDOException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '"
                . $row['id']
                . "' for key 'PRIMARY'"
            );
            $error->errorInfo = ['23000', 1062, "Duplicate entry for key 'PRIMARY'"];
            throw $error;
        }
        $this->occupied[$row['id']] = true;
        $this->inserted[] = $row;
        return $row['id'];
    }
}

$db = new EmployeeRaceDb();
$dbProperty = new ReflectionProperty(DatabaseAdapter::class, 'db');
$dbProperty->setValue(null, $db);
$enabledProperty = new ReflectionProperty(DatabaseAdapter::class, 'useDatabase');
$enabledProperty->setValue(null, true);

$result = DatabaseAdapter::addEmployee([
    'email' => 'ali@example.com',
    'name_en' => 'Ali',
    'skip_invite' => true,
], 'company-b');

$ok = !empty($result['success'])
    && ($result['id'] ?? null) === 'ali2'
    && count($db->inserted) === 1
    && ($db->inserted[0]['id'] ?? null) === 'ali2';

echo ($ok ? 'PASS' : 'FAIL') . ": primary-key race is retried with a new global ID\n";
exit($ok ? 0 : 1);
