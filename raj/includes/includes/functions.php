<?php
// /raj/includes/functions.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/json-storage.php';

// ============================================================
// AGENT FUNCTIONS - Database
// ============================================================

function getAgent($agentId) {
    $result = executeQuery("SELECT * FROM agents WHERE agent_id = ?", [$agentId]);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

function getAgents($filters = []) {
    $sql = "SELECT * FROM agents WHERE 1=1";
    $params = [];
    $types = '';
    
    if (!empty($filters['search'])) {
        $sql .= " AND (agent_id LIKE ? OR full_name LIKE ? OR email LIKE ? OR mobile LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params = array_merge($params, [$search, $search, $search, $search]);
        $types .= 'ssss';
    }
    
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'verified') {
            $sql .= " AND verification_status = 'verified'";
        } elseif ($filters['status'] === 'pending') {
            $sql .= " AND verification_status = 'pending'";
        } elseif ($filters['status'] === 'rejected') {
            $sql .= " AND verification_status = 'rejected'";
        } elseif ($filters['status'] === 'onboarding_completed') {
            $sql .= " AND onboarding_completed = 1";
        } elseif ($filters['status'] === 'onboarding_pending') {
            $sql .= " AND onboarding_completed = 0";
        }
    }
    
    if (!empty($filters['shift'])) {
        $sql .= " AND shift_type = ?";
        $params[] = $filters['shift'];
        $types .= 's';
    }
    
    if (!empty($filters['location'])) {
        $sql .= " AND work_location = ?";
        $params[] = $filters['location'];
        $types .= 's';
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    if (!empty($filters['limit'])) {
        $sql .= " LIMIT ?";
        $params[] = (int)$filters['limit'];
        $types .= 'i';
    }
    
    $result = executeQuery($sql, $params, $types);
    if ($result) {
        $agents = [];
        while ($row = $result->fetch_assoc()) {
            $agents[] = $row;
        }
        return $agents;
    }
    return [];
}

function saveAgentField($agentId, $field, $value) {
    $sql = "UPDATE agents SET `$field` = ? WHERE agent_id = ?";
    return executeQuery($sql, [$value, $agentId]);
}

function generateAgentId() {
    $result = executeQuery("SELECT agent_id FROM agents ORDER BY agent_id DESC LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $num = (int) substr($row['agent_id'], 3) + 1;
    } else {
        $num = 1000;
    }
    return 'BGS' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// ONBOARDING DATA - Database (JSON)
// ============================================================

function getOnboardingData($agentId) {
    $agent = getAgent($agentId);
    if (!$agent) return [];
    
    if (empty($agent['onboarding_data'])) {
        return [];
    }
    
    $data = json_decode($agent['onboarding_data'], true);
    return is_array($data) ? $data : [];
}

function saveOnboardingData($agentId, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return saveAgentField($agentId, 'onboarding_data', $json);
}

// ============================================================
// AUDIT LOG - Database + JSON Storage
// ============================================================

function addAuditLog($agentId, $action, $details = '') {
    // Save to database
    if (function_exists('executeQuery')) {
        executeQuery("INSERT INTO audit_log (agent_id, action, details) VALUES (?, ?, ?)", 
                     [$agentId, $action, $details]);
    }
    // Save to JSON storage
    return getJsonStorage()->addAuditLog($agentId, $action, $details);
}

function getAuditLog($agentId) {
    return getJsonStorage()->getAuditLog($agentId);
}

// ============================================================
// JSON STORAGE FUNCTIONS
// ============================================================

function getAgentProfileJson($agentId) {
    return getJsonStorage()->getAgentProfile($agentId);
}

function saveAgentProfileJson($agentId, $profile) {
    return getJsonStorage()->saveAgentProfile($agentId, $profile);
}

function initializeAgentStorage($agentId, $data = []) {
    return getJsonStorage()->initializeAgent($agentId, $data);
}

function ensureAgentStorage($agentId) {
    return getJsonStorage()->initializeAgent($agentId);
}

// ============================================================
// VALIDATION FUNCTIONS
// ============================================================

function validateMobile($mobile) { 
    return preg_match('/^[6-9]\d{9}$/', $mobile); 
}

function validatePan($pan) { 
    return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($pan)); 
}

function validateAadhaar($aadhaar) { 
    return preg_match('/^\d{12}$/', $aadhaar); 
}

function validatePincode($pincode) { 
    return preg_match('/^\d{6}$/', $pincode); 
}

function validateEmail($email) { 
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; 
}

// ============================================================
// FILE UPLOAD FUNCTIONS
// ============================================================

