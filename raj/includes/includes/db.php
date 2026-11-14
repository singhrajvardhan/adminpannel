<?php
// /raj/includes/db.php
require_once __DIR__ . '/config.php';

function getDbConnection() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die("Database connection failed: " . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function executeQuery($sql, $params = [], $types = '') {
    $conn = getDbConnection();
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $conn->error . " SQL: " . $sql);
        return false;
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat('s', count($params));
        }
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    
    // For SELECT queries, get the result set
    if (strpos(strtoupper(trim($sql)), 'SELECT') === 0) {
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }
    
    // For INSERT, UPDATE, DELETE queries, return true on success
    $success = ($stmt->affected_rows >= 0 || $stmt->insert_id > 0);
    $stmt->close();
    return $success;
}
?>