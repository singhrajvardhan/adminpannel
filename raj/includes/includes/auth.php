<?php
// /raj/includes/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isAdminLoggedIn() { 
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true; 
}

function requireAdminAuth() { 
    if (!isAdminLoggedIn()) { 
        header('Location: /raj/admin/login.php'); 
        exit; 
    } 
}

function isAgentLoggedIn() { 
    return isset($_SESSION['agent_logged_in']) && $_SESSION['agent_logged_in'] === true && isset($_SESSION['agent_id']); 
}

function requireAgentAuth() { 
    if (!isAgentLoggedIn()) { 
        header('Location: /raj/agent/login.php'); 
        exit; 
    } 
}

function adminLogin($username, $password) {
    $result = executeQuery("SELECT password_hash FROM admins WHERE username = ?", [$username]);
    $row = $result->fetch_assoc();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function agentLogin($agentId, $dob) {
    $agent = getAgent($agentId);
    if (!$agent || $agent['account_status'] !== 'active') return false;
    $decryptedDob = decryptData($agent['date_of_birth']);
    if ($decryptedDob !== $dob) return false;
    $_SESSION['agent_logged_in'] = true;
    $_SESSION['agent_id'] = $agentId;
    return true;
}

function getCurrentAgentId() {
    return $_SESSION['agent_id'] ?? null;
}

function isOnboardingCompleted($agentId) {
    $agent = getAgent($agentId);
    return $agent && $agent['onboarding_completed'] == 1;
}
?>