<?php
// /raj/api/submit-onboarding.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/csrf.php';

ob_clean();

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

try {
    // Check if we have POST data
    if (empty($_POST)) {
        sendJsonResponse(['success' => false, 'message' => 'No POST data received']);
    }

    // Get agent ID
    $agentId = $_POST['agent_id'] ?? '';
    if (empty($agentId)) {
        if (isset($_SESSION['agent_id'])) {
            $agentId = $_SESSION['agent_id'];
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Agent ID required. Please login again.']);
        }
    }

    // Verify agent exists
    $agent = getAgent($agentId);
    if (!$agent) {
        sendJsonResponse(['success' => false, 'message' => 'Agent not found. Please login again.']);
    }

    // CSRF validation
    $csrf = $_POST['csrf_token'] ?? '';
    if (!empty($csrf) && !validateCsrfToken($csrf)) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    }

    // Get data
    $data = json_decode($_POST['data'] ?? '{}', true);
    if (empty($data)) {
        sendJsonResponse(['success' => false, 'message' => 'No data received. Please fill all fields.']);
    }

    // Validate required fields
    $required = ['full_name', 'father_name', 'mother_name', 'mobile', 'email'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        sendJsonResponse(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
    }

    // Check email verified
    if (empty($data['email_verified']) && empty($agent['email_verified'])) {
        sendJsonResponse(['success' => false, 'message' => 'Email must be verified before submission']);
    }

    // Validate mobile format
    $mobile = $data['mobile'] ?? $agent['mobile'] ?? '';
    if (!empty($mobile) && !preg_match('/^[6-9]\d{9}$/', $mobile)) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid mobile number format']);
    }

    // Encrypt sensitive data
    $panNumber = $data['pan_number'] ?? '';
    if (!empty($panNumber)) {
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($panNumber))) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid PAN format']);
        }
        $encryptedPan = encryptData(strtoupper($panNumber));
        saveAgentField($agentId, 'pan_number', $encryptedPan);
    }

    $aadhaarNumber = $data['aadhaar_number'] ?? '';
    if (!empty($aadhaarNumber)) {
        if (!preg_match('/^\d{12}$/', $aadhaarNumber)) {
            sendJsonResponse(['success' => false, 'message' => 'Aadhaar number must be 12 digits']);
        }
        $encryptedAadhaar = encryptData($aadhaarNumber);
        saveAgentField($agentId, 'aadhaar_number', $encryptedAadhaar);
    }

    // Get all columns from the table to know what to update
    $conn = getDbConnection();
    $columnsResult = $conn->query("SHOW COLUMNS FROM agents");
    $existingColumns = [];
    while ($col = $columnsResult->fetch_assoc()) {
        $existingColumns[] = $col['Field'];
    }

    // Build update query with only existing columns
    $updateFields = [];
    $updateValues = [];
    $types = '';

    $fieldMappings = [
        'full_name' => 'full_name',
        'father_name' => 'father_name',
        'mother_name' => 'mother_name',
        'mobile' => 'mobile',
        'email' => 'email',
        'email_verified' => 'email_verified',
        'current_address' => 'current_address',
        'house_number' => 'house_number',
        'street' => 'street',
        'locality' => 'locality',
        'landmark' => 'landmark',
        'city' => 'city',
        'state' => 'state',
        'pincode' => 'pincode',
        'country' => 'country',
        'resume_path' => 'resume_path',
        'pan_image_path' => 'pan_image_path',
        'aadhaar_front_path' => 'aadhaar_front_path',
        'aadhaar_back_path' => 'aadhaar_back_path',
        'candidate_photo_path' => 'candidate_photo_path',
        'house_photo_1' => 'house_photo_1',
        'house_photo_2' => 'house_photo_2',
        'street_photo' => 'street_photo'
    ];

    foreach ($fieldMappings as $dataKey => $dbField) {
        if (in_array($dbField, $existingColumns)) {
            $value = $data[$dataKey] ?? '';
            if (!empty($value) || $dataKey === 'email_verified') {
                $updateFields[] = "`$dbField` = ?";
                $updateValues[] = $value;
                $types .= 's';
            }
        }
    }

    // Add location fields if they exist
    if (in_array('latitude', $existingColumns) && !empty($data['latitude'])) {
        $updateFields[] = "`latitude` = ?";
        $updateValues[] = encryptData($data['latitude']);
        $types .= 's';
    }
    if (in_array('longitude', $existingColumns) && !empty($data['longitude'])) {
        $updateFields[] = "`longitude` = ?";
        $updateValues[] = encryptData($data['longitude']);
        $types .= 's';
    }
    if (in_array('accuracy', $existingColumns) && !empty($data['accuracy'])) {
        $updateFields[] = "`accuracy` = ?";
        $updateValues[] = $data['accuracy'];
        $types .= 's';
    }

    // If no fields to update, return error
    if (empty($updateFields)) {
        sendJsonResponse(['success' => false, 'message' => 'No fields to update']);
    }

    // Add agent_id to values
    $updateValues[] = $agentId;
    $types .= 's';

    // Build the update query
    $sql = "UPDATE agents SET " . implode(', ', $updateFields) . " WHERE agent_id = ?";
    
    // Execute the query
    $conn = getDbConnection();
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendJsonResponse(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->bind_param($types, ...$updateValues);
    $stmt->execute();
    
    if ($stmt->affected_rows === -1) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to update: ' . $stmt->error]);
    }
    $stmt->close();

    // Mark onboarding as completed if columns exist
    if (in_array('onboarding_completed', $existingColumns)) {
        $stmt = $conn->prepare("UPDATE agents SET onboarding_completed = 1, submitted = 1, submitted_at = NOW(), completion_date = NOW() WHERE agent_id = ?");
        $stmt->bind_param('s', $agentId);
        $stmt->execute();
        $stmt->close();
    }

    // Save onboarding data
    saveOnboardingData($agentId, $data);
    addAuditLog($agentId, 'onboarding_completed', 'Final submission completed');

    sendJsonResponse(['success' => true, 'message' => 'Onboarding submitted successfully']);

} catch (Exception $e) {
    error_log("submit-onboarding.php error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>