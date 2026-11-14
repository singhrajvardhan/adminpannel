<?php
// /raj/api/save-location.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendJsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($data);
    exit;
}

// Get agent ID
$agentId = $_POST['agent_id'] ?? '';
if (empty($agentId) && isset($_SESSION['agent_id'])) {
    $agentId = $_SESSION['agent_id'];
}

if (empty($agentId)) {
    sendJsonResponse(['success' => false, 'message' => 'Agent ID required']);
}

$lat = $_POST['latitude'] ?? '';
$lng = $_POST['longitude'] ?? '';
$accuracy = $_POST['accuracy'] ?? '';

if (empty($lat) || empty($lng)) {
    sendJsonResponse(['success' => false, 'message' => 'Location data missing']);
}

// Validate coordinates
if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid coordinates']);
}

// Store as plain text (no encryption to avoid issues)
$conn = getDbConnection();
$sql = "UPDATE agents SET 
    latitude = ?, 
    longitude = ?, 
    accuracy = ?, 
    location_timestamp = NOW() 
    WHERE agent_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ssss', $lat, $lng, $accuracy, $agentId);

if ($stmt->execute()) {
    // Save to onboarding data
    $onboardingData = getOnboardingData($agentId) ?: [];
    $onboardingData['location_captured'] = true;
    $onboardingData['latitude'] = $lat;
    $onboardingData['longitude'] = $lng;
    $onboardingData['accuracy'] = $accuracy;
    saveOnboardingData($agentId, $onboardingData);
    
    addAuditLog($agentId, 'location_captured', "Location captured at $lat, $lng");
    
    sendJsonResponse(['success' => true, 'message' => 'Location saved successfully']);
} else {
    sendJsonResponse(['success' => false, 'message' => 'Failed to save location: ' . $stmt->error]);
}

$stmt->close();
?>