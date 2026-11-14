<?php
// /raj/admin/reports-manage.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    if ($action === 'approve' && $requestId > 0) {
        executeQuery("UPDATE sales_report_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [$adminId, $requestId]);
        $msg = "✅ Report approved!";
    }
    
    if ($action === 'reject' && $requestId > 0) {
        executeQuery("UPDATE sales_report_requests SET status = 'rejected' WHERE id = ?", [$requestId]);
        $msg = "❌ Report rejected.";
    }
    
    if ($action === 'mark_sent' && $requestId > 0) {
        executeQuery("UPDATE sales_report_requests SET status = 'sent', sent_at = NOW() WHERE id = ?", [$requestId]);
        $msg = "📧 Report marked as sent!";
    }
}

// Get all requests
$requestsQuery = executeQuery("
    SELECT srr.*, a.full_name, a.email 
    FROM sales_report_requests srr 
    JOIN agents a ON srr.agent_id = a.agent_id 
    ORDER BY 
        CASE srr.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        srr.requested_at DESC
");

$requests = [];
$pendingCount = 0;
$approvedCount = 0;
$sentCount = 0;

if ($requestsQuery) {
    while ($row = $requestsQuery->fetch_assoc()) {
        $requests[] = $row;
        if ($row['status'] === 'pending') $pendingCount++;
        if ($row['status'] === 'approved') $approvedCount++;
        if ($row['status'] === 'sent') $sentCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Requests - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --cream: #f8f7f3; --paper: #ffffff;
            --ink: #1c2536; --ink-soft: #667085; --line: #ecebe6;
            --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 16px; --radius-sm: 10px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-300); }

        .content { max-width: 1100px; margin: 0 auto; padding: 22px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 18px; }

        .alert { padding: 12px 18px; border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 13px; background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 18px; }
        .stat-box {
            background: var(--paper); border-radius: var(--radius); padding: 16px; text-align: center;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .stat-box .sv { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; }
        .stat-box .sl { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }
        .stat-box.pending .sv { color: var(--warn); }
        .stat-box.approved .sv { color: var(--info); }
        .stat-box.sent .sv { color: var(--success); }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table th {
            background: var(--navy-800); color: #fff; padding: 10px 14px; text-align: left;
            font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
        }
        table td { padding: 10px 14px; border-bottom: 1px solid var(--line); }
        table tr:hover { background: var(--cream); }
        table tr.pending-row { background: #fffdf5; }

        .status-badge {
            padding: 4px 12px; border-radius: 15px; font-size: 10px; font-weight: 600; display: inline-block;
        }
        .status-badge.pending { background: #fdf1de; color: var(--warn); }
        .status-badge.approved { background: #e6eefb; color: var(--info); }
        .status-badge.rejected { background: #fbe7e7; color: var(--danger); }
        .status-badge.sent { background: #e4f4e8; color: var(--success); }

        .btn-xs {
            padding: 5px 14px; border: none; border-radius: 15px; font-size: 10px;
            font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 4px; transition: transform .2s;
        }
        .btn-xs:hover { transform: translateY(-1px); }
        .btn-approve { background: var(--success); color: #fff; }
        .btn-reject { background: var(--danger); color: #fff; }
        .btn-send { background: var(--info); color: #fff; }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 768px) {
            .content { padding: 12px 14px; }
            table { font-size: 10px; }
            table th, table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📧 Report Requests</div>
        <div class="nav">
            <a href="db.php"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="dbsales.php"><i class="fas fa-chart-pie"></i> Analytics</a>
            <a href="agents-manage.php"><i class="fas fa-users"></i> Agents</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="targets-manage.php"><i class="fas fa-bullseye"></i> Targets</a>
            <a href="reports-manage.php" class="active"><i class="fas fa-envelope"></i> Reports</a>
            <a href="attendance-manage.php"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📧 Email Report Requests</h1>

        <?php if (isset($msg)): ?>
        <div class="alert"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-box pending">
                <div class="sv"><?php echo $pendingCount; ?></div>
                <div class="sl">Pending</div>
            </div>
            <div class="stat-box approved">
                <div class="sv"><?php echo $approvedCount; ?></div>
                <div class="sl">Approved</div>
            </div>
            <div class="stat-box sent">
                <div class="sv"><?php echo $sentCount; ?></div>
                <div class="sl">Sent</div>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Email</th>
                        <th>Month</th>
                        <th>Type</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:30px;color:var(--ink-soft);">
                            No report requests yet
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                    <tr class="<?php echo $req['status'] === 'pending' ? 'pending-row' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($req['full_name']); ?></strong></td>
                        <td style="font-size:11px;"><?php echo htmlspecialchars($req['email']); ?></td>
                        <td><?php echo date('F Y', strtotime(($req['month_year'] ?? date('Y-m')) . '-01')); ?></td>
                        <td><?php echo ucfirst($req['request_type']); ?></td>
                        <td><?php echo date('d M H:i', strtotime($req['requested_at'])); ?></td>
                        <td><span class="status-badge <?php echo $req['status']; ?>"><?php echo ucfirst($req['status']); ?></span></td>
                        <td>
                            <?php if ($req['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="btn-xs btn-approve">✓ Approve</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="btn-xs btn-reject">✗ Reject</button>
                            </form>
                            <?php elseif ($req['status'] === 'approved'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_sent">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="btn-xs btn-send">📧 Mark Sent</button>
                            </form>
                            <?php else: ?>
                            <span style="font-size:10px;color:var(--ink-soft);">
                                <?php echo $req['status'] === 'sent' ? '✅ Sent ' . date('d M', strtotime($req['sent_at'] ?? 'now')) : ''; ?>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>
</body>
</html>