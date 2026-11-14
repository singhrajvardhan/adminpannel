<?php
// /raj/admin/admin-joining-letter.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Admin authentication
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin-login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? '';
$adminName = $_SESSION['admin_name'] ?? 'Admin';

// Get all joining letters with agent details
// REMOVED: c.signed_at as compliance_signed_at - this column doesn't exist
$lettersQuery = executeQuery("
    SELECT 
        jl.*,
        a.full_name,
        a.email,
        a.mobile,
        a.agent_id,
        a.candidate_photo_path,
        c.id as compliance_id,
        c.status as compliance_status,
        c.agreed_at as compliance_agreed_at
    FROM joining_letters jl
    LEFT JOIN agents a ON jl.agent_id = a.agent_id
    LEFT JOIN compliance_agreements c ON jl.agent_id = c.agent_id
    ORDER BY jl.created_at DESC
");

$letters = [];
if ($lettersQuery && $lettersQuery->num_rows > 0) {
    while ($row = $lettersQuery->fetch_assoc()) {
        $letters[] = $row;
    }
}

// Handle actions
$actionResult = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $letterId = $_POST['letter_id'] ?? 0;
    $agentId = $_POST['agent_id'] ?? '';
    
    if ($action === 'approve') {
        // Approve joining letter
        $salaryRevised = isset($_POST['salary_revised']) ? floatval($_POST['salary_revised']) : 0;
        $salaryRevisionReason = $_POST['salary_revision_reason'] ?? '';
        $adminComment = $_POST['admin_comment'] ?? '';
        $performanceRemarks = $_POST['performance_remarks'] ?? '';
        
        if ($salaryRevised > 0) {
            // Update with salary revision
            $updateResult = executeQuery(
                "UPDATE joining_letters SET 
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    salary_revised = ?,
                    salary_revision_reason = ?,
                    salary_revision_date = NOW(),
                    admin_comment = ?,
                    performance_remarks = ?
                WHERE id = ? AND agent_id = ?",
                [$adminName, $salaryRevised, $salaryRevisionReason, $adminComment, $performanceRemarks, $letterId, $agentId]
            );
        } else {
            // Approve without salary revision
            $updateResult = executeQuery(
                "UPDATE joining_letters SET 
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    admin_comment = ?,
                    performance_remarks = ?
                WHERE id = ? AND agent_id = ?",
                [$adminName, $adminComment, $performanceRemarks, $letterId, $agentId]
            );
        }
        
        if ($updateResult !== false) {
            $actionResult = "✅ Joining letter approved successfully!";
            header('Location: admin-joining-letter.php?success=approved');
            exit;
        } else {
            $actionError = "Failed to approve joining letter. Please try again.";
        }
    } elseif ($action === 'reject') {
        $rejectionReason = $_POST['rejection_reason'] ?? '';
        $adminComment = $_POST['admin_comment'] ?? '';
        
        $updateResult = executeQuery(
            "UPDATE joining_letters SET 
                status = 'rejected',
                admin_comment = ?,
                rejection_reason = ?,
                rejected_at = NOW(),
                rejected_by = ?
            WHERE id = ? AND agent_id = ?",
            [$adminComment, $rejectionReason, $adminName, $letterId, $agentId]
        );
        
        if ($updateResult !== false) {
            $actionResult = "❌ Joining letter rejected.";
            header('Location: admin-joining-letter.php?success=rejected');
            exit;
        } else {
            $actionError = "Failed to reject joining letter. Please try again.";
        }
    } elseif ($action === 'update_salary') {
        // Update salary revision only
        $salaryRevised = floatval($_POST['salary_revised'] ?? 0);
        $salaryRevisionReason = $_POST['salary_revision_reason'] ?? '';
        $adminComment = $_POST['admin_comment'] ?? '';
        
        if ($salaryRevised > 0) {
            $updateResult = executeQuery(
                "UPDATE joining_letters SET 
                    salary_revised = ?,
                    salary_revision_reason = ?,
                    salary_revision_date = NOW(),
                    admin_comment = ?
                WHERE id = ? AND agent_id = ?",
                [$salaryRevised, $salaryRevisionReason, $adminComment, $letterId, $agentId]
            );
            
            if ($updateResult !== false) {
                $actionResult = "✅ Salary revised successfully!";
                header('Location: admin-joining-letter.php?success=salary_updated');
                exit;
            } else {
                $actionError = "Failed to update salary. Please try again.";
            }
        } else {
            $actionError = "Please enter a valid revised salary amount.";
        }
    } elseif ($action === 'delete') {
        $deleteResult = executeQuery(
            "DELETE FROM joining_letters WHERE id = ? AND agent_id = ?",
            [$letterId, $agentId]
        );
        
        if ($deleteResult !== false) {
            $actionResult = "🗑️ Joining letter deleted.";
            header('Location: admin-joining-letter.php?success=deleted');
            exit;
        } else {
            $actionError = "Failed to delete joining letter.";
        }
    }
}

