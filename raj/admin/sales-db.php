<?php
/**
 * BGS Sales Portal - Database Configuration & Connection
 * Professional database handling with error management
 */

// Prevent direct access
if (!defined('ROOT_PATH')) {
    die('Direct access not permitted');
}

/**
 * Sales Database Configuration
 * Uses same credentials as main application
 */
class SalesDatabase {
    
    private static $instance = null;
    private $connection = null;
    private $connected = false;
    private $lastError = null;
    private $queryCount = 0;
    private $queries = [];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Connect to database using credentials from config
     */
    private function connect() {
        try {
            // Use credentials from main config
            $host = defined('DB_HOST') ? DB_HOST : 'sql310.ezyro.com';
            $user = defined('DB_USER') ? DB_USER : 'ezyro_42446564';
            $pass = defined('DB_PASS') ? DB_PASS : 'eccc0470dd1';
            $name = defined('DB_NAME') ? DB_NAME : 'ezyro_42446564_db';
            
            // Create connection
            $this->connection = new mysqli($host, $user, $pass, $name);
            
            // Check connection
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // Set charset
            $this->connection->set_charset("utf8mb4");
            
            // Set timezone
            $this->connection->query("SET time_zone = '+05:30'");
            
            $this->connected = true;
            $this->logQuery("CONNECT", "Database connected successfully");
            
        } catch (Exception $e) {
            $this->connected = false;
            $this->lastError = $e->getMessage();
            error_log("Sales Database Connection Error: " . $e->getMessage());
        }
    }
    
    /**
     * Get database connection
     */
    public function getConnection() {
        if (!$this->connected) {
            $this->connect();
        }
        return $this->connection;
    }
    
    /**
     * Check if connected
     */
    public function isConnected() {
        return $this->connected && $this->connection !== null;
    }
    
    /**
     * Execute a query with prepared statement
     */
    public function execute($sql, $params = [], $types = '') {
        if (!$this->isConnected()) {
            $this->lastError = "No database connection";
            return false;
        }
        
        try {
            $this->queryCount++;
            
            if (empty($params)) {
                // Simple query without parameters
                $result = $this->connection->query($sql);
                $this->logQuery($sql, "QUERY");
                return $result;
            }
            
            // Prepared statement with parameters
            $stmt = $this->connection->prepare($sql);
            if (!$stmt) {
                $this->lastError = "Prepare failed: " . $this->connection->error;
                $this->logQuery($sql, "ERROR", $this->lastError);
                return false;
            }
            
            // Bind parameters
            if (empty($types)) {
                // Auto-detect types
                $types = $this->getParamTypes($params);
            }
            
            $bindParams = array_merge([$types], $params);
            call_user_func_array([$stmt, 'bind_param'], $bindParams);
            
            // Execute
            $stmt->execute();
            
            // Get result
            $result = $stmt->get_result();
            
            // If result is false but no error, it might be an INSERT/UPDATE
            if ($result === false && $stmt->errno !== 0) {
                $this->lastError = $stmt->error;
                $this->logQuery($sql, "ERROR", $this->lastError);
                $stmt->close();
                return false;
            }
            
            $this->logQuery($sql, "PREPARED", $params);
            
            // For SELECT queries, return result set
            if ($result !== false) {
                $stmt->close();
                return $result;
            }
            
            // For INSERT/UPDATE/DELETE, return affected rows
            $affectedRows = $stmt->affected_rows;
            $insertId = $stmt->insert_id;
            $stmt->close();
            
            return ['affected_rows' => $affectedRows, 'insert_id' => $insertId];
            
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->logQuery($sql, "EXCEPTION", $e->getMessage());
            return false;
        }
    }
    
