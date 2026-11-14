<?php
// /raj/api/upload-document.php
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// SERVE PHOTOS - This is what displays images
// ============================================
if (($_GET['action'] ?? '') === 'serve') {
    $agentId = $_GET['agent'] ?? '';
    $file = $_GET['file'] ?? '';
    
    if (empty($agentId) || empty($file)) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }
    
    // Build the full file path
    $filePath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $file;
    
    // Check if file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    
    // Get file extension for content type
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $contentTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf'
    ];
    
    $contentType = $contentTypes[$ext] ?? 'application/octet-stream';
    
    // Send the file
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// ============================================
// UPLOAD PHOTOS
// ============================================
$agentId = $_POST['agent_id'] ?? '';
if (empty($agentId)) {
    echo json_encode(['success' => false, 'message' => 'Agent ID required']);
    exit;
}

$type = $_POST['type'] ?? '';
$file = $_FILES['file'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB']);
    exit;
}

// Validate file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
    exit;
}

// Map type to subdirectory
$typeMap = [
    'resume' => 'documents',
    'pan' => 'pan',
    'aadhaar_front' => 'aadhaar',
    'aadhaar_back' => 'aadhaar',
    'photo' => 'candidate',
    'house1' => 'address',
    'house2' => 'address',
    'street' => 'address'
];

$subdir = $typeMap[$type] ?? 'documents';

// Save file
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$newName = bin2hex(random_bytes(16)) . '.' . $ext;
$targetDir = __DIR__ . '/../storage/agents/' . $agentId . '/' . $subdir . '/';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$targetPath = $targetDir . $newName;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $relativePath = $subdir . '/' . $newName;
    
    // Update database
    require_once __DIR__ . '/../includes/db.php';
    $conn = getDbConnection();
    
    $dbField = '';
    switch ($type) {
        case 'resume': $dbField = 'resume_path'; break;
        case 'pan': $dbField = 'pan_image_path'; break;
        case 'aadhaar_front': $dbField = 'aadhaar_front_path'; break;
        case 'aadhaar_back': $dbField = 'aadhaar_back_path'; break;
        case 'photo': $dbField = 'candidate_photo_path'; break;
        case 'house1': $dbField = 'house_photo_1'; break;
        case 'house2': $dbField = 'house_photo_2'; break;
        case 'street': $dbField = 'street_photo'; break;
    }
    
    if ($dbField) {
        $conn->query("UPDATE agents SET `$dbField` = '$relativePath' WHERE agent_id = '$agentId'");
    }
    
    echo json_encode(['success' => true, 'path' => $relativePath]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
}
?>