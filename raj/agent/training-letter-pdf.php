<?php
// /raj/agent/training-letter-pdf.php
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

$agreement = executeQuery("SELECT * FROM training_letters WHERE agent_id = ?", [$agentId]);
if (!$agreement || $agreement->num_rows === 0) die("Agreement not found.");
$data = $agreement->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Training Letter - <?php echo htmlspecialchars($data['full_name']); ?></title>
    <style>
        @page { margin: 12mm; size: A4; }
        body { font-family: 'Inter', Arial, sans-serif; font-size: 11px; color: #1c2536; line-height: 1.6; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #c9962f; padding-bottom: 10px; margin-bottom: 15px; }
        .company-info { text-align: right; }
        .company-info h2 { font-size: 18px; margin:0; color: #0c1a38; }
        .company-info p { font-size: 10px; margin:2px 0; color: #555; }
        .agent-details table { width: 100%; border-collapse: collapse; }
        .agent-details td { padding: 2px 5px; font-size: 11px; }
        .agent-details td:first-child { font-weight: 600; width: 100px; color: #555; }
        .salary-note { background: #fdf1de; border: 1px solid #c9962f; padding: 8px; text-align: center; margin: 12px 0; font-weight: 600; }
        h3 { font-size: 14px; color: #163063; border-bottom: 1px solid #c9962f; margin: 12px 0 6px; }
        ul { padding-left: 18px; margin: 4px 0; }
        li { margin-bottom: 2px; }
        .sign-section { display: flex; margin-top: 30px; border-top: 1px solid #ccc; padding-top: 15px; }
        .sign-box { flex: 1; text-align: center; }
        .sign-box img { max-width: 150px; max-height: 60px; }
        .footer { margin-top: 20px; font-size: 9px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="agent-details">
            <table>
                <tr><td>Employee:</td><td><strong><?php echo htmlspecialchars($data['full_name']); ?></strong></td></tr>
                <tr><td>Agent ID:</td><td><?php echo htmlspecialchars($data['agent_id']); ?></td></tr>
                <tr><td>Email:</td><td><?php echo htmlspecialchars($data['email']); ?></td></tr>
                <tr><td>Phone:</td><td><?php echo htmlspecialchars($data['phone']); ?></td></tr>
            </table>
        </div>
        <div class="company-info">
            <h2>Better Growth Solutions</h2>
            <p>Cosmos City, Bicholi Mardana, Indore – 452016</p>
            <p>info@bettergrowthsolutions.com</p>
        </div>
    </div>

    <div class="salary-note">Starting Salary: ₹13,000 per month (in-hand) – Performance-based increments</div>

    <h3>Employee Compliance & Code of Conduct Policy</h3>
    <p>Applicable to all Work-From-Home / Remote Agents</p>
    <ol>
        <li><strong>Working Hours:</strong> 9 hrs/day, 7:30 PM–4:30 AM IST, Mon–Fri. 60 min break.</li>
        <li><strong>Technical:</strong> 50–80 Mbps internet, power backup, quiet environment, no personal phone.</li>
        <li><strong>Leave:</strong> U.S. holidays; Indian festivals need approval. 30-day notice for resignation.</li>
        <li><strong>Salary:</strong> Credited on 10th monthly.</li>
        <li><strong>Monitoring:</strong> Full screen sharing; calls/screens recorded.</li>
        <li><strong>Data Protection:</strong> No customer data on personal devices; no social media contact.</li>
        <li><strong>Conduct:</strong> Respectful communication; no deceptive sales.</li>
        <li><strong>Confidentiality:</strong> Company info protected; return equipment.</li>
        <li><strong>Discipline:</strong> Violations may lead to termination.</li>
    </ol>

    <div class="sign-section">
        <div class="sign-box">
            <p><strong>Employee Signature:</strong></p>
            <?php if(!empty($data['signature_data'])): ?>
                <img src="<?php echo $data['signature_data']; ?>" alt="Signature">
            <?php endif; ?>
            <p><?php echo htmlspecialchars($data['full_name']); ?><br><?php echo date('d M Y', strtotime($data['agreed_at'])); ?></p>
        </div>
        <div class="sign-box">
            <p><strong>Company Representative:</strong></p>
            <img src="/hr-sign.png" alt="HR Sign" style="max-width:120px;" onerror="this.style.display='none'">
            <p>Authorized Signatory</p>
        </div>
    </div>

    <div class="footer">Better Growth Solutions © <?php echo date('Y'); ?> | Training Letter #<?php echo $data['id']; ?></div>
    <script>window.onload=function(){window.print();};</script>
</body>
</html>