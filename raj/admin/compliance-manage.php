<?php
// /raj/admin/compliance-manage.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $agreementId = (int)($_POST['agreement_id'] ?? 0);
    
    if ($action === 'approve' && $agreementId > 0) {
        executeQuery("UPDATE compliance_agreements SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?", [$adminId, $agreementId]);
        $msg = "✅ Agreement approved!";
    }
    if ($action === 'reject' && $agreementId > 0) {
        $notes = trim($_POST['notes'] ?? '');
        executeQuery("UPDATE compliance_agreements SET status='rejected', admin_notes=? WHERE id=?", [$notes, $agreementId]);
        $msg = "❌ Agreement rejected.";
    }
}

// Get all agreements
$agreements = [];
$aq = executeQuery("
    SELECT ca.*, a.candidate_photo_path 
    FROM compliance_agreements ca 
    LEFT JOIN agents a ON ca.agent_id = a.agent_id 
    ORDER BY FIELD(ca.status, 'pending', 'approved', 'rejected'), ca.agreed_at DESC
");
if ($aq) while ($r = $aq->fetch_assoc()) $agreements[] = $r;

$pendingCount = count(array_filter($agreements, fn($a) => $a['status'] === 'pending'));
$approvedCount = count(array_filter($agreements, fn($a) => $a['status'] === 'approved'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Management - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 16px; --radius-sm: 10px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f5f4f0; color:var(--ink); }
        .header {
            background:linear-gradient(135deg,#1a0a0a,#3d1515); color:#fce8e8;
            padding:14px 28px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
        }
        .header .brand { font-family:'Fraunces',serif; font-size:17px; font-weight:600; }
        .header .nav a { color:#fce8e8; text-decoration:none; padding:6px 14px; border-radius:20px; font-size:12px; }
        .header .nav a:hover,.header .nav a.active { background:rgba(255,255,255,.12); }
        .content { max-width:1200px; margin:0 auto; padding:22px 28px 40px; }
        .page-title { font-family:'Fraunces',serif; font-size:24px; color:var(--navy-950); margin-bottom:4px; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:20px; }
        .stat-box { background:var(--paper); border-radius:var(--radius); padding:16px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line); }
        .stat-box .sv { font-family:'Fraunces',serif; font-size:28px; font-weight:700; }
        .stat-box .sl { font-size:10px; color:var(--ink-soft); text-transform:uppercase; }
        .card { background:var(--paper); border-radius:var(--radius); padding:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line); }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        table th { background:var(--navy-800); color:#fff; padding:10px; text-align:left; font-size:10px; text-transform:uppercase; }
        table td { padding:10px; border-bottom:1px solid var(--line); }
        table tr:hover { background:var(--cream); }
        table tr.pending-row { background:#fffdf5; }
        .status-badge { padding:4px 12px; border-radius:12px; font-size:10px; font-weight:600; display:inline-block; }
        .status-badge.pending { background:#fdf1de; color:var(--warn); }
        .status-badge.approved { background:#e4f4e8; color:var(--success); }
        .status-badge.rejected { background:#fbe7e7; color:var(--danger); }
        .btn-xs { padding:5px 14px; border:none; border-radius:15px; font-size:10px; font-weight:600; cursor:pointer; margin:2px; }
        .btn-approve { background:var(--success); color:#fff; }
        .btn-reject { background:var(--danger); color:#fff; }
        .btn-view { background:var(--info); color:#fff; }
        .modal { display:none; position:fixed; top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center; }
        .modal.show { display:flex; }
        .modal-content { background:#fff; border-radius:var(--radius); padding:24px; max-width:500px; width:92%; }
        .modal-content textarea { width:100%; padding:8px; border:2px solid var(--line); border-radius:8px; font-family:'Inter',sans-serif; }
        .copyright-footer { text-align:center; padding:20px; margin-top:30px; border-top:1px solid var(--line); font-size:12px; color:var(--ink-soft); }
        .copyright-footer a { color:var(--gold-500); text-decoration:none; font-weight:600; }
        @media(max-width:768px){ .content{padding:12px;} table{font-size:10px;} }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📋 Compliance Management</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="compliance-manage.php" class="active">Compliance</a>
            <a href="agents-manage.php">Agents</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📋 Compliance Agreements</h1>
        <?php if(isset($msg)): ?><div class="alert" style="padding:12px;background:#e4f4e8;color:var(--success);border-radius:8px;margin-bottom:14px;"><?php echo $msg; ?></div><?php endif; ?>

        <div class="stats">
            <div class="stat-box"><div class="sv" style="color:var(--warn);"><?php echo $pendingCount; ?></div><div class="sl">Pending</div></div>
            <div class="stat-box"><div class="sv" style="color:var(--success);"><?php echo $approvedCount; ?></div><div class="sl">Approved</div></div>
            <div class="stat-box"><div class="sv"><?php echo count($agreements); ?></div><div class="sl">Total</div></div>
        </div>

        <div class="card">
            <table>
                <thead><tr><th>Agent</th><th>Email</th><th>Phone</th><th>Signed On</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($agreements as $a): ?>
                    <tr class="<?php echo $a['status']==='pending'?'pending-row':''; ?>">
                        <td><strong><?php echo htmlspecialchars($a['full_name']); ?></strong><br><small><?php echo $a['agent_id']; ?></small></td>
                        <td><?php echo htmlspecialchars($a['email']); ?></td>
                        <td><?php echo htmlspecialchars($a['phone']); ?></td>
                        <td><?php echo date('d M H:i', strtotime($a['agreed_at'])); ?></td>
                        <td><span class="status-badge <?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                        <td>
                            <a href="../agent/compliance-pdf.php?agent=<?php echo $a['agent_id']; ?>" target="_blank" class="btn-xs btn-view">📄 PDF</a>
                            <?php if($a['status']==='pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="agreement_id" value="<?php echo $a['id']; ?>">
                                <button class="btn-xs btn-approve">✓ Approve</button>
                            </form>
                            <button class="btn-xs btn-reject" onclick="openRejectModal(<?php echo $a['id']; ?>)">✗ Reject</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <h3>Reject Agreement</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="agreement_id" id="rejectId">
                <p style="margin:10px 0;font-size:13px;">Reason for rejection:</p>
                <textarea name="notes" rows="3" placeholder="e.g., Signature unclear, missing information..."></textarea>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="submit" class="btn-xs btn-reject">✗ Reject</button>
                    <button type="button" class="btn-xs" style="background:#ccc;" onclick="closeRejectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        function openRejectModal(id) { document.getElementById('rejectId').value = id; document.getElementById('rejectModal').classList.add('show'); }
        function closeRejectModal() { document.getElementById('rejectModal').classList.remove('show'); }
        document.getElementById('rejectModal').addEventListener('click',function(e){ if(e.target===this) closeRejectModal(); });
    </script>
</body>
</html>