<?php
// /raj/admin/training-letters-manage.php
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

// ---------- Handle Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $letterId = (int)($_POST['letter_id'] ?? 0);

    // Approve
    if ($action === 'approve' && $letterId > 0) {
        executeQuery("UPDATE training_letters SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?", [$adminId, $letterId]);
        $message = "✅ Training letter approved!";
    }

    // Reject
    if ($action === 'reject' && $letterId > 0) {
        $notes = trim($_POST['notes'] ?? '');
        executeQuery("UPDATE training_letters SET status='rejected', admin_notes=? WHERE id=?", [$notes, $letterId]);
        $message = "❌ Training letter rejected.";
    }

    // Update salary
    if ($action === 'update_salary') {
        $agentId = $_POST['agent_id'] ?? '';
        $newSalary = (float)($_POST['salary'] ?? 0);
        if (!empty($agentId) && $newSalary > 0) {
            executeQuery("UPDATE agents SET salary = ? WHERE agent_id = ?", [$newSalary, $agentId]);
            $message = "💰 Salary updated for agent $agentId";
        }
    }
}

// ---------- Fetch all training letters ----------
$letters = [];
$query = executeQuery("
    SELECT tl.*, a.salary, a.candidate_photo_path
    FROM training_letters tl
    LEFT JOIN agents a ON tl.agent_id = a.agent_id
    ORDER BY FIELD(tl.status, 'pending', 'approved', 'rejected'), tl.agreed_at DESC
");
if ($query) {
    while ($row = $query->fetch_assoc()) {
        $letters[] = $row;
    }
}

$pendingCount = count(array_filter($letters, fn($l) => $l['status'] === 'pending'));
$approvedCount = count(array_filter($letters, fn($l) => $l['status'] === 'approved'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Letters - Admin</title>
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
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f5f4f0;color:var(--ink);}
        .header{
            background:linear-gradient(135deg,#1a0a0a,#3d1515); color:#fce8e8;
            padding:14px 28px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.2);
        }
        .header .brand{font-family:'Fraunces',serif; font-size:17px; font-weight:600;}
        .header .nav a{color:#fce8e8; text-decoration:none; padding:6px 14px; border-radius:20px; font-size:12px; transition:background .2s;}
        .header .nav a:hover,.header .nav a.active{background:rgba(255,255,255,.12);}
        .header .nav a.active{color:var(--gold-300); font-weight:600;}
        .content{max-width:1300px; margin:0 auto; padding:22px 28px 40px;}
        .page-title{font-family:'Fraunces',serif; font-size:24px; color:var(--navy-950); margin-bottom:4px;}
        .alert{padding:12px 18px; border-radius:var(--radius-sm); margin-bottom:14px; font-size:13px;}
        .alert-success{background:#e4f4e8; color:var(--success); border:1px solid var(--success);}
        .alert-error{background:#fbe7e7; color:var(--danger); border:1px solid var(--danger);}

        .stats{display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:18px;}
        .stat-box{background:var(--paper); border-radius:var(--radius); padding:16px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line);}
        .stat-box .sv{font-family:'Fraunces',serif; font-size:28px; font-weight:700;}
        .stat-box .sl{font-size:10px; color:var(--ink-soft); text-transform:uppercase;}

        .card{background:var(--paper); border-radius:var(--radius); padding:20px; box-shadow:0 2px 8px rgba(0,0,0,.05); border:1px solid var(--line); margin-bottom:20px;}
        table{width:100%; border-collapse:collapse; font-size:12px;}
        th{background:var(--navy-800); color:#fff; padding:10px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.5px;}
        td{padding:10px; border-bottom:1px solid var(--line);}
        tr:hover{background:var(--cream);}
        tr.pending-row{background:#fffdf5;}
        .status-badge{padding:4px 12px; border-radius:12px; font-size:10px; font-weight:600; display:inline-block;}
        .status-badge.pending{background:#fdf1de; color:var(--warn);}
        .status-badge.approved{background:#e4f4e8; color:var(--success);}
        .status-badge.rejected{background:#fbe7e7; color:var(--danger);}

        .btn-xs{padding:5px 12px; border:none; border-radius:15px; font-size:10px; font-weight:600; cursor:pointer; margin:2px; transition:transform .2s;}
        .btn-xs:hover{transform:translateY(-1px);}
        .btn-approve{background:var(--success); color:#fff;}
        .btn-reject{background:var(--danger); color:#fff;}
        .btn-view{background:var(--info); color:#fff;}
        .btn-edit{background:var(--gold-500); color:var(--navy-950);}

        .modal{display:none; position:fixed; top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;}
        .modal.show{display:flex;}
        .modal-content{background:#fff; border-radius:var(--radius); padding:24px; max-width:450px; width:92%;}
        .modal-content h3{font-family:'Fraunces',serif; margin-bottom:12px;}
        .form-group{margin-bottom:12px;}
        .form-group label{display:block; font-size:11px; font-weight:600; color:var(--ink-soft); text-transform:uppercase; margin-bottom:4px;}
        .form-group input, .form-group textarea{width:100%; padding:8px 12px; border:2px solid var(--line); border-radius:8px; font-family:'Inter',sans-serif; font-size:13px;}
        .btn-group{display:flex; gap:8px; margin-top:14px;}
        .btn-save{background:var(--navy-800); color:#fff; padding:8px 20px; border:none; border-radius:8px; cursor:pointer; font-weight:600;}
        .btn-cancel{background:var(--cream); color:var(--ink-soft); border:2px solid var(--line); padding:8px 20px; border-radius:8px; cursor:pointer;}

        .copyright-footer{text-align:center; padding:20px; margin-top:30px; border-top:1px solid var(--line); font-size:12px; color:var(--ink-soft);}
        .copyright-footer a{color:var(--gold-500); text-decoration:none; font-weight:600;}

        @media(max-width:768px){ .content{padding:12px;} table{font-size:10px;} }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📋 Training Letters Management</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="training-letters-manage.php" class="active">Training Letters</a>
            <a href="agents-manage.php">Agents</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📋 Training Letter Approvals</h1>
        <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-box"><div class="sv" style="color:var(--warn);"><?php echo $pendingCount; ?></div><div class="sl">Pending</div></div>
            <div class="stat-box"><div class="sv" style="color:var(--success);"><?php echo $approvedCount; ?></div><div class="sl">Approved</div></div>
            <div class="stat-box"><div class="sv"><?php echo count($letters); ?></div><div class="sl">Total</div></div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Salary</th>
                        <th>Signed On</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($letters as $l): ?>
                    <tr class="<?php echo $l['status']==='pending'?'pending-row':''; ?>">
                        <td><strong><?php echo htmlspecialchars($l['full_name']); ?></strong><br><small><?php echo $l['agent_id']; ?></small></td>
                        <td><?php echo htmlspecialchars($l['email']); ?></td>
                        <td><?php echo htmlspecialchars($l['phone']); ?></td>
                        <td>
                            <span id="salary-display-<?php echo $l['agent_id']; ?>">₹<?php echo number_format($l['salary'] ?? 13000, 0); ?></span>
                            <button class="btn-xs btn-edit" onclick="openSalaryModal('<?php echo $l['agent_id']; ?>', <?php echo $l['salary'] ?? 13000; ?>)"><i class="fas fa-edit"></i></button>
                        </td>
                        <td><?php echo date('d M H:i', strtotime($l['agreed_at'])); ?></td>
                        <td><span class="status-badge <?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                        <td>
                            <a href="../agent/training-letter-pdf.php?agent=<?php echo $l['agent_id']; ?>" target="_blank" class="btn-xs btn-view">📄 PDF</a>
                            <?php if($l['status']==='pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                <button class="btn-xs btn-approve">✓ Approve</button>
                            </form>
                            <button class="btn-xs btn-reject" onclick="openRejectModal(<?php echo $l['id']; ?>)">✗ Reject</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($letters)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--ink-soft);">No training letters yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <h3>Reject Training Letter</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="letter_id" id="rejectId">
                <div class="form-group">
                    <label>Reason (optional)</label>
                    <textarea name="notes" rows="2" placeholder="e.g., Signature unclear..."></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-save">✗ Reject</button>
                    <button type="button" class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Salary Modal -->
    <div id="salaryModal" class="modal">
        <div class="modal-content">
            <h3>💰 Update Agent Salary</h3>
            <form method="POST" id="salaryForm">
                <input type="hidden" name="action" value="update_salary">
                <input type="hidden" name="agent_id" id="salaryAgentId">
                <div class="form-group">
                    <label>New Salary (₹)</label>
                    <input type="number" name="salary" id="salaryInput" step="500" min="0" required>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-save">💾 Save</button>
                    <button type="button" class="btn-cancel" onclick="closeSalaryModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        function openRejectModal(id) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectModal').classList.add('show');
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('show');
        }
        function openSalaryModal(agentId, currentSalary) {
            document.getElementById('salaryAgentId').value = agentId;
            document.getElementById('salaryInput').value = currentSalary;
            document.getElementById('salaryModal').classList.add('show');
        }
        function closeSalaryModal() {
            document.getElementById('salaryModal').classList.remove('show');
        }
        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) { if(e.target === this) this.classList.remove('show'); });
        });
    </script>
</body>
</html>