<?php
// /raj/api/send-otp.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$agentId = $_POST['agent_id'] ?? '';
if (!$agentId) {
    jsonResponse(['success' => false, 'message' => 'Agent ID required']);
}

$agent = getAgent($agentId);
if (!$agent) {
    jsonResponse(['success' => false, 'message' => 'Agent not found']);
}

$email = trim($_POST['email'] ?? '');
if (!validateEmail($email)) {
    jsonResponse(['success' => false, 'message' => 'Invalid email address']);
}

// Generate OTP
$otp = random_int(100000, 999999);
$_SESSION['otp'] = $otp;
$_SESSION['otp_expiry'] = time() + 300;
$_SESSION['otp_attempts'] = 0;
$_SESSION['otp_email'] = $email;
$_SESSION['otp_agent'] = $agentId;

// Debug: Log OTP
error_log("OTP generated for $agentId: $otp");

// Send email
$sent = sendOtpEmail($email, $otp);

if ($sent) {
    addAuditLog($agentId, 'otp_sent', "OTP sent to $email");
    jsonResponse(['success' => true, 'message' => 'OTP sent to your email']);
} else {
    // For debugging, return the OTP in development (remove in production)
    if (DEBUG_MODE) {
        jsonResponse(['success' => true, 'message' => 'OTP sent (debug mode)', 'otp' => $otp]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to send OTP. Please check email configuration.']);
    }
}
?>