<?php
// /raj/agent/compliance-pdf.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$agentId = $_GET['agent'] ?? $_SESSION['agent_id'] ?? '';

// Admin can view any agent's PDF
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!$isAdmin && (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true)) {
    header('Location: login.php');
    exit;
}

$agreement = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$agentId]);
if (!$agreement || $agreement->num_rows === 0) {
    die("Agreement not found.");
}
$data = $agreement->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compliance Agreement - <?php echo htmlspecialchars($data['full_name']); ?></title>
    <style>
        @page { margin: 15mm; size: A4; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1c2536; line-height: 1.7; }
        .header { text-align: center; border-bottom: 3px solid #c9962f; padding-bottom: 12px; margin-bottom: 16px; }
        .header h1 { font-size: 18px; color: #0c1a38; margin: 0; }
        .header .subtitle { font-size: 11px; color: #667085; }
        .employee-info { border: 1px solid #ddd; padding: 12px; margin-bottom: 16px; border-radius: 8px; }
        .employee-info table { width: 100%; border-collapse: collapse; }
        .employee-info td { padding: 4px 8px; font-size: 11px; }
        .employee-info td:first-child { font-weight: 600; width: 120px; color: #667085; }
        h2 { font-size: 14px; color: #163063; border-bottom: 1px solid #c9962f; padding-bottom: 4px; margin: 16px 0 8px; }
        ul { padding-left: 18px; }
        li { margin-bottom: 3px; }
        .signature-section { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 16px; }
        .signature-img { max-width: 300px; max-height: 80px; border: 1px solid #ddd; padding: 8px; }
        .footer { margin-top: 20px; font-size: 9px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 8px; }
        @media print { body { -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Employee Compliance & Code of Conduct Agreement</h1>
        <div class="subtitle">BetterGrowth Solutions | Generated on <?php echo date('d M Y'); ?></div>
    </div>

    <div class="employee-info">
        <table>
            <tr><td>Employee Name:</td><td><strong><?php echo htmlspecialchars($data['full_name']); ?></strong></td></tr>
            <tr><td>Agent ID:</td><td><?php echo htmlspecialchars($data['agent_id']); ?></td></tr>
            <tr><td>Email:</td><td><?php echo htmlspecialchars($data['email']); ?></td></tr>
            <tr><td>Phone:</td><td><?php echo htmlspecialchars($data['phone']); ?></td></tr>
            <tr><td>Address:</td><td><?php echo htmlspecialchars($data['address'] ?? 'N/A'); ?></td></tr>
            <tr><td>Agreement Date:</td><td><?php echo date('d M Y, h:i A', strtotime($data['agreed_at'])); ?></td></tr>
            <tr><td>Status:</td><td><strong><?php echo ucfirst($data['status']); ?></strong></td></tr>
            <?php if ($data['status'] === 'approved'): ?>
            <tr><td>Approved By:</td><td><?php echo htmlspecialchars($data['approved_by'] ?? 'Admin'); ?> on <?php echo date('d M Y', strtotime($data['approved_at'] ?? 'now')); ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <h2>1. Working Hours & Attendance</h2>
    <ul>
        <li>Standard shift: 9 hours/day, 7:30 PM - 4:30 AM IST, Mon-Fri. Sat-Sun off.</li>
        <li>60 minutes paid break: 30-min lunch, 15-min snack, 15-min break.</li>
        <li>Must inform manager before and after breaks.</li>
        <li>Must log in on time. Late/irregular login = policy violation.</li>
    </ul>

    <h2>2. Technical Requirements</h2>
    <ul>
        <li>Internet: 50-80 Mbps minimum, stable connection.</li>
        <li>Power backup required. Quiet, distraction-free environment.</li>
        <li>Company tools for official use only. No unauthorized software.</li>
        <li>No VPNs. No personal phone at workstation.</li>
    </ul>

    <h2>3. Leave & Holiday Policy</h2>
    <ul>
        <li>U.S. holiday calendar followed. Indian festivals require HR approval.</li>
        <li>Planned leave: advance notice required. 30-day notice for resignation.</li>
    </ul>

    <h2>4. Salary</h2>
    <ul><li>Credited on 10th of each month (next working day if weekend/holiday).</li></ul>

    <h2>5. Monitoring</h2>
    <ul>
        <li>Full screen sharing required during shift.</li>
        <li>Calls and screens monitored/recorded for QA and compliance.</li>
    </ul>

    <h2>6. Data Protection</h2>
    <ul>
        <li>No saving customer data on personal devices.</li>
        <li>No personal social media contact with customers.</li>
        <li>No third-party access to company systems.</li>
    </ul>

    <h2>7. Professional Conduct</h2>
    <ul>
        <li>Respectful communication. No harassment, abuse, or deceptive practices.</li>
        <li>Follow approved scripts and guidelines.</li>
    </ul>

    <h2>8. Confidentiality</h2>
    <ul>
        <li>Company information confidential during and after employment.</li>
        <li>All equipment must be returned upon separation.</li>
    </ul>

    <h2>9. Disciplinary Action</h2>
    <ul>
        <li>Violations may result in warnings, suspension, termination, or legal action.</li>
           <li>The Company reserves the right to terminate the employee’s employment at any time, with or without notice, subject to applicable law and the terms of the employment agreement. Termination may occur due to misconduct, policy violations, performance concerns, operational requirements, loss of business, or other legitimate reasons.

Unless otherwise required by applicable law or expressly provided in the employment agreement, no additional termination payment, severance pay, or compensation for termination will be payable by the Company. Any salary or other amounts legally due to the employee up to the effective date of termination will be settled in accordance with applicable law and Company policy</li>
    </ul>

    <div class="signature-section">
        <h2>Acknowledgement & Signature</h2>
        <p><strong>I confirm that I have read, understood, and agree to comply with all the policies stated in this document.</strong></p>
        <p>
            <strong>Employee Signature:</strong><br>
            <?php if (!empty($data['signature_data'])): ?>
                <img src="<?php echo $data['signature_data']; ?>" class="signature-img" alt="Signature">
            <?php endif; ?>
        </p>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($data['full_name']); ?></p>
        <p><strong>Date:</strong> <?php echo date('d M Y', strtotime($data['agreed_at'])); ?></p>
    </div>

    <div class="footer">
        This document is electronically generated and is valid without a physical signature.<br>
        BetterGrowth Solutions © <?php echo date('Y'); ?> | Compliance Agreement #<?php echo $data['id']; ?>
    </div>

    <script>
        window.onload = function() { window.print(); };
    </script>
</body>
</html>