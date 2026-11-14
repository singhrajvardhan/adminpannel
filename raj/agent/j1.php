<?php
// /raj/agent/joining-letter-cover.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$agentId = $_SESSION['agent_id'] ?? '';
$agent = getAgent($agentId);
if (!$agent) { header('Location: login.php'); exit; }

// Get joining letter data
$letterQuery = executeQuery("SELECT * FROM joining_letters WHERE agent_id = ?", [$agentId]);
$letter = ($letterQuery && $letterQuery->num_rows > 0) ? $letterQuery->fetch_assoc() : null;

// Get compliance data
$complianceCheck = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$agentId]);
$complianceData = ($complianceCheck && $complianceCheck->num_rows > 0) ? $complianceCheck->fetch_assoc() : null;

// Get company stamp
$stampPath = '';
$stampQuery = executeQuery("SELECT setting_value FROM company_settings WHERE setting_key = 'company_stamp'");
if ($stampQuery && $stampQuery->num_rows > 0) {
    $stampPath = $stampQuery->fetch_assoc()['setting_value'];
}

// Agent photo
$photoUrl = '';
if (!empty($agent['candidate_photo_path'])) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $agent['candidate_photo_path'];
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($agent['candidate_photo_path']);
    }
}

// Data
$fullName = $letter['full_name'] ?? $agent['full_name'] ?? 'Employee';
$email = $letter['email'] ?? $agent['email'] ?? 'N/A';
$phone = $letter['phone'] ?? $agent['mobile'] ?? 'N/A';
$joiningDate = $letter['joining_date'] ?? $agent['joining_date'] ?? date('Y-m-d');
$designation = $letter['designation'] ?? $agent['designation'] ?? 'Sales Executive';
$department = $letter['department'] ?? $agent['department'] ?? 'Sales';
$salary = $letter['salary'] ?? $agent['salary'] ?? 13000;
$shiftType = $letter['shift_type'] ?? $agent['shift_type'] ?? 'night';
$workLocation = $letter['work_location'] ?? $agent['work_location'] ?? 'home';

$signatureData = $letter['signature_data'] ?? '';
$agreedAt = $letter['agreed_at'] ?? '';
$isSigned = !empty($signatureData);
$isApproved = ($letter['status'] ?? '') === 'approved';
$isRejected = ($letter['status'] ?? '') === 'rejected';
$approvedAt = $letter['approved_at'] ?? '';
$approvedBy = $letter['approved_by'] ?? 'Admin';
$complianceId = $complianceData['id'] ?? 'N/A';
$verificationCode = strtoupper(substr(md5($agentId . ($approvedAt ?: $joiningDate)), 0, 8));

