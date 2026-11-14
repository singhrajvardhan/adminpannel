<?php
// /raj/agent/joining-letter-pdf.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$agentId = $_GET['agent'] ?? $_SESSION['agent_id'] ?? '';
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!$isAdmin && (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true)) {
    header('Location: login.php'); exit;
}

$letter = executeQuery("SELECT * FROM joining_letters WHERE agent_id = ?", [$agentId]);
if (!$letter || $letter->num_rows === 0) die("Joining letter not found.");
$d = $letter->fetch_assoc();

// Get stamp
$stampQuery = executeQuery("SELECT company_stamp_path FROM joining_letters WHERE company_stamp_path IS NOT NULL ORDER BY id DESC LIMIT 1");
$stampPath = ($stampQuery && $stampQuery->num_rows > 0) ? $stampQuery->fetch_assoc()['company_stamp_path'] : '';

// Photo
$photoUrl = '';
if (!empty($d['photo_path'])) {
    $fullPath = __DIR__ . '/../storage/agents/' . $d['agent_id'] . '/' . $d['photo_path'];
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $d['agent_id'] . '&file=' . urlencode($d['photo_path']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Joining Letter - <?php echo htmlspecialchars($d['full_name']); ?></title>
    <style>
        @page { margin: 15mm; size: A4; }
        body { font-family: 'Inter', Arial, sans-serif; font-size: 12px; color: #1c2536; line-height: 1.7; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #c9962f; padding-bottom: 10px; margin-bottom: 15px; }
        .company-info { text-align: right; }
        .company-info h2 { font-size: 20px; margin: 0; color: #0c1a38; }
        .company-info p { font-size: 10px; margin: 2px 0; color: #555; }
        .ref-no { font-size: 10px; color: #888; }
        .date-line { text-align: right; font-size: 11px; margin-bottom: 12px; }
        .subject { font-size: 14px; font-weight: 700; text-decoration: underline; margin-bottom: 12px; }
        .photo-box { text-align: center; margin: 10px 0; }
        .photo-box img { width: 80px; height: 80px; border-radius: 6px; border: 1px solid #ccc; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        td { padding: 5px 8px; border: 1px solid #ddd; font-size: 11px; }
        td:first-child { font-weight: 600; background: #f9f9f9; width: 160px; }
        .sign-section { display: flex; justify-content: space-between; margin-top: 30px; border-top: 1px solid #ccc; padding-top: 15px; }
        .sign-box { text-align: center; }
        .sign-box img { max-width: 150px; max-height: 60px; }
        .sign-line { border-bottom: 1px solid #000; width: 160px; margin: 30px auto 4px; }
        .disclaimer { font-size: 9px; color: #999; text-align: center; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; font-style: italic; }
        .footer { margin-top: 15px; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div><div class="ref-no">Ref: BGS/JL/<?php echo date('Y'); ?>/<?php echo $d['agent_id']; ?></div></div>
        <div class="company-info">
            <h2>Better Growth Solutions</h2>
            <p>Cosmos City, Bicholi Mardana, Indore – 452016</p>
            <p>info@bettergrowthsolutions.com</p>
        </div>
    </div>

    <div class="date-line">Date: <?php echo date('d M Y', strtotime($d['joining_date'])); ?></div>
    <div class="subject">SUBJECT: APPOINTMENT LETTER</div>
    
    <p>Dear <strong><?php echo htmlspecialchars($d['full_name']); ?></strong>,</p>
    <p>We are pleased to appoint you as <strong><?php echo htmlspecialchars($d['designation']); ?></strong> on the following terms:</p>

    <?php if(!empty($photoUrl)): ?>
    <div class="photo-box"><img src="<?php echo $photoUrl; ?>" alt="Photo"></div>
    <?php endif; ?>

    <table>
        <tr><td>Name</td><td><strong><?php echo htmlspecialchars($d['full_name']); ?></strong></td></tr>
        <tr><td>Agent ID</td><td><?php echo $d['agent_id']; ?></td></tr>
        <tr><td>Date of Joining</td><td><strong><?php echo date('d M Y', strtotime($d['joining_date'])); ?></strong></td></tr>
        <tr><td>Designation</td><td><?php echo htmlspecialchars($d['designation']); ?></td></tr>
        <tr><td>Salary</td><td><strong>₹<?php echo number_format($d['salary'], 0); ?>/month</strong></td></tr>
        <tr><td>Shift</td><td><?php echo $d['shift_type'] === 'night' ? '7:30 PM – 4:30 AM IST' : 'Day Shift'; ?></td></tr>
        <tr><td>Location</td><td><?php echo $d['work_location'] === 'home' ? 'Work From Home' : 'Office'; ?></td></tr>
    </table>

    <p>We welcome you and look forward to a rewarding association.</p>
    <p>Yours sincerely,<br><strong>For Better Growth Solutions</strong></p>

    <div class="sign-section">
        <div class="sign-box">
            <?php if(!empty($d['signature_data'])): ?>
                <img src="<?php echo $d['signature_data']; ?>" alt="Signature">
            <?php else: ?>
                <div class="sign-line"></div>
            <?php endif; ?>
            <p><strong><?php echo htmlspecialchars($d['full_name']); ?></strong><br>Employee</p>
        </div>
        <div class="sign-box">
            <?php if(!empty($stampPath)): ?>
                <img src="<?php echo $stampPath; ?>" alt="Stamp"><br>
            <?php else: ?>
                <div class="sign-line"></div>
            <?php endif; ?>
            <p><strong>Authorized Signatory</strong><br>Better Growth Solutions</p>
        </div>
    </div>

    <div class="disclaimer">
        <strong>Disclaimer:</strong> Neither BGS nor Rajvardhan Singh is responsible for any inadvertent error that may have crept in the joining letter being published on BGS. This is a computer-generated document.
    </div>
    <div class="footer">Better Growth Solutions © <?php echo date('Y'); ?></div>
    <script>window.onload=function(){window.print();};</script>
</body>
</html>