// Get success/error messages from URL
$successMsg = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'approved': $successMsg = '✅ Joining letter approved successfully!'; break;
        case 'rejected': $successMsg = '❌ Joining letter rejected.'; break;
        case 'salary_updated': $successMsg = '✅ Salary revised successfully!'; break;
        case 'deleted': $successMsg = '🗑️ Joining letter deleted.'; break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Joining Letters Management</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0a1628;
            --navy-800: #163063;
            --navy-700: #1a3d7a;
            --gold-500: #c9962f;
            --gold-400: #dcae4d;
            --gold-100: #fdf1de;
            --cream: #f8f7f3;
            --paper: #ffffff;
            --ink: #1a2332;
            --ink-soft: #5a6a7e;
            --ink-light: #8a9aae;
            --line: #ecebe6;
            --success: #1e7a41;
            --danger: #b3352f;
            --warn: #9a6a10;
            --info: #29538c;
            --radius: 16px;
            --radius-sm: 10px;
            --shadow-sm: 0 2px 8px rgba(10,22,40,.06);
            --shadow-md: 0 8px 24px rgba(10,22,40,.10);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Inter',sans-serif;
            background:#f0eee8;
            color:var(--ink);
        }

        /* ===== HEADER ===== */
        .header {
            background:linear-gradient(135deg,var(--navy-950),var(--navy-800));
            color:#eef2fa;
            padding:12px 28px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.15);
            position:sticky;
            top:0;
            z-index:100;
        }
        .header-left {
            display:flex;
            align-items:center;
            gap:14px;
        }
        .header-logo {
            height:40px;
            width:auto;
            border-radius:8px;
            background:rgba(255,255,255,.08);
            padding:4px 10px;
        }
        .header-logo img {
            height:100%;
            width:auto;
        }
        .header-brand {
            font-family:'Fraunces',serif;
            font-size:18px;
            font-weight:700;
            color:#f5e7c8;
        }
        .header-brand span { color:var(--gold-400); }
        .header-brand small {
            font-size:11px;
            font-weight:400;
            color:#aab4cc;
            font-family:'Inter',sans-serif;
            margin-left:6px;
        }
        .header-nav {
            display:flex;
            gap:4px;
            flex-wrap:wrap;
        }
        .header-nav a {
            color:#eef2fa;
            text-decoration:none;
            padding:6px 16px;
            border-radius:20px;
            font-size:12px;
            font-weight:500;
            transition:all .25s;
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:rgba(255,255,255,.04);
        }
        .header-nav a:hover {
            background:rgba(255,255,255,.12);
            transform:translateY(-1px);
        }
        .header-nav a.active {
            background:var(--gold-500);
            color:var(--navy-950);
        }

        /* ===== CONTENT ===== */
        .content {
            max-width:1400px;
            margin:0 auto;
            padding:20px 24px 40px;
        }

        /* ===== ALERTS ===== */
        .alert {
            padding:14px 22px;
            border-radius:var(--radius-sm);
            margin-bottom:16px;
            font-size:13px;
            line-height:1.6;
            display:flex;
            align-items:center;
            gap:12px;
        }
        .alert-success { background:#e4f4e8; color:var(--success); border:1px solid var(--success); }
        .alert-error { background:#fbe7e7; color:var(--danger); border:1px solid var(--danger); }
        .alert-info { background:#e6eefb; color:var(--info); border:1px solid var(--info); }

        /* ===== STATS ===== */
        .stats-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(180px,1fr));
            gap:16px;
            margin-bottom:24px;
        }
        .stat-card {
            background:var(--paper);
            border-radius:var(--radius-sm);
            padding:18px 20px;
            border:1px solid var(--line);
            text-align:center;
        }
        .stat-card .stat-number {
            font-size:28px;
            font-weight:800;
            color:var(--navy-800);
            display:block;
        }
        .stat-card .stat-label {
            font-size:12px;
            color:var(--ink-soft);
            margin-top:2px;
        }
        .stat-card.pending .stat-number { color:var(--warn); }
        .stat-card.approved .stat-number { color:var(--success); }
        .stat-card.rejected .stat-number { color:var(--danger); }

        /* ===== TABLE ===== */
        .table-container {
            background:var(--paper);
            border-radius:var(--radius);
            padding:20px;
            border:1px solid var(--line);
            overflow-x:auto;
            box-shadow:var(--shadow-sm);
        }
        .table-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:16px;
        }
        .table-header h2 {
            font-family:'Fraunces',serif;
            font-size:20px;
            color:var(--navy-950);
        }
        .table-header .search-box {
            display:flex;
            gap:8px;
            align-items:center;
            flex-wrap:wrap;
        }
        .table-header .search-box input {
            padding:8px 16px;
            border:1px solid var(--line);
            border-radius:20px;
            font-size:13px;
            font-family:'Inter',sans-serif;
            outline:none;
            min-width:200px;
        }
        .table-header .search-box input:focus {
            border-color:var(--gold-500);
        }
        .table-header .search-box select {
            padding:8px 16px;
            border:1px solid var(--line);
            border-radius:20px;
            font-size:13px;
            font-family:'Inter',sans-serif;
            outline:none;
            background:var(--paper);
            cursor:pointer;
        }

        table {
            width:100%;
            border-collapse:collapse;
            font-size:13px;
        }
        table th {
            background:var(--cream);
            padding:12px 14px;
            text-align:left;
            font-weight:600;
            color:var(--ink-soft);
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:0.5px;
            border-bottom:2px solid var(--line);
            white-space:nowrap;
        }
        table td {
            padding:12px 14px;
            border-bottom:1px solid var(--line);
            vertical-align:middle;
        }
        table tr:hover td { background:#faf8f3; }
        table td .status-badge {
            display:inline-block;
            padding:4px 14px;
            border-radius:12px;
            font-size:11px;
            font-weight:600;
        }
        .status-badge.pending { background:#fdf1de; color:var(--warn); }
        .status-badge.approved { background:#e4f4e8; color:var(--success); }
        .status-badge.rejected { background:#fbe7e7; color:var(--danger); }
        .status-badge.signed { background:#e6eefb; color:var(--info); }

        .action-btn {
            padding:4px 12px;
            border:none;
            border-radius:12px;
            font-size:11px;
            font-weight:600;
            cursor:pointer;
            transition:all .2s;
            display:inline-flex;
            align-items:center;
            gap:4px;
            font-family:'Inter',sans-serif;
            text-decoration:none;
        }
        .action-btn:hover { transform:translateY(-1px); }
        .action-btn.approve { background:#e4f4e8; color:var(--success); }
        .action-btn.approve:hover { background:#c8e6c9; }
        .action-btn.reject { background:#fbe7e7; color:var(--danger); }
        .action-btn.reject:hover { background:#ffcdd2; }
        .action-btn.edit { background:#e6eefb; color:var(--info); }
        .action-btn.edit:hover { background:#bbdefb; }
        .action-btn.delete { background:#fbe7e7; color:var(--danger); }
        .action-btn.delete:hover { background:#ffcdd2; }
        .action-btn.view { background:var(--gold-100); color:var(--navy-800); }
        .action-btn.view:hover { background:var(--gold-500); color:var(--navy-950); }

        /* ===== MODAL ===== */
        .modal {
            display:none;
            position:fixed;
            top:0; left:0; right:0; bottom:0;
            background:rgba(0,0,0,0.5);
            z-index:1000;
            justify-content:center;
            align-items:center;
            padding:20px;
        }
        .modal.active {
            display:flex;
        }
        .modal-content {
            background:var(--paper);
            border-radius:var(--radius);
            max-width:700px;
            width:100%;
            max-height:90vh;
            overflow-y:auto;
            padding:30px 35px;
            position:relative;
            box-shadow:0 20px 60px rgba(0,0,0,0.2);
            animation:modalFade .3s ease;
        }
        @keyframes modalFade {
            from { opacity:0; transform:translateY(-20px); }
            to { opacity:1; transform:translateY(0); }
        }
        .modal-close {
            position:absolute;
            top:14px;
            right:18px;
            background:none;
            border:none;
            font-size:24px;
            cursor:pointer;
            color:var(--ink-soft);
            transition:color .2s;
        }
        .modal-close:hover { color:var(--ink); }
        .modal h3 {
            font-family:'Fraunces',serif;
            font-size:20px;
            color:var(--navy-950);
            margin-bottom:6px;
        }
        .modal .subtitle {
            color:var(--ink-soft);
            font-size:13px;
            margin-bottom:20px;
        }
        .modal .form-group {
            margin-bottom:16px;
        }
        .modal .form-group label {
            display:block;
            font-weight:600;
            font-size:13px;
            margin-bottom:4px;
            color:var(--ink);
        }
        .modal .form-group label .required {
            color:var(--danger);
        }
        .modal .form-group input,
        .modal .form-group textarea,
        .modal .form-group select {
            width:100%;
            padding:10px 14px;
            border:1px solid var(--line);
            border-radius:var(--radius-sm);
            font-size:13px;
            font-family:'Inter',sans-serif;
            transition:border-color .3s;
            background:var(--paper);
        }
        .modal .form-group input:focus,
        .modal .form-group textarea:focus,
        .modal .form-group select:focus {
            border-color:var(--gold-500);
            outline:none;
        }
        .modal .form-group textarea {
            min-height:80px;
            resize:vertical;
        }
        .modal .form-group .help-text {
            font-size:11px;
            color:var(--ink-soft);
            margin-top:3px;
        }
        .modal .form-row {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
        }
        .modal .btn-row {
            display:flex;
            gap:10px;
            margin-top:20px;
            flex-wrap:wrap;
        }
        .modal .btn {
            padding:10px 28px;
            border:none;
            border-radius:25px;
            font-weight:600;
            font-size:13px;
            cursor:pointer;
            font-family:'Inter',sans-serif;
            display:inline-flex;
            align-items:center;
            gap:8px;
            transition:all .25s;
        }
        .modal .btn:hover { transform:translateY(-2px); box-shadow:var(--shadow-sm); }
        .modal .btn-primary { background:var(--navy-800); color:#fff; }
        .modal .btn-primary:hover { background:var(--navy-700); }
        .modal .btn-success { background:var(--success); color:#fff; }
        .modal .btn-success:hover { background:#2d9b54; }
        .modal .btn-danger { background:var(--danger); color:#fff; }
        .modal .btn-danger:hover { background:#c0392b; }
        .modal .btn-outline { background:var(--paper); color:var(--ink-soft); border:2px solid var(--line); }
        .modal .btn-outline:hover { border-color:var(--gold-500); color:var(--ink); }

        .modal .info-box {
            padding:12px 16px;
            background:var(--cream);
            border-radius:var(--radius-sm);
            margin-bottom:12px;
            font-size:13px;
        }
        .modal .info-box strong {
            color:var(--navy-800);
        }

        /* ===== RESPONSIVE ===== */
        @media(max-width:768px) {
            .header { padding:10px 16px; }
            .header-brand { font-size:14px; }
            .header-brand small { display:none; }
            .header-nav a { padding:4px 12px; font-size:11px; }
            .header-nav a span { display:none; }
            .content { padding:12px 12px 30px; }
            .table-container { padding:14px; }
            .table-header { flex-direction:column; align-items:stretch; }
            .table-header .search-box { flex-wrap:wrap; }
            .table-header .search-box input { min-width:100%; }
            .stats-grid { grid-template-columns:1fr 1fr; }
            .modal-content { padding:20px; }
            .modal .form-row { grid-template-columns:1fr; }
            table { font-size:12px; }
            table th, table td { padding:8px 10px; }
        }
        @media(max-width:480px) {
            .stats-grid { grid-template-columns:1fr; }
            .modal { padding:10px; }
            .modal-content { padding:16px; }
        }
    </style>
</head>
<body>
    <!-- ===== HEADER ===== -->
    <header class="header">
        <div class="header-left">
            <div class="header-logo">
                <img src="/bettergrowthsolutions.jpg" alt="BGS">
            </div>
            <div class="header-brand">
                Better<span>Growth</span> <small>| Admin Panel</small>
            </div>
        </div>
        <nav class="header-nav">
            <a href="admin-dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a>
            <a href="admin-agents.php"><i class="fas fa-users"></i> <span>Agents</span></a>
            <a href="admin-joining-letter.php" class="active"><i class="fas fa-file-contract"></i> <span>Joining Letters</span></a>
            <a href="admin-compliance.php"><i class="fas fa-file-signature"></i> <span>Compliance</span></a>
            <a href="admin-logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </nav>
    </header>

    <!-- ===== CONTENT ===== -->
    <div class="content">
        <!-- Alert Messages -->
        <?php if ($successMsg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $successMsg; ?></div>
        <?php endif; ?>
        <?php if ($actionError): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $actionError; ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $total = count($letters);
        $pending = 0; $approved = 0; $rejected = 0; $signed = 0;
        foreach ($letters as $l) {
            $status = $l['status'] ?? 'pending';
            if ($status === 'pending') $pending++;
            elseif ($status === 'approved') $approved++;
            elseif ($status === 'rejected') $rejected++;
            if (!empty($l['signature_data'])) $signed++;
        }
        ?>
        <div class="stats-grid">
            <div class="stat-card"><span class="stat-number"><?php echo $total; ?></span><span class="stat-label">Total Letters</span></div>
            <div class="stat-card pending"><span class="stat-number"><?php echo $pending; ?></span><span class="stat-label">Pending</span></div>
            <div class="stat-card approved"><span class="stat-number"><?php echo $approved; ?></span><span class="stat-label">Approved</span></div>
            <div class="stat-card rejected"><span class="stat-number"><?php echo $rejected; ?></span><span class="stat-label">Rejected</span></div>
            <div class="stat-card"><span class="stat-number"><?php echo $signed; ?></span><span class="stat-label">Signed</span></div>
        </div>

        <!-- Table -->
        <div class="table-container">
            <div class="table-header">
                <h2><i class="fas fa-file-contract" style="color:var(--gold-500);"></i> Joining Letters</h2>
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="🔍 Search agent..." onkeyup="filterTable()">
                    <select id="statusFilter" onchange="filterTable()">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="signed">Signed</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>

            <?php if (empty($letters)): ?>
            <div style="text-align:center;padding:40px 20px;color:var(--ink-soft);">
                <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:10px;color:var(--gold-500);"></i>
                <p>No joining letters found.</p>
            </div>
            <?php else: ?>
            <table id="lettersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Agent</th>
                        <th>Designation</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>Signed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($letters as $letter): 
                        $status = $letter['status'] ?? 'pending';
                        $salaryDisplay = $letter['salary'] ?? 0;
                        if (!empty($letter['salary_revised']) && $letter['salary_revised'] > 0) {
                            $salaryDisplay = $letter['salary_revised'] . ' <span style="color:var(--success);font-size:10px;">↑</span>';
                        }
                    ?>
                    <tr data-status="<?php echo $status; ?>" data-name="<?php echo strtolower($letter['full_name'] ?? ''); ?>">
                        <td><?php echo $i++; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($letter['full_name'] ?? 'N/A'); ?></strong>
                            <br><span style="font-size:11px;color:var(--ink-soft);">ID: <?php echo htmlspecialchars($letter['agent_id']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($letter['designation'] ?? 'N/A'); ?></td>
                        <td>₹<?php echo number_format($letter['salary'] ?? 0, 0); ?></td>
                        <td>
                            <?php if (!empty($letter['signature_data']) && $status === 'pending'): ?>
                                <span class="status-badge signed">✍️ Signed</span>
                            <?php elseif ($status === 'approved'): ?>
                                <span class="status-badge approved">✅ Approved</span>
                            <?php elseif ($status === 'rejected'): ?>
                                <span class="status-badge rejected">❌ Rejected</span>
                            <?php else: ?>
                                <span class="status-badge pending">⏳ Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($letter['signature_data'])): ?>
                                <span style="color:var(--success);font-size:12px;">✅ <?php echo date('d M', strtotime($letter['agreed_at'] ?? 'now')); ?></span>
                            <?php else: ?>
                                <span style="color:var(--ink-soft);font-size:12px;">❌ Not signed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <?php if ($status === 'pending'): ?>
                                    <button class="action-btn approve" onclick="openApproveModal(<?php echo $letter['id']; ?>, '<?php echo $letter['agent_id']; ?>', '<?php echo addslashes($letter['full_name']); ?>', <?php echo $letter['salary'] ?? 0; ?>)">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="action-btn reject" onclick="openRejectModal(<?php echo $letter['id']; ?>, '<?php echo $letter['agent_id']; ?>', '<?php echo addslashes($letter['full_name']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                                <?php if ($status === 'approved'): ?>
                                    <button class="action-btn edit" onclick="openSalaryModal(<?php echo $letter['id']; ?>, '<?php echo $letter['agent_id']; ?>', '<?php echo addslashes($letter['full_name']); ?>', <?php echo $letter['salary'] ?? 0; ?>, <?php echo $letter['salary_revised'] ?? 0; ?>)">
                                        <i class="fas fa-edit"></i> Salary
                                    </button>
                                <?php endif; ?>
                                <button class="action-btn view" onclick="window.open('view-letter.php?id=<?php echo $letter['id']; ?>','_blank')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn delete" onclick="confirmDelete(<?php echo $letter['id']; ?>, '<?php echo $letter['agent_id']; ?>', '<?php echo addslashes($letter['full_name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- APPROVE MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="approveModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            <h3><i class="fas fa-check-circle" style="color:var(--success);"></i> Approve Joining Letter</h3>
            <div class="subtitle">Review and approve the joining letter for <span id="approveAgentName"></span></div>

            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="letter_id" id="approveLetterId">
                <input type="hidden" name="agent_id" id="approveAgentId">

                <div class="info-box">
                    <strong>Current Salary:</strong> ₹<span id="approveCurrentSalary"></span>/month
                </div>

                <div class="form-group">
                    <label>Revised Salary (if any)</label>
                    <input type="number" name="salary_revised" id="approveSalaryRevised" step="500" min="0" placeholder="Leave blank to keep current salary">
                    <div class="help-text">Enter new salary amount if you want to revise the salary.</div>
                </div>

                <div class="form-group">
                    <label>Salary Revision Reason</label>
                    <input type="text" name="salary_revision_reason" id="approveRevisionReason" placeholder="e.g., Performance-based increment, Market adjustment, etc.">
                    <div class="help-text">Why is the salary being revised?</div>
                </div>

                <div class="form-group">
                    <label>Performance Remarks</label>
                    <textarea name="performance_remarks" id="approvePerformanceRemarks" placeholder="Add performance-related remarks..."></textarea>
                    <div class="help-text">Any positive feedback or performance notes for the employee.</div>
                </div>

                <div class="form-group">
                    <label>Admin Comment <span class="required">*</span></label>
                    <textarea name="admin_comment" id="approveAdminComment" required placeholder="Add your approval comments..."></textarea>
                    <div class="help-text">Required: Add your remarks about this approval.</div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- REJECT MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            <h3><i class="fas fa-times-circle" style="color:var(--danger);"></i> Reject Joining Letter</h3>
            <div class="subtitle">Reject the joining letter for <span id="rejectAgentName"></span></div>

            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="letter_id" id="rejectLetterId">
                <input type="hidden" name="agent_id" id="rejectAgentId">

                <div class="form-group">
                    <label>Rejection Reason <span class="required">*</span></label>
                    <textarea name="rejection_reason" id="rejectReason" required placeholder="Why is this joining letter being rejected?"></textarea>
                    <div class="help-text">Provide a clear reason for rejection.</div>
                </div>

                <div class="form-group">
                    <label>Admin Comment <span class="required">*</span></label>
                    <textarea name="admin_comment" id="rejectAdminComment" required placeholder="Add your rejection comments..."></textarea>
                    <div class="help-text">Required: Add your remarks about this rejection.</div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- SALARY MODAL -->
    <!-- ============================================================ -->
    <div class="modal" id="salaryModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('salaryModal')">&times;</button>
            <h3><i class="fas fa-edit" style="color:var(--info);"></i> Update Salary</h3>
            <div class="subtitle">Revise salary for <span id="salaryAgentName"></span></div>

            <form method="POST">
                <input type="hidden" name="action" value="update_salary">
                <input type="hidden" name="letter_id" id="salaryLetterId">
                <input type="hidden" name="agent_id" id="salaryAgentId">

                <div class="info-box">
                    <strong>Current Salary:</strong> ₹<span id="salaryCurrentSalary"></span>/month
                </div>

                <div class="form-group">
                    <label>New Revised Salary <span class="required">*</span></label>
                    <input type="number" name="salary_revised" id="salaryRevisedAmount" step="500" min="0" required placeholder="Enter new salary amount">
                    <div class="help-text">Enter the new salary amount after revision.</div>
                </div>

                <div class="form-group">
                    <label>Revision Reason <span class="required">*</span></label>
                    <input type="text" name="salary_revision_reason" id="salaryRevisionReason" required placeholder="e.g., Performance-based increment, Market adjustment, etc.">
                    <div class="help-text">Why is the salary being revised?</div>
                </div>

                <div class="form-group">
                    <label>Admin Comment</label>
                    <textarea name="admin_comment" id="salaryAdminComment" placeholder="Add any additional comments..."></textarea>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Salary</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('salaryModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- DELETE CONFIRMATION -->
    <!-- ============================================================ -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            <h3><i class="fas fa-exclamation-triangle" style="color:var(--danger);"></i> Confirm Delete</h3>
            <div class="subtitle">Are you sure you want to delete the joining letter for <span id="deleteAgentName"></span>?</div>
            <p style="color:var(--danger);font-size:13px;margin:12px 0;">⚠️ This action cannot be undone.</p>

            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="letter_id" id="deleteLetterId">
                <input type="hidden" name="agent_id" id="deleteAgentId">

                <div class="btn-row">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Yes, Delete</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ===== MODAL FUNCTIONS =====
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on click outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // ===== APPROVE MODAL =====
        function openApproveModal(letterId, agentId, agentName, currentSalary) {
            document.getElementById('approveLetterId').value = letterId;
            document.getElementById('approveAgentId').value = agentId;
            document.getElementById('approveAgentName').textContent = agentName;
            document.getElementById('approveCurrentSalary').textContent = currentSalary;
            document.getElementById('approveSalaryRevised').value = '';
            document.getElementById('approveRevisionReason').value = '';
            document.getElementById('approvePerformanceRemarks').value = '';
            document.getElementById('approveAdminComment').value = '';
            openModal('approveModal');
        }

        // ===== REJECT MODAL =====
        function openRejectModal(letterId, agentId, agentName) {
            document.getElementById('rejectLetterId').value = letterId;
            document.getElementById('rejectAgentId').value = agentId;
            document.getElementById('rejectAgentName').textContent = agentName;
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectAdminComment').value = '';
            openModal('rejectModal');
        }

        // ===== SALARY MODAL =====
        function openSalaryModal(letterId, agentId, agentName, currentSalary, revisedSalary) {
            document.getElementById('salaryLetterId').value = letterId;
            document.getElementById('salaryAgentId').value = agentId;
            document.getElementById('salaryAgentName').textContent = agentName;
            document.getElementById('salaryCurrentSalary').textContent = currentSalary;
            document.getElementById('salaryRevisedAmount').value = revisedSalary > 0 ? revisedSalary : '';
            document.getElementById('salaryRevisionReason').value = '';
            document.getElementById('salaryAdminComment').value = '';
            openModal('salaryModal');
        }

        // ===== DELETE MODAL =====
        function confirmDelete(letterId, agentId, agentName) {
            if (confirm('⚠️ Are you sure you want to delete the joining letter for ' + agentName + '?')) {
                document.getElementById('deleteLetterId').value = letterId;
                document.getElementById('deleteAgentId').value = agentId;
                document.getElementById('deleteAgentName').textContent = agentName;
                openModal('deleteModal');
            }
        }

        // ===== TABLE FILTER =====
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = document.getElementById('statusFilter');
            const searchTerm = input.value.toLowerCase();
            const statusFilter = filter.value;
            const rows = document.querySelectorAll('#lettersTable tbody tr');

            rows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const status = row.getAttribute('data-status') || '';
                let show = true;

                if (searchTerm && !name.includes(searchTerm)) {
                    show = false;
                }
                if (statusFilter && status !== statusFilter) {
                    show = false;
                }

                row.style.display = show ? '' : 'none';
            });
        }

        // ===== KEYBOARD SHORTCUTS =====
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>