<?php
// /raj/admin/view-letter.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['agent_logged_in'])) {
    header('Location: login.php'); exit;
}

$letterId = $_GET['id'] ?? 0;
if (!$letterId) { header('Location: admin-joining-letter.php'); exit; }

// Get letter details
$letterQuery = executeQuery("
    SELECT 
        jl.*,
        a.full_name,
        a.email,
        a.mobile,
        a.agent_id,
        a.candidate_photo_path
    FROM joining_letters jl
    LEFT JOIN agents a ON jl.agent_id = a.agent_id
    WHERE jl.id = ?
", [$letterId]);

if (!$letterQuery || $letterQuery->num_rows === 0) {
    header('Location: admin-joining-letter.php');
    exit;
}
$letter = $letterQuery->fetch_assoc();

// Display the letter using the existing showLetter function
// Include the joining-letter.php to use its showLetter function
include_once 'joining-letter.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Joining Letter</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family:'Inter',sans-serif; background:#f5f4f0; padding:20px; }
        .container { max-width:960px; margin:0 auto; }
        .back-btn { margin-bottom:16px; }
        .back-btn a { color:var(--navy-800); text-decoration:none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="back-btn">
            <a href="admin-joining-letter.php"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
        <?php
        // Reuse the showLetter function
        $photoUrl = '';
        $stampPath = '';
        showLetter($letter, $photoUrl, $stampPath, null);
        ?>
    </div>
</body>
</html>