<?php
// /raj/api/verify-otp.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$agentId = $_POST['agent_id'] ?? '';
if (!$agentId) {
    jsonResponse(['success' => false, 'message' => 'Agent ID required']);
}

$otp = trim($_POST['otp'] ?? '');
if (empty($otp)) {
    jsonResponse(['success' => false, 'message' => 'OTP required']);
}

// Check OTP
$_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
if ($_SESSION['otp_attempts'] > 5) {
    jsonResponse(['success' => false, 'message' => 'Too many attempts. Request new OTP.']);
}

if (!isset($_SESSION['otp']) || time() > $_SESSION['otp_expiry']) {
    jsonResponse(['success' => false, 'message' => 'OTP expired or not generated.']);
}

if ($otp != $_SESSION['otp']) {
    jsonResponse(['success' => false, 'message' => 'Invalid OTP.']);
}

// Verify email
$email = $_SESSION['otp_email'];
saveAgentField($agentId, 'email', $email);
saveAgentField($agentId, 'email_verified', 1);
addAuditLog($agentId, 'email_verified', "Email $email verified via OTP");

// Save to onboarding data
$onboardingData = getOnboardingData($agentId) ?: [];
$onboardingData['email'] = $email;
$onboardingData['email_verified'] = true;
saveOnboardingData($agentId, $onboardingData);

// Clear OTP session
unset($_SESSION['otp'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts'], $_SESSION['otp_email']);

jsonResponse(['success' => true, 'message' => 'Email verified successfully']);
?>