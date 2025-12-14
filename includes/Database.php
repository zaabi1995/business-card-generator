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
        
        return $this->connection->lastInsertId();
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
    
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    public function exec($sql) {
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
                $sql = "SHOW TABLES LIKE :table";
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
    
    // Check if database is set up
    public function isSetup() {
        return $this->tableExists('companies') && 
               $this->tableExists('employees') && 
               $this->tableExists('templates');
    }
}
