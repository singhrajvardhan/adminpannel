<?php
// /raj/agent/compliance.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$agentId = $_SESSION['agent_id'] ?? '';
$agent = getAgent($agentId);
if (!$agent) {
    header('Location: login.php');
    exit;
}

// Check if already submitted
$existingCheck = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$agentId]);
$alreadySubmitted = ($existingCheck && $existingCheck->num_rows > 0);
$existingData = $alreadySubmitted ? $existingCheck->fetch_assoc() : null;

// If already approved, show status with PDF option
if ($alreadySubmitted && ($existingData['status'] ?? '') === 'approved') {
    $showApproved = true;
}

// Handle signature submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadySubmitted) {
    $signatureData = $_POST['signature_data'] ?? '';
    
    // Check all 9 policy checkboxes
    $allChecked = true;
    for ($i = 1; $i <= 9; $i++) {
        if (($_POST['policy_' . $i] ?? '0') !== '1') {
            $allChecked = false;
            break;
        }
    }
    
    if (empty($signatureData) || $signatureData === 'data:,') {
        $error = "Please provide your signature.";
    } elseif (!$allChecked) {
        $error = "Please acknowledge ALL 9 policy sections before submitting.";
    } else {
        $result = executeQuery(
            "INSERT INTO compliance_agreements (agent_id, full_name, email, phone, address, photo_path, signature_data, ip_address, user_agent, agreed_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $agentId,
                $agent['full_name'],
                $agent['email'] ?? '',
                $agent['mobile'] ?? '',
                $agent['address'] ?? $agent['permanent_address'] ?? '',
                $agent['candidate_photo_path'] ?? '',
                $signatureData,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]
        );
        
        if ($result !== false) {
            $success = true;
            // Refresh
            $existingCheck = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$agentId]);
            $alreadySubmitted = true;
            if ($existingCheck && $existingCheck->num_rows > 0) {
                $existingData = $existingCheck->fetch_assoc();
            }
        } else {
            $error = "Error saving your agreement. Please try again.";
        }
    }
}

