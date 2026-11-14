<?php
// /raj/admin/joining-letter-setup.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';
$message = '';
$msgType = 'success';

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Create new joining letter
    if ($action === 'create_letter') {
        $agentId = $_POST['agent_id'] ?? '';
        $joiningDate = $_POST['joining_date'] ?? date('Y-m-d');
        $designation = trim($_POST['designation'] ?? 'Sales Executive');
        $department = trim($_POST['department'] ?? 'Sales');
        $salary = (float)($_POST['salary'] ?? 13000);
        $shiftType = $_POST['shift_type'] ?? 'night';
        $workLocation = $_POST['work_location'] ?? 'home';
        
        if (empty($agentId)) {
            $message = "Please select an agent.";
            $msgType = 'error';
        } else {
            $agent = getAgent($agentId);
            if (!$agent) {
                $message = "Agent not found.";
                $msgType = 'error';
            } else {
                $existing = executeQuery("SELECT id FROM joining_letters WHERE agent_id = ?", [$agentId]);
                if ($existing && $existing->num_rows > 0) {
                    $message = "Joining letter already exists for this agent.";
                    $msgType = 'error';
                } else {
                    executeQuery("UPDATE agents SET joining_date = ?, designation = ?, department = ?, salary = ?, shift_type = ?, work_location = ? WHERE agent_id = ?",
                        [$joiningDate, $designation, $department, $salary, $shiftType, $workLocation, $agentId]);
                    
                    $stampQuery = executeQuery("SELECT setting_value FROM company_settings WHERE setting_key = 'company_stamp'");
                    $stampPath = ($stampQuery && $stampQuery->num_rows > 0) ? $stampQuery->fetch_assoc()['setting_value'] : '';
                    
                    executeQuery(
                        "INSERT INTO joining_letters (agent_id, full_name, email, phone, photo_path, joining_date, designation, department, salary, shift_type, work_location, company_stamp_path, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                        [$agentId, $agent['full_name'], $agent['email'] ?? '', $agent['mobile'] ?? '',
                         $agent['candidate_photo_path'] ?? '', $joiningDate, $designation, $department,
                         $salary, $shiftType, $workLocation, $stampPath]
                    );
                    $message = "✅ Joining letter created!";
                    $msgType = 'success';
                }
            }
        }
    }
    
    // Approve letter
    if ($action === 'approve_letter') {
        $agentId = $_POST['agent_id'] ?? '';
        executeQuery("UPDATE joining_letters SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE agent_id = ?", [$adminId, $agentId]);
        $message = "✅ Letter approved!";
        $msgType = 'success';
    }
    
    // Reject letter
    if ($action === 'reject_letter') {
        $agentId = $_POST['agent_id'] ?? '';
        $notes = trim($_POST['reject_notes'] ?? '');
        executeQuery("UPDATE joining_letters SET status = 'rejected', admin_notes = ? WHERE agent_id = ?", [$notes, $agentId]);
        $message = "❌ Letter rejected.";
        $msgType = 'error';
    }
    
    // Update joining date
    if ($action === 'update_date') {
        $agentId = $_POST['agent_id'] ?? '';
        $newDate = $_POST['new_joining_date'] ?? '';
        if (!empty($agentId) && !empty($newDate)) {
            executeQuery("UPDATE joining_letters SET joining_date = ? WHERE agent_id = ?", [$newDate, $agentId]);
            executeQuery("UPDATE agents SET joining_date = ? WHERE agent_id = ?", [$newDate, $agentId]);
            $message = "✅ Joining date updated!";
            $msgType = 'success';
        }
    }
    
    // Upload stamp
    if ($action === 'upload_stamp' && isset($_FILES['stamp_file']) && $_FILES['stamp_file']['error'] === 0) {
        $uploadDir = __DIR__ . '/../storage/company/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = pathinfo($_FILES['stamp_file']['name'], PATHINFO_EXTENSION);
        $fileName = 'company_stamp.' . $ext;
        $targetPath = $uploadDir . $fileName;
        
        foreach (glob($uploadDir . 'company_stamp.*') as $oldFile) @unlink($oldFile);
        
        if (move_uploaded_file($_FILES['stamp_file']['tmp_name'], $targetPath)) {
            $stampUrl = '/raj/storage/company/' . $fileName;
            executeQuery("INSERT INTO company_settings (setting_key, setting_value) VALUES ('company_stamp', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$stampUrl, $stampUrl]);
            executeQuery("UPDATE joining_letters SET company_stamp_path = ? WHERE company_stamp_path IS NULL OR company_stamp_path = ''", [$stampUrl]);
            $message = "✅ Stamp uploaded!";
            $msgType = 'success';
        } else {
            $message = "❌ Upload failed.";
            $msgType = 'error';
        }
    }
}

