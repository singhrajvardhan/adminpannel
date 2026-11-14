<?php
// /raj/api/save-step.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$agentId = $_POST['agent_id'] ?? '';
if (!$agentId) {
    jsonResponse(['success' => false, 'message' => 'Agent ID required']);
}

$csrf = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf)) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token']);
}

$step = $_POST['step'] ?? '';
$data = json_decode($_POST['data'] ?? '{}', true);

if (empty($step) || empty($data)) {
    jsonResponse(['success' => false, 'message' => 'Invalid data']);
}

// Get existing onboarding data
$onboardingData = getOnboardingData($agentId) ?: [];

// Merge new data
$onboardingData = array_merge($onboardingData, $data);

// Save specific fields to main table
$fields = ['full_name', 'father_name', 'mother_name', 'mobile', 'email', 'current_address'];
foreach ($fields as $field) {
    if (isset($data[$field])) {
        saveAgentField($agentId, $field, $data[$field]);
    }
}

// Save onboarding data
if (saveOnboardingData($agentId, $onboardingData)) {
    addAuditLog($agentId, 'step_saved', "Step $step saved");
    jsonResponse(['success' => true, 'message' => 'Step saved']);
} else {
    jsonResponse(['success' => false, 'message' => 'Failed to save step']);
}
?>