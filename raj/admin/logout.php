<?php
// /raj/admin/logout.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Update logout time if login log exists
$loginLogId = $_SESSION['admin_login_log_id'] ?? null;
if ($loginLogId) {
    // Calculate duration
    $loginTimeQuery = executeQuery("SELECT login_time FROM admin_login_logs WHERE id = ?", [$loginLogId]);
    if ($loginTimeQuery && $loginTimeQuery->num_rows > 0) {
        $loginTime = strtotime($loginTimeQuery->fetch_assoc()['login_time']);
        $duration = time() - $loginTime;
        
        executeQuery(
            "UPDATE admin_login_logs SET logout_time = NOW(), session_duration = ?, status = 'logged_out' WHERE id = ?",
            [$duration, $loginLogId]
        );
    }
}

// Clear session
session_unset();
session_destroy();

header('Location: login.php?logout=success');
exit;
?>