$statusLabel = $isApproved ? 'Approved' : ($isRejected ? 'Rejected' : ($isSigned ? 'Signed' : 'Pending'));
$statusClass = $isApproved ? 'approved' : ($isRejected ? 'rejected' : ($isSigned ? 'signed' : 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Joining Letter - Better Growth Solutions</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{
            font-family:'Inter',sans-serif;background:#e8e4dc;
            display:flex;flex-direction:column;align-items:center;
            min-height:100vh;padding:20px;
        }
        .a4-page{
            width:210mm;min-height:297mm;background:#fff;
            margin:0 auto;padding:14mm 16mm 12mm;
            box-shadow:0 8px 40px rgba(0,0,0,.12);border-radius:4px;
            position:relative;overflow:hidden;
        }
        .a4-page::before{
            content:'';position:absolute;top:0;left:0;right:0;height:7px;
            background:linear-gradient(90deg,#b8860b,#c9962f,#dcae4d,#c9962f,#b8860b);z-index:5;
        }
        .a4-content{position:relative;z-index:1;}

        /* Approved Stamp */
        .approved-stamp{
            position:absolute;top:50%;left:50%;
            transform:translate(-50%,-50%) rotate(-28deg);z-index:2;
            opacity:.12;pointer-events:none;
            font-family:'Fraunces',serif;font-size:90px;font-weight:800;
            color:#1e7a41;border:8px solid #1e7a41;
            padding:20px 50px;border-radius:20px;letter-spacing:12px;white-space:nowrap;
        }
        @media(max-width:600px){.approved-stamp{font-size:40px;padding:10px 25px;border-width:5px;letter-spacing:6px;}}

        /* Header */
        .letter-header{
            display:flex;justify-content:space-between;align-items:flex-start;
            border-bottom:3px double #c9962f;padding-bottom:16px;margin-bottom:16px;
            flex-wrap:wrap;gap:12px;
        }
        .header-left{display:flex;align-items:center;gap:14px;}
        .logo-box{
            height:55px;padding:4px 8px;background:#f8f7f3;border-radius:8px;
            border:2px solid #fdf1de;display:flex;align-items:center;flex-shrink:0;
        }
        .logo-box img{height:100%;width:auto;max-width:120px;}
        .brand-text{font-family:'Fraunces',serif;font-size:22px;font-weight:800;color:#0a1628;line-height:1.1;}
        .brand-text .tagline{
            font-size:8px;font-weight:400;color:#5a6a7e;letter-spacing:3px;
            text-transform:uppercase;display:block;font-family:'Inter',sans-serif;margin-top:2px;
        }
        .header-right{text-align:right;flex:1;min-width:170px;}
        .header-right .ref{
            font-size:11px;color:#0a1628;font-weight:700;background:#fdf1de;
            padding:3px 14px;border-radius:4px;display:inline-block;border:1px solid #e0d5b0;margin-bottom:6px;
        }
        .header-right .address{font-size:10px;color:#5a6a7e;margin:2px 0;}
        .header-right .contact-row{
            display:flex;align-items:center;justify-content:flex-end;gap:12px;
            font-size:10px;color:#5a6a7e;margin-top:3px;flex-wrap:wrap;
        }
        .header-right .contact-row a{color:#29538c;text-decoration:none;}

        .date-line{text-align:right;font-size:12px;color:#5a6a7e;margin-bottom:14px;padding-bottom:6px;border-bottom:1px solid #ecebe6;}
        .subject{
            font-size:16px;font-weight:700;color:#0a1628;margin-bottom:14px;
            text-decoration:underline;text-underline-offset:5px;text-decoration-color:#c9962f;text-decoration-thickness:2.5px;
        }
        .salutation{font-size:13px;margin-bottom:12px;line-height:1.7;}
        .salutation strong{color:#163063;}

        .photo-row{text-align:center;margin:6px 0 16px;}
        .photo-row .photo-frame{
            display:inline-block;padding:4px;
            background:linear-gradient(135deg,#c9962f,#dcae4d);
            border-radius:50%;box-shadow:0 2px 12px rgba(201,150,47,.2);
        }
        .photo-row img{width:110px;height:110px;border-radius:50%;object-fit:cover;border:3px solid #fff;display:block;}

        .details-table-wrap{margin:14px 0 18px;border-radius:8px;border:1px solid #e8e6dd;overflow:hidden;}
        .details-table{width:100%;border-collapse:collapse;font-size:12px;}
        .details-table td{padding:8px 14px;border-bottom:1px solid #efede6;}
        .details-table tr:last-child td{border-bottom:none;}
        .details-table td:first-child{
            font-weight:600;background:#faf8f3;width:140px;color:#5a6a7e;
            font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
        }
        .details-table td:last-child{background:#fff;font-weight:500;}

        .terms-section{margin:18px 0 14px;}
        .terms-section .terms-title{
            font-family:'Fraunces',serif;font-size:15px;color:#163063;
            border-bottom:2px solid #c9962f;padding-bottom:6px;margin-bottom:12px;
        }
        .terms-section ol{padding-left:22px;columns:2;column-gap:25px;}
        .terms-section ol li{font-size:11.5px;line-height:1.8;margin-bottom:3px;break-inside:avoid;color:#1a2332;}
        .terms-section .closing{margin-top:12px;font-size:12.5px;color:#5a6a7e;font-style:italic;}
        .terms-section .sign-off{margin-top:8px;font-size:14px;font-weight:700;color:#0a1628;}
        @media(max-width:600px){.terms-section ol{columns:1;}}

        .signature-section{margin-top:22px;padding-top:18px;border-top:2px solid #ecebe6;}
        .signature-section .sig-title{
            font-family:'Fraunces',serif;font-size:14px;color:#163063;margin-bottom:14px;
        }
        .signature-grid{display:flex;gap:30px;flex-wrap:wrap;align-items:flex-end;}
        .signature-grid .sig-box{
            flex:1;min-width:180px;text-align:center;padding:14px 16px;
            background:#f8f7f3;border-radius:10px;border:1px solid #ecebe6;
        }
        .signature-grid .sig-box .sig-label{
            font-size:9px;color:#5a6a7e;text-transform:uppercase;letter-spacing:1.5px;
            font-weight:600;margin-bottom:8px;
        }
        .signature-grid .sig-box .sig-image{max-width:180px;max-height:60px;margin:4px auto;}
        .signature-grid .sig-box .sig-name{font-weight:700;font-size:13px;color:#0a1628;margin-top:4px;}
        .signature-grid .sig-box .sig-title-text{font-size:10px;color:#5a6a7e;}
        .signature-grid .sig-box .sig-date{font-size:10px;color:#5a6a7e;margin-top:2px;}
        .signature-grid .sig-box .sig-stamp{max-width:120px;max-height:70px;margin:4px auto;}
        .signature-grid .sig-box .sig-placeholder{
            padding:16px;border:1px dashed #d4c5a0;border-radius:6px;
            color:#5a6a7e;font-size:11px;background:#fff;
        }

        .approval-info{
            margin-top:16px;padding:12px 18px;
            border-radius:8px;border-left:4px solid #1e7a41;
            display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:center;
        }
        .approval-info.approved{background:linear-gradient(135deg,#eef6f0,#e4f0e8);}
        .approval-info.pending{background:linear-gradient(135deg,#fdf8f0,#fcf5e8);border-left-color:#c9962f;}
        .approval-info.rejected{background:linear-gradient(135deg,#fef5f5,#fce8e8);border-left-color:#b3352f;}
        .approval-info .approval-item{display:flex;align-items:center;gap:6px;font-size:12px;color:#5a6a7e;}
        .approval-info .verification{
            background:#163063;color:#fff;padding:2px 10px;border-radius:10px;
            font-size:9px;font-weight:600;letter-spacing:.5px;
        }

        .disclaimer{
            margin-top:16px;padding:12px 18px;background:#fffdf8;
            border:1px solid #e0d5b0;border-radius:8px;font-size:10px;
            color:#5a6a7e;text-align:center;line-height:1.8;
        }

        .status-badge{
            position:absolute;top:18px;right:18px;padding:6px 20px;
            border-radius:20px;font-size:12px;font-weight:700;z-index:10;
            box-shadow:0 2px 12px rgba(0,0,0,.08);
        }
        .status-badge.approved{background:#1e7a41;color:#fff;}
        .status-badge.pending{background:#c9962f;color:#0a1628;}
        .status-badge.signed{background:#163063;color:#fff;}
        .status-badge.rejected{background:#b3352f;color:#fff;}

        .action-bar{display:flex;justify-content:center;gap:10px;margin-top:24px;flex-wrap:wrap;max-width:800px;width:100%;}
        .btn{
            padding:10px 28px;border:none;border-radius:30px;font-size:13px;font-weight:600;
            cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;
            transition:all .25s;text-decoration:none;
        }
        .btn:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.12);}
        .btn-primary{background:#163063;color:#fff;}
        .btn-gold{background:#c9962f;color:#0a1628;}
        .btn-pdf{background:#e74c3c;color:#fff;}
        .btn-outline{background:transparent;color:#5a6a7e;border:2px solid #ecebe6;}
        .btn-outline:hover{border-color:#c9962f;color:#1a2332;background:#fdf1de;}

        @media print{
            body{background:#fff!important;padding:0!important;margin:0!important;}
            .action-bar{display:none!important;}
            .a4-page{
                width:100%!important;min-height:100vh!important;height:297mm!important;
                padding:14mm 16mm 12mm!important;margin:0!important;
                box-shadow:none!important;border-radius:0!important;
            }
            .a4-page::before{display:block!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
            .approved-stamp{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;opacity:.12!important;}
        }

        @media screen and (max-width:800px){
            .a4-page{width:100%;min-height:auto;padding:30px 24px;}
            .letter-header{flex-direction:column;}
            .header-right{text-align:left;width:100%;}
            .header-right .address,.header-right .contact-row{justify-content:flex-start;}
            .signature-grid{flex-direction:column;}
            .status-badge{position:static;margin-bottom:10px;}
        }
    </style>
</head>
<body>

    <div class="a4-page">
        <div class="a4-content">

            <?php if($isApproved): ?>
            <div class="approved-stamp">✓ APPROVED</div>
            <?php endif; ?>

         
            <!-- Header -->
            <div class="letter-header">
                <div class="header-left">
                    <div class="logo-box">
                        <img src="/bettergrowthsolutions.jpg" alt="BGS Logo">
                    </div>
                </div>
                <div class="header-right">
                    <span class="ref">Ref: BGS/JL/<?php echo date('Y'); ?>/<?php echo htmlspecialchars($agentId); ?></span>
                    <div class="address">📍 Cosmos City, Bicholi Mardana, Indore – 452016</div>
                    <div class="contact-row">
                        <span>📧 info@bettergrowthsolutions.com</span>
                        <span>🌐 <a href="https://bettergrowthsolutions.com">bettergrowthsolutions.com</a></span>
                        <span>📞 +91 92443-30496</span>
                    </div>
                </div>
            </div>

            <div class="date-line">📅 Date: <strong><?php echo date('d M Y', strtotime($joiningDate)); ?></strong></div>
            <div class="subject">SUBJECT: APPOINTMENT LETTER</div>

            <div class="salutation">
                <p>Dear <strong><?php echo htmlspecialchars($fullName); ?></strong>,</p>
                <p>We are pleased to appoint you as <strong><?php echo htmlspecialchars($designation); ?></strong> 
                in our <strong><?php echo htmlspecialchars($department); ?></strong> department.</p>
            </div>

            <?php if(!empty($photoUrl)): ?>
            <div class="photo-row">
                <div class="photo-frame"><img src="<?php echo $photoUrl; ?>" alt="Photo"></div>
            </div>
            <?php endif; ?>

            <div class="details-table-wrap">
                <table class="details-table">
                    <tr><td>Employee Name</td><td><strong><?php echo htmlspecialchars($fullName); ?></strong></td></tr>
                    <tr><td>Agent ID</td><td><?php echo htmlspecialchars($agentId); ?></td></tr>
                    <tr><td>Email</td><td><?php echo htmlspecialchars($email); ?></td></tr>
                    <tr><td>Phone</td><td><?php echo htmlspecialchars($phone); ?></td></tr>
                    <tr><td>Date of Joining</td><td><strong><?php echo date('d M Y', strtotime($joiningDate)); ?></strong></td></tr>
                    <tr><td>Designation</td><td><?php echo htmlspecialchars($designation); ?></td></tr>
                    <tr><td>Department</td><td><?php echo htmlspecialchars($department); ?></td></tr>
                    <tr><td>Starting Salary</td><td><strong>₹<?php echo number_format($salary, 0); ?>/month</strong> (in-hand)</td></tr>
                    <tr><td>Shift Timing</td><td><?php echo ($shiftType==='night')?'🕒 7:30 PM – 4:30 AM IST':'🕒 Day Shift'; ?></td></tr>
                    <tr><td>Work Location</td><td><?php echo ($workLocation==='home')?'🏠 Work From Home':'🏢 Office'; ?></td></tr>
                    <tr><td>Probation Period</td><td>3 Months</td></tr>
                </table>
            </div>

            <div class="terms-section">
                <div class="terms-title">📋 Terms & Conditions</div>
                <ol>
                    <li>Employment subject to company rules and code of conduct.</li>
                    <li>Salary credited on <strong>10th of every month</strong>.</li>
                    <li>Strict confidentiality of company and customer data.</li>
                    <li>Working hours: <strong>9 hours/day</strong>, Monday–Friday.</li>
                    <li>Must adhere to compliance policy signed separately.</li>
                    <li>Notice period: <strong>30 days</strong> for termination.</li>
                    <li>Performance reviews; increments are performance-based.</li>
                    <li>Leave policy as per company HR guidelines.</li>
                </ol>
                <div class="closing">We look forward to a long and mutually beneficial association.</div>
                <div class="sign-off">Yours sincerely,<br><strong>For Better Growth Solutions</strong></div>
            </div>

            <!-- Signatures -->
            <div class="signature-section">
                <div class="sig-title">✍️ Digital Signatures</div>
                <div class="signature-grid">
                    <div class="sig-box">
                        <div class="sig-label">Employee Signature</div>
                        <?php if(!empty($signatureData)): ?>
                            <img src="<?php echo $signatureData; ?>" class="sig-image" alt="Signature">
                            <div class="sig-name"><?php echo htmlspecialchars($fullName); ?></div>
                            <div class="sig-title-text">Employee</div>
                            <?php if(!empty($agreedAt)): ?>
                            <div class="sig-date">Signed: <?php echo date('d M Y, h:i A', strtotime($agreedAt)); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="sig-placeholder">⏳ Awaiting Signature</div>
                            <div class="sig-name" style="color:#5a6a7e;"><?php echo htmlspecialchars($fullName); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="sig-box">
                        <div class="sig-label">Company Authority</div>
                        <?php if(!empty($stampPath)): ?>
                            <img src="<?php echo $stampPath; ?>" class="sig-stamp" alt="Stamp">
                        <?php else: ?>
                            <div class="sig-placeholder">🏢 Company Stamp</div>
                        <?php endif; ?>
                        <div class="sig-name">Authorized Signatory</div>
                        <div class="sig-title-text">Better Growth Solutions</div>
                        <?php if($isApproved && !empty($approvedAt)): ?>
                        <div class="sig-date">✅ Approved: <?php echo date('d M Y', strtotime($approvedAt)); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Approval Info -->
            <?php if($isApproved): ?>
            <div class="approval-info approved">
                <div class="approval-item">✅ Approved by: <strong><?php echo htmlspecialchars($approvedBy); ?></strong></div>
                <div class="approval-item">📅 On: <strong><?php echo date('d M Y', strtotime($approvedAt)); ?></strong></div>
                <div class="approval-item">🔑 Verification: <span class="verification">#<?php echo $verificationCode; ?></span></div>
            </div>
            <?php elseif($isSigned): ?>
            <div class="approval-info pending">
                <div class="approval-item" style="color:#9a6a10;">⏳ Status: <strong>Pending Admin Approval</strong></div>
                <div class="approval-item">📅 Signed: <strong><?php echo date('d M Y', strtotime($agreedAt)); ?></strong></div>
            </div>
            <?php elseif($isRejected): ?>
            <div class="approval-info rejected">
                <div class="approval-item" style="color:#b3352f;">❌ Status: <strong>Rejected</strong></div>
                <div class="approval-item">Please contact HR for details.</div>
            </div>
            <?php endif; ?>

            <!-- Disclaimer -->
            <div class="disclaimer">
                <strong>Disclaimer:</strong> Neither BGS nor Rajvardhan Singh is responsible for any inadvertent error.<br>
                <strong>This document is electronically generated and is valid without a physical signature.</strong><br>
                BetterGrowth Solutions © <?php echo date('Y'); ?> | Compliance Agreement #<?php echo $complianceId; ?>
                <?php if($isApproved): ?>| ✅ Digitally Verified<?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-bar">
        <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Dashboard</a>
        <a href="joining-letter.php" class="btn btn-outline"><i class="fas fa-edit"></i> Edit Letter</a>
        <button class="btn btn-pdf" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
        <button class="btn btn-gold" onclick="window.open('joining-letter-pdf.php','_blank')"><i class="fas fa-file-pdf"></i> Download PDF</button>
    </div>

</body>
</html>