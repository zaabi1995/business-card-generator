<?php
/**
 * Database Connection and Query Helper
 * Supports MySQL/MariaDB and PostgreSQL
 */
class Database {
    private static $instance = null;
    private $connection = null;
    private $dbType = 'mysql';
    
    private function __construct() {
        // Private constructor for singleton
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function connect($host, $database, $username, $password, $port = null, $type = 'mysql') {
        $this->dbType = $type;
        $port = $port ?: ($type === 'pgsql' ? 5432 : 3306);
        
        try {
            if ($type === 'pgsql') {
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            if ($type === 'mysql') {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
            }
            
            $this->connection = new PDO($dsn, $username, $password, $options);
            
            return true;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function isConnected() {
        return $this->connection !== null;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query error: " . $e->getMessage() . " | SQL: " . $sql);
            throw $e;
        }
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $fieldsList = implode(', ', $fields);
        
        $sql = "INSERT INTO {$table} ({$fieldsList}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        $id = $this->connection->lastInsertId();
        $this->notePublishedCountMoved($table);

        return $id;
    }

    public function update($table, $data, $where, $whereParams = []) {
        $setParts = [];
        foreach (array_keys($data) as $field) {
            $setParts[] = "{$field} = :{$field}";
        }
        $setClause = implode(', ', $setParts);
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $params = array_merge($data, $whereParams);
        
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * llm71-2: cardify.om publishes COUNT(*) of six tables as exact, undated
     * numbers on six public pages. Those counts are cached, so between a write
     * and the cache expiring the site states a number that is measurably wrong,
     * and numeric_gate correctly calls it a defect.
     *
     * The invalidation lives HERE, on the one method every write goes through,
     * and not on the four separate `insert('generated_cards', ...)` call sites:
     * a rule attached to some of its call sites and not the rest is the shape
     * that regresses the moment a fifth one is written.
     *
     * Hooked on insert() and delete() only, because those are what move
     * COUNT(*). An UPDATE can move `issuing` too, by renaming a company into or
     * out of PlatformStats::NOT_A_CUSTOMER; that is left to the 5-minute TTL
     * deliberately, rather than busting the snapshot on every unrelated column
     * write.
     *
     * Never fatal. A cache that failed to clear is a stale number; an exception
     * thrown out of insert() would be a lost card.
     */
    private function notePublishedCountMoved($table) {
        try {
            if (!class_exists('PlatformStats')) {
                $f = __DIR__ . '/PlatformStats.php';
                if (!is_file($f)) return;
                require_once $f;
            }
            if (PlatformStats::publishes((string) $table)) {
                PlatformStats::invalidate();
            }
        } catch (Throwable $e) {
            error_log('PlatformStats invalidation skipped: ' . $e->getMessage());
        }
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        $rows = $stmt->rowCount();
        if ($rows > 0) $this->notePublishedCountMoved($table);
        return $rows;
    }
    
    public function beginTransaction() {
        if (!$this->connection) {
            throw new Exception('Database connection not established');
        }
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        if (!$this->connection) {
            throw new Exception('Database connection not established');
        }
        return $this->connection->commit();
    }
    
    public function rollback() {
        if (!$this->connection) {
            throw new Exception('Database connection not established');
        }
        return $this->connection->rollBack();
    }
    
    public function exec($sql) {
        if (!$this->connection) {
            throw new Exception('Database connection not established');
        }
        return $this->connection->exec($sql);
    }
    
    public function getDbType() {
        return $this->dbType;
    }
    
    // Check if table exists
    public function tableExists($tableName) {
        try {
            if ($this->dbType === 'pgsql') {
                $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = :table)";
            } else {
                $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table";
            }
            $result = $this->fetchOne($sql, ['table' => $tableName]);
            
            if ($this->dbType === 'pgsql') {
                return !empty($result) && array_values($result)[0] === true;
            } else {
                return !empty($result);
            }
        } catch (PDOException $e) {
            return false;
        }
    }

    // Check if column exists
    public function columnExists($tableName, $columnName) {
        try {
            if ($this->dbType === 'pgsql') {
                $sql = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_name = :table AND column_name = :column)";
                $result = $this->fetchOne($sql, ['table' => $tableName, 'column' => $columnName]);
                if (empty($result)) {
                    return false;
                }
                $value = array_values($result)[0];
                return $value === true || $value === 't' || $value === 'true' || $value == 1;
            }

            $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column";
            $result = $this->fetchOne($sql, ['table' => $tableName, 'column' => $columnName]);
            return !empty($result);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // Check if database is set up
    public function isSetup() {
        return $this->tableExists('companies') && 
               $this->tableExists('employees') && 
               $this->tableExists('templates');
    }
}