function generateRandomFilename($ext) {
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

function getStoragePath($agentId) {
    return STORAGE_PATH . $agentId . '/';
}

function saveUploadedFile($agentId, $file, $subdir, $allowedMimes = null) {
    if ($allowedMimes === null) $allowedMimes = ALLOWED_MIME_TYPES;
    if ($file['error'] !== UPLOAD_ERR_OK) return 'Upload error.';
    if ($file['size'] > UPLOAD_MAX_SIZE) return 'File too large.';
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowedMimes)) return 'Invalid file type.';
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','pdf'];
    if (!in_array($ext, $allowedExts)) return 'Invalid extension.';
    
    $newName = generateRandomFilename($ext);
    $targetDir = getStoragePath($agentId) . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $targetPath = $targetDir . $newName;
    
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) return 'Move failed.';
    
    return $subdir . '/' . $newName;
}

function getFileUrl($agentId, $filePath) {
    if (empty($filePath)) return '';
    return '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($filePath);
}

function fileExistsInStorage($agentId, $filePath) {
    if (empty($filePath)) return false;
    $fullPath = getStoragePath($agentId) . $filePath;
    return file_exists($fullPath);
}

// ============================================================
// EMAIL FUNCTIONS
// ============================================================

function sendOtpEmail($email, $otp) {
    $phpmailerPath = __DIR__ . '/PHPMailer/PHPMailer.php';
    $smtpPath = __DIR__ . '/PHPMailer/SMTP.php';
    $exceptionPath = __DIR__ . '/PHPMailer/Exception.php';
    
    if (file_exists($phpmailerPath) && file_exists($smtpPath) && file_exists($exceptionPath)) {
        try {
            require_once $phpmailerPath;
            require_once $smtpPath;
            require_once $exceptionPath;
            
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email);
            $mail->isHTML(false);
            $mail->Subject = 'Email Verification OTP - ' . APP_NAME;
            $mail->Body = "Your OTP for email verification is: $otp\n\nThis OTP is valid for 5 minutes.\n\nRegards,\n" . APP_NAME;
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer error: " . $mail->ErrorInfo);
        }
    }
    
    $subject = "Email Verification OTP - " . APP_NAME;
    $message = "Your OTP for email verification is: $otp\n\nThis OTP is valid for 5 minutes.\n\nRegards,\n" . APP_NAME;
    $headers = "From: " . APP_NAME . " <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    $headers .= "Reply-To: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $result = mail($email, $subject, $message, $headers);
    if (!$result) {
        error_log("OTP mail failed to $email on " . date('c'));
        return false;
    }
    return true;
}

// ============================================================
// MASKING FUNCTIONS
// ============================================================

function maskAadhaar($aadhaar) {
    if (strlen($aadhaar) != 12) return $aadhaar;
    return 'XXXX XXXX ' . substr($aadhaar, -4);
}

function maskPan($pan) {
    if (strlen($pan) != 10) return $pan;
    return '******' . substr($pan, -4);
}

// ============================================================
// DECRYPT HELPER
// ============================================================

function decryptField($value) {
    if (empty($value)) return '';
    $decrypted = decryptData($value);
    return !empty($decrypted) ? $decrypted : $value;
}

// ============================================================
// RESPONSE FUNCTIONS
// ============================================================

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($data);
    exit;
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function getAgentInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }
    return $initials;
}

function formatDuration($seconds) {
    $seconds = (int)$seconds;
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}

function getStatusBadgeClass($status) {
    $map = [
        'verified' => 'badge-success',
        'rejected' => 'badge-danger',
        'pending' => 'badge-info',
        'correction_requested' => 'badge-warning',
        'active' => 'badge-success',
        'inactive' => 'badge-secondary'
    ];
    return $map[$status] ?? 'badge-secondary';
}

function getShiftBadgeClass($shiftType) {
    return $shiftType === 'day' ? 'badge-day' : 'badge-night';
}

function getLocationBadgeClass($location) {
    return $location === 'office' ? 'badge-office' : 'badge-home';
}

function getShiftIcon($shiftType) {
    return $shiftType === 'day' ? 'fa-sun' : 'fa-moon';
}

function getLocationIcon($location) {
    return $location === 'office' ? 'fa-building' : 'fa-home';
}

// ============================================================
// STATUS LABEL - Single definition
// ============================================================

function getStatusLabel($status) {
    $map = [
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'pending' => 'Pending',
        'correction_requested' => 'Correction Requested',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'not_started' => 'Not Started',
        'logged_out' => 'Logged Out',
        'on_break' => 'On Break',
        'paused' => 'Paused',
        'working' => 'Working',
        'present' => 'Present',
        'absent' => 'Absent',
        'holiday' => 'Holiday',
        'correction' => 'Correction'
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
?>