// ============================================================
// GET DATA
// ============================================================
$agentsWithout = executeQuery("
    SELECT a.* FROM agents a 
    LEFT JOIN joining_letters jl ON a.agent_id = jl.agent_id 
    WHERE a.onboarding_completed = 1 AND jl.id IS NULL
    ORDER BY a.full_name
");

$allLetters = executeQuery("
    SELECT jl.*, 
           a.candidate_photo_path as agent_photo,
           ca.id as compliance_id,
           ca.status as compliance_status,
           ca.signature_data as compliance_signature
    FROM joining_letters jl
    LEFT JOIN agents a ON jl.agent_id = a.agent_id
    LEFT JOIN compliance_agreements ca ON jl.agent_id = ca.agent_id
    ORDER BY jl.created_at DESC
");

$stampQuery = executeQuery("SELECT setting_value FROM company_settings WHERE setting_key = 'company_stamp'");
$currentStamp = ($stampQuery && $stampQuery->num_rows > 0) ? $stampQuery->fetch_assoc()['setting_value'] : '';

$pendingCount = 0;
$approvedCount = 0;
$lettersList = [];
if ($allLetters) {
    while ($row = $allLetters->fetch_assoc()) {
        $lettersList[] = $row;
        if ($row['status'] === 'pending') $pendingCount++;
        if ($row['status'] === 'approved') $approvedCount++;
    }
}

// View single letter detail
$viewAgent = $_GET['view'] ?? '';
$viewLetter = null;
$viewCompliance = null;
if (!empty($viewAgent)) {
    $vl = executeQuery("SELECT * FROM joining_letters WHERE agent_id = ?", [$viewAgent]);
    if ($vl && $vl->num_rows > 0) $viewLetter = $vl->fetch_assoc();
    
    $vc = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$viewAgent]);
    if ($vc && $vc->num_rows > 0) $viewCompliance = $vc->fetch_assoc();
    
    // Agent photo
    $agentData = getAgent($viewAgent);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Joining Letters - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 16px; --radius-sm: 10px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,.05);
            --shadow-md: 0 8px 24px rgba(0,0,0,.1);
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f5f4f0;color:var(--ink);}
        .header{
            background:linear-gradient(135deg,#1a0a0a,#3d1515); color:#fce8e8;
            padding:14px 28px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.2);
        }
        .header .brand{font-family:'Fraunces',serif; font-size:17px; font-weight:600;}
        .header .nav{display:flex; gap:6px; flex-wrap:wrap;}
        .header .nav a{
            color:#fce8e8; text-decoration:none; padding:6px 14px; border-radius:20px;
            font-size:12px; transition:background .2s;
        }
        .header .nav a:hover,.header .nav a.active{background:rgba(255,255,255,.12);}
        .header .nav a.active{color:var(--gold-300); font-weight:600;}

        .content{max-width:1300px; margin:0 auto; padding:22px 28px 40px;}
        .page-title{font-family:'Fraunces',serif; font-size:24px; color:var(--navy-950); margin-bottom:4px;}
        
        .alert{padding:14px 18px; border-radius:var(--radius-sm); margin-bottom:16px; font-size:13px;}
        .alert-success{background:#e4f4e8; color:var(--success); border:1px solid var(--success);}
        .alert-error{background:#fbe7e7; color:var(--danger); border:1px solid var(--danger);}

        .stats-row{display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:18px;}
        .stat-box{background:var(--paper); border-radius:var(--radius); padding:16px; text-align:center; box-shadow:var(--shadow-sm); border:1px solid var(--line);}
        .stat-box .sv{font-family:'Fraunces',serif; font-size:28px; font-weight:700;}
        .stat-box .sl{font-size:10px; color:var(--ink-soft); text-transform:uppercase;}

        .grid-2{display:grid; grid-template-columns:1fr 1fr; gap:20px;}
        .card{background:var(--paper); border-radius:var(--radius); padding:22px; box-shadow:var(--shadow-sm); border:1px solid var(--line); margin-bottom:20px;}
        .card h3{font-family:'Fraunces',serif; font-size:17px; color:var(--navy-950); margin-bottom:16px; display:flex; align-items:center; gap:8px;}
        .card h3 i{color:var(--gold-500);}
        
        .form-group{margin-bottom:14px;}
        .form-group label{display:block; font-size:11px; font-weight:600; color:var(--ink-soft); text-transform:uppercase; margin-bottom:5px; letter-spacing:.5px;}
        .form-group input,.form-group select{width:100%; padding:10px 14px; border:2px solid var(--line); border-radius:8px; font-family:'Inter',sans-serif; font-size:13px;}
        .form-group input:focus,.form-group select:focus{border-color:var(--gold-500); outline:none;}
        .form-row{display:grid; grid-template-columns:1fr 1fr; gap:10px;}
        
        .btn{padding:10px 24px; border:none; border-radius:25px; font-weight:600; font-size:12px; cursor:pointer; font-family:'Inter',sans-serif; transition:transform .2s; display:inline-flex; align-items:center; gap:6px;}
        .btn:hover{transform:translateY(-1px);}
        .btn-gold{background:var(--gold-500); color:var(--navy-950);}
        .btn-primary{background:var(--navy-800); color:#fff;}
        .btn-success{background:var(--success); color:#fff;}
        .btn-danger{background:var(--danger); color:#fff;}
        .btn-outline{background:var(--cream); color:var(--ink-soft); border:2px solid var(--line);}
        .btn-xs{padding:4px 10px; font-size:10px; border-radius:15px; text-decoration:none;}
        
        table{width:100%; border-collapse:collapse; font-size:12px;}
        th{background:var(--navy-800); color:#fff; padding:10px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.5px;}
        td{padding:10px; border-bottom:1px solid var(--line);}
        tr:hover{background:var(--cream);}
        tr.pending-row{background:#fffdf5;}
        
        .status-badge{padding:4px 12px; border-radius:12px; font-size:10px; font-weight:600; display:inline-block;}
        .status-badge.pending{background:#fdf1de; color:var(--warn);}
        .status-badge.approved{background:#e4f4e8; color:var(--success);}
        .status-badge.rejected{background:#fbe7e7; color:var(--danger);}
        
        .comp-badge{padding:3px 10px; border-radius:10px; font-size:9px; font-weight:600;}
        .comp-badge.done{background:#e4f4e8; color:var(--success);}
        .comp-badge.pending{background:#fdf1de; color:var(--warn);}
        .comp-badge.none{background:#f5f5f5; color:#999;}

        .stamp-preview{max-width:120px; border:1px solid var(--line); padding:5px; border-radius:8px;}

        /* View Detail Modal */
        .detail-panel{
            background:var(--paper); border-radius:var(--radius); padding:30px 35px;
            box-shadow:var(--shadow-md); border:2px solid #d4c5a0; margin-bottom:20px;
            position:relative;
        }
        .detail-panel .close-btn{
            position:absolute; top:14px; right:14px; background:var(--danger); color:#fff;
            width:30px; height:30px; border-radius:50%; border:none; cursor:pointer; font-size:14px;
        }
        .detail-header{
            display:flex; justify-content:space-between; align-items:flex-start;
            border-bottom:2px solid var(--gold-500); padding-bottom:14px; margin-bottom:16px;
            flex-wrap:wrap; gap:16px;
        }
        .detail-header .company{text-align:right;}
        .detail-header .company h2{font-family:'Fraunces',serif; font-size:20px; color:var(--navy-950);}
        .detail-header .company p{font-size:10px; color:var(--ink-soft);}
        .detail-table{width:100%; border-collapse:collapse; font-size:12px; margin:12px 0;}
        .detail-table td{padding:6px 10px; border:1px solid #e0e0e0;}
        .detail-table td:first-child{font-weight:600; background:#fafafa; width:160px; font-size:10px; text-transform:uppercase; color:var(--ink-soft);}
        
        .sign-preview{text-align:center; margin:16px 0;}
        .sign-preview img{max-width:200px; max-height:80px; border:1px solid #ddd; padding:8px; border-radius:8px;}
        .sign-preview .label{font-size:10px; color:var(--ink-soft); margin-top:4px;}

        .copyright-footer{text-align:center; padding:20px; margin-top:30px; border-top:1px solid var(--line); font-size:12px; color:var(--ink-soft);}
        .copyright-footer a{color:var(--gold-500); text-decoration:none; font-weight:600;}

        @media(max-width:768px){.grid-2{grid-template-columns:1fr;}.content{padding:12px;}}
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📝 Joining Letter Management</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="joining-letter-setup.php" class="active">Joining Letters</a>
            <a href="agents-manage.php">Agents</a>
            <a href="compliance-manage.php">Compliance</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        
        <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box"><div class="sv" style="color:var(--warn);"><?php echo $pendingCount; ?></div><div class="sl">Pending</div></div>
            <div class="stat-box"><div class="sv" style="color:var(--success);"><?php echo $approvedCount; ?></div><div class="sl">Approved</div></div>
            <div class="stat-box"><div class="sv"><?php echo count($lettersList); ?></div><div class="sl">Total Letters</div></div>
        </div>

        <?php if($viewLetter): ?>
        <!-- ========== VIEW SINGLE LETTER DETAIL ========== -->
        <div class="detail-panel" id="letterDetail">
            <button class="close-btn" onclick="window.location.href='joining-letter-setup.php'">×</button>
            
            <div class="detail-header">
                <div>
                    <div style="font-size:10px;color:var(--ink-soft);">Ref: BGS/JL/<?php echo date('Y'); ?>/<?php echo $viewAgent; ?></div>
                    <div style="font-size:10px;color:var(--ink-soft);">Date: <?php echo date('d M Y', strtotime($viewLetter['joining_date'] ?? 'now')); ?></div>
                </div>
                <div class="company">
                    <h2>Better Growth Solutions</h2>
                    <p>Cosmos City, Bicholi Mardana, Indore – 452016</p>
                </div>
            </div>

            <h3 style="text-align:center;color:var(--navy-950);margin-bottom:14px;">APPOINTMENT LETTER</h3>

            <table class="detail-table">
                <tr><td>Employee Name</td><td><strong><?php echo htmlspecialchars($viewLetter['full_name']); ?></strong></td></tr>
                <tr><td>Agent ID</td><td><?php echo $viewAgent; ?></td></tr>
                <tr><td>Email</td><td><?php echo htmlspecialchars($viewLetter['email'] ?? ''); ?></td></tr>
                <tr><td>Phone</td><td><?php echo htmlspecialchars($viewLetter['phone'] ?? ''); ?></td></tr>
                <tr><td>Joining Date</td><td><strong><?php echo date('d M Y', strtotime($viewLetter['joining_date'])); ?></strong></td></tr>
                <tr><td>Designation</td><td><?php echo htmlspecialchars($viewLetter['designation']); ?></td></tr>
                <tr><td>Department</td><td><?php echo htmlspecialchars($viewLetter['department']); ?></td></tr>
                <tr><td>Salary</td><td><strong>₹<?php echo number_format($viewLetter['salary'], 0); ?>/month</strong></td></tr>
                <tr><td>Shift</td><td><?php echo $viewLetter['shift_type'] === 'night' ? 'Night (7:30 PM–4:30 AM)' : 'Day'; ?></td></tr>
                <tr><td>Location</td><td><?php echo $viewLetter['work_location'] === 'home' ? 'Work From Home' : 'Office'; ?></td></tr>
                <tr><td>Status</td><td><span class="status-badge <?php echo $viewLetter['status']; ?>"><?php echo ucfirst($viewLetter['status']); ?></span></td></tr>
                <tr><td>Compliance</td><td>
                    <?php if($viewCompliance): ?>
                        <span class="comp-badge <?php echo $viewCompliance['status'] === 'approved' ? 'done' : 'pending'; ?>">
                            #<?php echo $viewCompliance['id']; ?> - <?php echo ucfirst($viewCompliance['status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="comp-badge none">Not Signed</span>
                    <?php endif; ?>
                </td></tr>
            </table>

            <!-- Agent Signature -->
            <div style="display:flex; gap:30px; margin-top:20px; flex-wrap:wrap;">
                <div class="sign-preview" style="flex:1;">
                    <strong style="font-size:12px;display:block;margin-bottom:8px;">✍️ Agent Signature</strong>
                    <?php if(!empty($viewLetter['signature_data'])): ?>
                        <img src="<?php echo $viewLetter['signature_data']; ?>" alt="Agent Signature">
                        <div class="label">Signed: <?php echo date('d M Y, h:i A', strtotime($viewLetter['agreed_at'] ?? 'now')); ?></div>
                    <?php else: ?>
                        <div style="padding:20px;border:1px dashed #ccc;border-radius:8px;color:#999;font-size:11px;">Not signed yet</div>
                    <?php endif; ?>
                </div>
                
                <div class="sign-preview" style="flex:1;">
                    <strong style="font-size:12px;display:block;margin-bottom:8px;">🏢 Company Stamp</strong>
                    <?php if(!empty($currentStamp)): ?>
                        <img src="<?php echo $currentStamp; ?>" alt="Company Stamp">
                    <?php else: ?>
                        <div style="padding:20px;border:1px dashed #ccc;border-radius:8px;color:#999;font-size:11px;">Stamp not uploaded</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Compliance Signature -->
            <?php if($viewCompliance && !empty($viewCompliance['signature_data'])): ?>
            <div class="sign-preview" style="margin-top:16px;">
                <strong style="font-size:12px;display:block;margin-bottom:8px;">📋 Compliance Signature</strong>
                <img src="<?php echo $viewCompliance['signature_data']; ?>" alt="Compliance Signature">
                <div class="label">Compliance Agreement #<?php echo $viewCompliance['id']; ?></div>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;justify-content:center;">
                <a href="../agent/joining-letter-pdf.php?agent=<?php echo $viewAgent; ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> View PDF
                </a>
                <?php if($viewLetter['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="approve_letter">
                    <input type="hidden" name="agent_id" value="<?php echo $viewAgent; ?>">
                    <button type="submit" class="btn btn-success">✓ Approve Letter</button>
                </form>
                <button class="btn btn-danger" onclick="rejectLetter('<?php echo $viewAgent; ?>')">✗ Reject</button>
                <?php endif; ?>
                <button class="btn btn-outline" onclick="editDate('<?php echo $viewAgent; ?>', '<?php echo $viewLetter['joining_date']; ?>')">
                    <i class="fas fa-calendar"></i> Edit Joining Date
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- Create Letter -->
            <div class="card">
                <h3><i class="fas fa-plus-circle"></i> Create Joining Letter</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create_letter">
                    <div class="form-group">
                        <label>Select Agent *</label>
                        <select name="agent_id" required>
                            <option value="">-- Choose Agent --</option>
                            <?php if($agentsWithout): while($a = $agentsWithout->fetch_assoc()): ?>
                            <option value="<?php echo $a['agent_id']; ?>"><?php echo htmlspecialchars($a['full_name']); ?> (<?php echo $a['agent_id']; ?>)</option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Joining Date *</label><input type="date" name="joining_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                        <div class="form-group"><label>Salary (₹/month)</label><input type="number" name="salary" value="13000" step="500"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Designation</label><input type="text" name="designation" value="Sales Executive"></div>
                        <div class="form-group"><label>Department</label><input type="text" name="department" value="Sales"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Shift</label><select name="shift_type"><option value="night">🌙 Night</option><option value="day">☀️ Day</option></select></div>
                        <div class="form-group"><label>Location</label><select name="work_location"><option value="home">🏠 WFH</option><option value="office">🏢 Office</option></select></div>
                    </div>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-file-alt"></i> Create Letter</button>
                </form>
            </div>

            <!-- Upload Stamp -->
            <div class="card">
                <h3><i class="fas fa-stamp"></i> Company Stamp</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_stamp">
                    <div class="form-group"><label>Upload Stamp (PNG/JPG)</label><input type="file" name="stamp_file" accept="image/*" required></div>
                    <?php if(!empty($currentStamp)): ?>
                    <p style="font-size:12px;color:var(--success);">✅ Current:</p>
                    <img src="<?php echo $currentStamp; ?>" class="stamp-preview" alt="Stamp">
                    <?php else: ?>
                    <p style="font-size:12px;color:var(--danger);">⚠️ No stamp</p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-upload"></i> Upload</button>
                </form>
            </div>
        </div>

        <!-- All Letters Table -->
        <div class="card">
            <h3><i class="fas fa-list"></i> All Joining Letters</h3>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Joining Date</th>
                        <th>Salary</th>
                        <th>Signed</th>
                        <th>Compliance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($lettersList as $l): ?>
                    <tr class="<?php echo $l['status']==='pending'?'pending-row':''; ?>">
                        <td><strong><?php echo htmlspecialchars($l['full_name']); ?></strong><br><small><?php echo $l['agent_id']; ?></small></td>
                        <td><?php echo date('d M Y', strtotime($l['joining_date'])); ?></td>
                        <td>₹<?php echo number_format($l['salary'], 0); ?></td>
                        <td><?php echo !empty($l['signature_data']) ? '✅' : '❌'; ?></td>
                        <td>
                            <?php if($l['compliance_id']): ?>
                                <span class="comp-badge <?php echo $l['compliance_status']==='approved'?'done':'pending'; ?>">
                                    #<?php echo $l['compliance_id']; ?>
                                </span>
                            <?php else: ?>
                                <span class="comp-badge none">None</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-badge <?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                        <td>
                            <a href="?view=<?php echo $l['agent_id']; ?>" class="btn btn-gold btn-xs">👁 View</a>
                            <a href="../agent/joining-letter-pdf.php?agent=<?php echo $l['agent_id']; ?>" target="_blank" class="btn btn-primary btn-xs">📄 PDF</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($lettersList)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--ink-soft);">No letters created yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:16px;padding:24px;max-width:400px;width:92%;">
            <h3 style="font-family:'Fraunces',serif;">Reject Letter</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reject_letter">
                <input type="hidden" name="agent_id" id="rejectAgentId">
                <div class="form-group"><label>Reason (optional)</label><textarea name="reject_notes" rows="2" style="width:100%;padding:8px;border:2px solid #ecebe6;border-radius:8px;"></textarea></div>
                <div style="display:flex;gap:8px;margin-top:12px;">
                    <button type="submit" class="btn btn-danger">✗ Reject</button>
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectModal').style.display='none'">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Date Modal -->
    <div id="editDateModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:16px;padding:24px;max-width:400px;width:92%;">
            <h3 style="font-family:'Fraunces',serif;">Edit Joining Date</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_date">
                <input type="hidden" name="agent_id" id="editDateAgentId">
                <div class="form-group"><label>New Joining Date</label><input type="date" name="new_joining_date" id="editDateInput" required></div>
                <div style="display:flex;gap:8px;margin-top:12px;">
                    <button type="submit" class="btn btn-gold">💾 Save</button>
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('editDateModal').style.display='none'">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        function rejectLetter(agentId) {
            document.getElementById('rejectAgentId').value = agentId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        function editDate(agentId, currentDate) {
            document.getElementById('editDateAgentId').value = agentId;
            document.getElementById('editDateInput').value = currentDate;
            document.getElementById('editDateModal').style.display = 'flex';
        }
        // Close modals on outside click
        document.querySelectorAll('[id$="Modal"]').forEach(m => {
            m.addEventListener('click', function(e) { if(e.target === this) this.style.display = 'none'; });
        });
    </script>
</body>
</html>