    /**
     * Auto-detect parameter types
     */
    private function getParamTypes($params) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param) || is_double($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        return $types;
    }
    
    /**
     * Fetch all rows as associative array
     */
    public function fetchAll($sql, $params = [], $types = '') {
        $result = $this->execute($sql, $params, $types);
        if ($result instanceof mysqli_result) {
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            return $rows;
        }
        return [];
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = [], $types = '') {
        $result = $this->execute($sql, $params, $types);
        if ($result instanceof mysqli_result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row;
        }
        return null;
    }
    
    /**
     * Fetch single value
     */
    public function fetchValue($sql, $params = [], $types = '') {
        $row = $this->fetchOne($sql, $params, $types);
        if ($row) {
            return reset($row);
        }
        return null;
    }
    
    /**
     * Insert data
     */
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $params = array_values($data);
        $types = $this->getParamTypes($params);
        
        $result = $this->execute($sql, $params, $types);
        
        if (is_array($result) && isset($result['insert_id'])) {
            return $result['insert_id'];
        }
        return false;
    }
    
    /**
     * Update data
     */
    public function update($table, $data, $where, $whereParams = [], $whereTypes = '') {
        $sets = [];
        $params = [];
        $types = '';
        
        foreach ($data as $field => $value) {
            $sets[] = "`{$field}` = ?";
            $params[] = $value;
            $types .= $this->getParamTypes([$value]);
        }
        
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE " . $where;
        
        // Merge parameters
        if (!empty($whereParams)) {
            $params = array_merge($params, $whereParams);
            $types .= $whereTypes;
        }
        
        $result = $this->execute($sql, $params, $types);
        
        if (is_array($result) && isset($result['affected_rows'])) {
            return $result['affected_rows'];
        }
        return false;
    }
    
    /**
     * Delete data
     */
    public function delete($table, $where, $params = [], $types = '') {
        $sql = "DELETE FROM `{$table}` WHERE " . $where;
        $result = $this->execute($sql, $params, $types);
        
        if (is_array($result) && isset($result['affected_rows'])) {
            return $result['affected_rows'];
        }
        return false;
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->insert_id;
    }
    
    /**
     * Get affected rows
     */
    public function affectedRows() {
        return $this->connection->affected_rows;
    }
    
    /**
     * Get last error
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Get query count
     */
    public function getQueryCount() {
        return $this->queryCount;
    }
    
    /**
     * Log query for debugging
     */
    private function logQuery($sql, $type = 'QUERY', $data = null) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $this->queries[] = [
                'type' => $type,
                'sql' => $sql,
                'data' => $data,
                'time' => microtime(true)
            ];
            
            // Keep last 100 queries
            if (count($this->queries) > 100) {
                array_shift($this->queries);
            }
        }
    }
    
    /**
     * Get query log
     */
    public function getQueryLog() {
        return $this->queries;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->begin_transaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollback();
    }
    
    /**
     * Escape string
     */
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    /**
     * Close connection
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
            $this->connected = false;
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}

/**
 * Helper functions for easy access
 */

/**
 * Get Sales Database instance
 */
function getSalesDB() {
    return SalesDatabase::getInstance();
}

/**
 * Execute query
 */
function salesQuery($sql, $params = [], $types = '') {
    $db = getSalesDB();
    return $db->execute($sql, $params, $types);
}

/**
 * Fetch all rows
 */
function salesFetchAll($sql, $params = [], $types = '') {
    $db = getSalesDB();
    return $db->fetchAll($sql, $params, $types);
}

/**
 * Fetch one row
 */
function salesFetchOne($sql, $params = [], $types = '') {
    $db = getSalesDB();
    return $db->fetchOne($sql, $params, $types);
}

/**
 * Fetch single value
 */
function salesFetchValue($sql, $params = [], $types = '') {
    $db = getSalesDB();
    return $db->fetchValue($sql, $params, $types);
}

/**
 * Insert data
 */
function salesInsert($table, $data) {
    $db = getSalesDB();
    return $db->insert($table, $data);
}

/**
 * Update data
 */
function salesUpdate($table, $data, $where, $whereParams = [], $whereTypes = '') {
    $db = getSalesDB();
    return $db->update($table, $data, $where, $whereParams, $whereTypes);
}

/**
 * Delete data
 */
function salesDelete($table, $where, $params = [], $types = '') {
    $db = getSalesDB();
    return $db->delete($table, $where, $params, $types);
}

/**
 * Check if sales tables exist and create if needed
 */
function initializeSalesTables() {
    $db = getSalesDB();
    
    // Check if sale_types table exists
    $result = $db->execute("SHOW TABLES LIKE 'sale_types'");
    if ($result && $result->num_rows === 0) {
        // Tables don't exist, create them
        $schemaFile = dirname(__DIR__) . '/storage/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query) && strpos($query, 'CREATE') !== false) {
                    $db->execute($query);
                }
            }
            return true;
        }
    }
    return true;
}

// Initialize database connection
$salesDB = getSalesDB();

// Check if connected
if (!$salesDB->isConnected()) {
    error_log("Sales Database: Connection failed - " . $salesDB->getLastError());
}