<?php
// /raj/admin/joining-letter-approve.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';
$agentId = $_POST['agent_id'] ?? $_GET['agent_id'] ?? '';

if (!empty($agentId)) {
    executeQuery("UPDATE joining_letters SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE agent_id = ?", [$adminId, $agentId]);
}

header('Location: joining-letter-setup.php?msg=approved');
exit;