$name = $agent['full_name'] ?: 'N/A';
$email = $agent['email'] ?: 'N/A';
$phone = $agent['mobile'] ?: 'N/A';
$photoPath = $agent['candidate_photo_path'] ?? '';
$photoUrl = '';
if (!empty($photoPath)) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath;
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Agreement - <?php echo APP_NAME; ?></title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --info: #29538c;
            --radius: 16px; --radius-sm: 10px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f5f4f0; color:var(--ink); }

        .header {
            background:linear-gradient(135deg,var(--navy-950),var(--navy-800));
            color:#eef2fa; padding:14px 28px; display:flex;
            justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.15); position:sticky; top:0; z-index:100;
        }
        .header .brand{font-family:'Fraunces',serif; font-size:16px; font-weight:600;}
        .header a{color:#eef2fa; text-decoration:none; font-size:12px; padding:6px 14px; border-radius:20px;}
        .header a:hover{background:rgba(255,255,255,.1);}

        .content{max-width:900px; margin:0 auto; padding:20px 24px 40px;}

        .page-title{font-family:'Fraunces',serif; font-size:24px; color:var(--navy-950); text-align:center; margin-bottom:4px;}
        .page-subtitle{color:var(--ink-soft); font-size:13px; text-align:center; margin-bottom:20px;}

        .alert{padding:14px 20px; border-radius:var(--radius-sm); margin-bottom:16px; font-size:13px; line-height:1.5;}
        .alert-success{background:#e4f4e8; color:var(--success); border:1px solid var(--success);}
        .alert-error{background:#fbe7e7; color:var(--danger); border:1px solid var(--danger);}
        .alert-info{background:#e6eefb; color:var(--info); border:1px solid var(--info);}

        .employee-card{
            background:var(--paper); border-radius:var(--radius); padding:20px 24px;
            margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line);
            display:flex; align-items:center; gap:16px; flex-wrap:wrap;
        }
        .employee-card .photo{
            width:65px; height:65px; border-radius:50%; object-fit:cover;
            border:3px solid var(--gold-500); flex-shrink:0;
        }
        .employee-card .photo-placeholder{
            width:65px; height:65px; border-radius:50%; background:var(--cream);
            display:flex; align-items:center; justify-content:center;
            font-size:28px; color:#bbb; border:2px dashed var(--line); flex-shrink:0;
        }
        .employee-card .info h3{font-family:'Fraunces',serif; font-size:17px; color:var(--navy-950);}
        .employee-card .info span{font-size:11px; color:var(--ink-soft); display:block;}

        .policy-card{
            background:var(--paper); border-radius:var(--radius); padding:24px 28px;
            margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line);
            max-height:450px; overflow-y:auto;
        }
        .policy-card h2{font-family:'Fraunces',serif; font-size:19px; color:var(--navy-950); text-align:center; margin-bottom:4px;}
        .policy-card .meta{text-align:center; font-size:11px; color:var(--ink-soft); margin-bottom:18px;}
        .policy-card h3{
            font-size:14px; color:var(--navy-800); margin:16px 0 8px;
            padding-bottom:4px; border-bottom:2px solid var(--gold-500);
        }
        .policy-card ul{padding-left:18px; margin-bottom:10px;}
        .policy-card li{font-size:12px; line-height:1.7; margin-bottom:3px;}

        .checkbox-section{
            background:var(--paper); border-radius:var(--radius); padding:20px 24px;
            margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line);
        }
        .checkbox-section h3{font-family:'Fraunces',serif; font-size:16px; color:var(--navy-950); margin-bottom:12px;}
        .checkbox-item{
            display:flex; align-items:flex-start; gap:10px; padding:10px 14px;
            border:1px solid var(--line); border-radius:8px; margin-bottom:8px;
            cursor:pointer; transition:border-color .3s; background:var(--cream); font-size:12px;
        }
        .checkbox-item:hover{border-color:var(--gold-500);}
        .checkbox-item input[type="checkbox"]{width:18px; height:18px; accent-color:var(--gold-500); margin-top:2px; cursor:pointer; flex-shrink:0;}
        .checkbox-item.checked{background:#e4f4e8; border-color:var(--success);}
        .progress-text{font-size:11px; color:var(--ink-soft); text-align:center; margin-bottom:8px;}
        .progress-bar-wrap{background:#eee; border-radius:10px; height:6px; overflow:hidden; margin-bottom:6px;}
        .progress-bar-fill{height:100%; background:var(--success); border-radius:10px; transition:width .3s;}

        .sign-section{
            background:var(--paper); border-radius:var(--radius); padding:24px 28px;
            margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line);
        }
        .sign-section h3{font-family:'Fraunces',serif; font-size:16px; color:var(--navy-950); margin-bottom:12px;}
        .sign-pad{
            border:2px dashed var(--line); border-radius:10px; background:#fff;
            cursor:crosshair; width:100%; height:160px; margin-bottom:10px;
        }
        .sign-pad canvas{width:100%; height:100%; border-radius:10px;}

        .btn-row{display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:14px;}
        .btn{
            padding:11px 24px; border:none; border-radius:25px; font-weight:600; font-size:13px;
            cursor:pointer; font-family:'Inter',sans-serif; display:inline-flex; align-items:center; gap:6px;
            transition:transform .2s;
        }
        .btn:hover{transform:translateY(-1px);}
        .btn-gold{background:var(--gold-500); color:var(--navy-950);}
        .btn-outline{background:var(--cream); color:var(--ink-soft); border:2px solid var(--line);}
        .btn-pdf{background:#e74c3c; color:#fff;}
        .btn:disabled{opacity:.5; cursor:not-allowed;}

        .status-card{text-align:center; padding:30px; background:var(--paper); border-radius:var(--radius);}
        .status-badge{display:inline-block; padding:7px 20px; border-radius:20px; font-weight:700; font-size:13px;}
        .status-badge.pending{background:#fdf1de; color:#9a6a10;}
        .status-badge.approved{background:#e4f4e8; color:var(--success);}
        .status-badge.rejected{background:#fbe7e7; color:var(--danger);}

        .copyright-footer{text-align:center; padding:20px; margin-top:30px; border-top:1px solid var(--line); font-size:12px; color:var(--ink-soft);}
        .copyright-footer a{color:var(--gold-500); text-decoration:none; font-weight:600;}

        @media(max-width:768px){.content{padding:10px 12px 30px;}.policy-card{padding:16px;}}
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📋 Compliance Agreement</div>
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </header>

    <div class="content">
        <h1 class="page-title">Employee Compliance & Code of Conduct</h1>
        <p class="page-subtitle">Please read all policies, check each section, sign, and submit</p>

        <?php if (isset($success)): ?>
        <div class="alert alert-success">✅ Agreement submitted! Pending admin approval.</div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
        <div class="alert alert-error">❌ <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($alreadySubmitted): ?>
        <!-- STATUS VIEW -->
        <div class="status-card">
            <div style="font-size:60px;">
                <?php echo ($existingData['status']??'')==='approved'?'✅':(($existingData['status']??'')==='rejected'?'❌':'⏳'); ?>
            </div>
            <h2 style="font-family:'Fraunces',serif;margin:10px 0;">Agreement Status</h2>
            <div class="status-badge <?php echo $existingData['status']??'pending'; ?>">
                <?php echo ucfirst($existingData['status']??'pending'); ?>
            </div>
            <p style="font-size:12px;color:var(--ink-soft);margin:8px 0;">
                Submitted: <?php echo date('d M Y, h:i A', strtotime($existingData['agreed_at']??'now')); ?>
            </p>
            <?php if(($existingData['status']??'')==='approved'): ?>
                <p style="color:var(--success);font-size:12px;">
                    Approved: <?php echo date('d M Y', strtotime($existingData['approved_at']??'now')); ?>
                </p>
            <?php endif; ?>
            <button class="btn btn-pdf" onclick="window.open('compliance-pdf.php','_blank')">
                <i class="fas fa-file-pdf"></i> Download Signed PDF
            </button>
        </div>

        <?php else: ?>
        <!-- FORM VIEW -->
        
        <!-- Employee Info -->
        <div class="employee-card">
            <?php if(!empty($photoUrl)): ?>
                <img src="<?php echo $photoUrl; ?>" class="photo" alt="Photo">
            <?php else: ?>
                <div class="photo-placeholder">👤</div>
            <?php endif; ?>
            <div class="info">
                <h3><?php echo htmlspecialchars($name); ?></h3>
                <span><i class="fas fa-id-badge"></i> <?php echo $agentId; ?></span>
                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($email); ?></span>
                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone); ?></span>
            </div>
        </div>

        <!-- Policy Document -->
        <div class="policy-card">
            <h2>Employee Compliance & Code of Conduct Policy</h2>
            <p class="meta">Applicable to all Work-From-Home / Remote Agents</p>
            <h3>1. Working Hours & Attendance</h3>
            <ul><li>9 hours/day, 7:30 PM–4:30 AM IST, Mon–Fri. Sat–Sun off.</li><li>60 min paid break. Must inform manager before/after breaks.</li><li>Late/irregular login = policy violation.</li></ul>
            <h3>2. Technical & Workstation Requirements</h3>
            <ul><li>50–80 Mbps internet, power backup, quiet environment.</li><li>No personal phone, no unauthorized software/VPNs.</li></ul>
            <h3>3. Leave & Holiday Policy</h3>
            <ul><li>U.S. holidays followed. Indian festivals need HR approval.</li><li>30-day notice for resignation.</li></ul>
            <h3>4. Salary & Compensation</h3>
            <ul><li>Salary credited on 10th monthly (next working day if holiday).</li></ul>
            <h3>5. Monitoring & Screen Sharing</h3>
            <ul><li>Full screen sharing required. Calls/screens may be recorded.</li></ul>
            <h3>6. Customer Data Protection & Privacy</h3>
            <ul><li>No storing customer data on personal devices. No social media contact.</li></ul>
            <h3>7. Professional Conduct</h3>
            <ul><li>Respectful communication. No harassment, false promises, or deceptive sales.</li></ul>
            <h3>8. Confidentiality</h3>
            <ul><li>Company info confidential. Return all equipment upon separation.</li></ul>
            <h3>9. Disciplinary Action</h3>
            <ul><li>Violations may result in warnings, suspension, termination, or legal action.</li></ul>
             <ul><li>The Company reserves the right to terminate the employee’s employment at any time, with or without notice, subject to applicable law and the terms of the employment agreement. Termination may occur due to misconduct, policy violations, performance concerns, operational requirements, loss of business, or other legitimate reasons.

Unless otherwise required by applicable law or expressly provided in the employment agreement, no additional termination payment, severance pay, or compensation for termination will be payable by the Company. Any salary or other amounts legally due to the employee up to the effective date of termination will be settled in accordance with applicable law and Company policy</li></ul>
        </div>

        <!-- Checkboxes -->
        <div class="checkbox-section">
            <h3>📋 Acknowledge Each Policy Section</h3>
            <p class="progress-text">Checked: <span id="checkedCount">0</span>/9</p>
            <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progressBar" style="width:0%;"></div></div>
            
            <?php
            $policies = [
                1 => 'Working Hours & Attendance',
                2 => 'Technical & Workstation Requirements',
                3 => 'Leave & Holiday Policy',
                4 => 'Salary & Compensation',
                5 => 'Monitoring & Screen Sharing',
                6 => 'Customer Data Protection & Privacy',
                7 => 'Professional Conduct',
                8 => 'Confidentiality',
                9 => 'Disciplinary Action',
            ];
            foreach($policies as $num => $title): ?>
            <label class="checkbox-item" id="checkItem<?php echo $num; ?>">
                <input type="checkbox" name="policy_<?php echo $num; ?>" value="1" onchange="updateProgress()">
                <span><strong>Section <?php echo $num; ?>:</strong> I have read and agree to the <strong><?php echo $title; ?></strong> policy.</span>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Signature -->
        <div class="sign-section">
            <h3>✍️ Digital Signature</h3>
            <p style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">Draw your signature below using mouse or finger:</p>
            <div class="sign-pad"><canvas id="signatureCanvas"></canvas></div>
            <div class="btn-row">
                <button type="button" class="btn btn-outline" onclick="clearSignature()"><i class="fas fa-eraser"></i> Clear</button>
            </div>
            
            <form method="POST" id="complianceForm">
                <input type="hidden" name="signature_data" id="signatureData">
                <?php foreach($policies as $num => $title): ?>
                <input type="hidden" name="policy_<?php echo $num; ?>" id="hiddenPolicy<?php echo $num; ?>" value="0">
                <?php endforeach; ?>
                
                <div class="btn-row" style="margin-top:16px;">
                    <button type="submit" class="btn btn-gold" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Sign & Submit Agreement
                    </button>
                </div>
            </form>
        </div>

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <strong>Note:</strong> You can only submit once. After admin approval, you can download the signed PDF.
        </div>
        <?php endif; ?>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Progress tracker
        function updateProgress() {
            let checked = 0;
            for (let i = 1; i <= 9; i++) {
                const cb = document.querySelector('input[name="policy_' + i + '"]');
                const hidden = document.getElementById('hiddenPolicy' + i);
                const item = document.getElementById('checkItem' + i);
                
                if (cb && cb.checked) {
                    checked++;
                    if (hidden) hidden.value = '1';
                    if (item) item.classList.add('checked');
                } else {
                    if (hidden) hidden.value = '0';
                    if (item) item.classList.remove('checked');
                }
            }
            document.getElementById('checkedCount').textContent = checked;
            document.getElementById('progressBar').style.width = (checked / 9 * 100) + '%';
        }

        // Signature Pad
        const canvas = document.getElementById('signatureCanvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            let drawing = false;
            function resizeCanvas() {
                const pad = canvas.parentElement;
                canvas.width = pad.offsetWidth;
                canvas.height = pad.offsetHeight;
                ctx.strokeStyle = '#1c2536';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
            function getPos(e) {
                const rect = canvas.getBoundingClientRect();
                const cx = e.touches ? e.touches[0].clientX : e.clientX;
                const cy = e.touches ? e.touches[0].clientY : e.clientY;
                return { x: cx - rect.left, y: cy - rect.top };
            }
            function start(e) { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
            function move(e) { if (!drawing) return; e.preventDefault(); const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
            function stop() { drawing = false; document.getElementById('signatureData').value = canvas.toDataURL(); }
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', stop);
            canvas.addEventListener('mouseleave', stop);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', stop);
        }
        function clearSignature() {
            const c = document.getElementById('signatureCanvas');
            c.getContext('2d').clearRect(0, 0, c.width, c.height);
            document.getElementById('signatureData').value = '';
        }

        // Form validation
        document.getElementById('complianceForm')?.addEventListener('submit', function(e) {
            const sig = document.getElementById('signatureData').value;
            let allChecked = true;
            for (let i = 1; i <= 9; i++) {
                if (!document.querySelector('input[name="policy_' + i + '"]').checked) {
                    allChecked = false;
                    break;
                }
            }
            
            if (!allChecked) {
                e.preventDefault();
                alert('Please check ALL 9 policy sections before submitting.');
                return;
            }
            if (!sig || sig === 'data:,') {
                e.preventDefault();
                alert('Please draw your signature.');
                return;
            }
            if (!confirm('⚠️ You can only submit ONCE. Continue?')) {
                e.preventDefault();
                return;
            }
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
    </script>
</body>
</html>