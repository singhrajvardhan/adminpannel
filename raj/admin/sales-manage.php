<?php
// /raj/admin/sales-manage.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check admin login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;

// Get all agents
$agentsQuery = executeQuery("SELECT agent_id, full_name, candidate_photo_path, shift_type, work_location FROM agents WHERE onboarding_completed = 1 AND status = 'active'");
$agents = [];
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
    }
}

// Get all sales for the month with agent info
$allSalesQuery = executeQuery("
    SELECT ds.*, a.full_name 
    FROM daily_sales ds 
    JOIN agents a ON ds.agent_id = a.agent_id 
    WHERE DATE_FORMAT(ds.sale_date, '%Y-%m') = ? 
    ORDER BY ds.sale_date ASC, a.full_name ASC
", [$selectedMonth]);
$allSales = [];
$agentTotals = [];
if ($allSalesQuery) {
    while ($row = $allSalesQuery->fetch_assoc()) {
        $allSales[] = $row;
        $aid = $row['agent_id'];
        if (!isset($agentTotals[$aid])) {
            $agentTotals[$aid] = [
                'name' => $row['full_name'],
                'credit_score' => 0,
                'credit_strong' => 0,
                'kikoff' => 0,
                'other' => 0,
                'total' => 0
            ];
        }
        $agentTotals[$aid]['credit_score'] += $row['credit_score'];
        $agentTotals[$aid]['credit_strong'] += $row['credit_strong'];
        $agentTotals[$aid]['kikoff'] += $row['kikoff'];
        $agentTotals[$aid]['other'] += $row['other_sales'];
        $agentTotals[$aid]['total'] += $row['total_sales'];
    }
}

// Get targets for all agents
$targetsQuery = executeQuery("SELECT * FROM sales_targets WHERE month_year = ?", [$selectedMonth]);
$targets = [];
if ($targetsQuery) {
    while ($row = $targetsQuery->fetch_assoc()) {
        $targets[$row['agent_id']] = $row;
    }
}

// Get pending report requests
$reportRequests = executeQuery("
    SELECT srr.*, a.full_name, a.email 
    FROM sales_report_requests srr 
    JOIN agents a ON srr.agent_id = a.agent_id 
    WHERE srr.status = 'pending' 
    ORDER BY srr.requested_at DESC
");

// Admin own target
$adminTargetQuery = executeQuery("SELECT * FROM admin_targets WHERE month_year = ?", [$selectedMonth]);
$adminTarget = $adminTargetQuery ? $adminTargetQuery->fetch_assoc() : null;

// Calculate team totals
$teamTotalSales = array_sum(array_column($agentTotals, 'total'));
$teamTarget = $adminTarget['total_team_target'] ?? 0;

// Available months
$monthsQuery = executeQuery("SELECT DISTINCT DATE_FORMAT(sale_date, '%Y-%m') as month_year FROM daily_sales ORDER BY month_year DESC LIMIT 12");
$availableMonths = [];
if ($monthsQuery) {
    while ($row = $monthsQuery->fetch_assoc()) {
        $availableMonths[] = $row['month_year'];
    }
}
if (!in_array($currentMonth, $availableMonths)) {
    array_unshift($availableMonths, $currentMonth);
}

// Chart data for team overview
$chartAgentNames = [];
$chartAgentSales = [];
$chartAgentTargets = [];
foreach ($agentTotals as $aid => $data) {
    $chartAgentNames[] = $data['name'];
    $chartAgentSales[] = $data['total'];
    $chartAgentTargets[] = $targets[$aid]['monthly_target'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Management - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --gold-300: #e9c877; --cream: #f8f7f3;
            --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success-bg: #e4f4e8; --success-ink: #1e7a41;
            --danger-bg: #fbe7e7; --danger-ink: #b3352f; --warn-bg: #fdf1de;
            --warn-ink: #9a6a10; --info-bg: #e6eefb; --info-ink: #29538c;
            --radius: 16px; --radius-sm: 10px; --ease: cubic-bezier(.22,.61,.36,1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--cream); color: var(--ink); }

        .header {
            background: linear-gradient(190deg, #6b1d1d, #8b2525 90%);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .header .brand { display: flex; align-items: center; gap: 10px; }
        .header .brand .logo-mark {
            width: 34px; height: 34px; border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 14px; color: #6b1d1d;
        }
        .header .brand .brand-text { font-family: 'Fraunces', serif; font-size: 15px; font-weight: 600; color: #fff; }
        .header .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }
        .header .nav-links a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: var(--radius-sm);
            font-size: 12px; font-weight: 500;
        }
        .header .nav-links a:hover, .header .nav-links a.active { background: rgba(255,255,255,.15); }
        .header .nav-links a.active { color: var(--gold-300); }

        .content { max-width: 1300px; margin: 0 auto; padding: 20px 24px 40px; }

        .page-title {
            font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 4px;
        }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 16px; }

        .section-title {
            font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950);
            margin: 20px 0 12px; display: flex; align-items: center; gap: 8px;
        }
        .section-title i { color: var(--gold-500); }

        .month-selector {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .month-selector select {
            padding: 8px 16px; border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper); cursor: pointer;
        }

        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px; margin-bottom: 16px;
        }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 16px;
            text-align: center; box-shadow: 0 1px 3px rgba(12,26,56,.06); border: 1px solid var(--line);
        }
        .stat-card .stat-value { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--navy-950); }
        .stat-card .stat-label { font-size: 11px; color: var(--ink-soft); font-weight: 500; text-transform: uppercase; }
        .stat-card.admin-highlight { border: 2px solid var(--gold-500); background: #fffdf5; }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            margin-bottom: 16px; box-shadow: 0 1px 3px rgba(12,26,56,.06); border: 1px solid var(--line);
        }
        .card h3 {
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950); margin-bottom: 12px;
        }

        .chart-wrapper { position: relative; width: 100%; max-height: 350px; }
        .chart-wrapper canvas { width: 100% !important; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table th {
            background: var(--cream); padding: 8px 10px; text-align: left; font-weight: 600;
            color: var(--ink-soft); font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
            border-bottom: 2px solid var(--line);
        }
        table td { padding: 8px 10px; border-bottom: 1px solid var(--line); }
        table tr:hover { background: var(--cream); }

        .status-badge {
            padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 600;
        }
        .status-badge.pending { background: var(--warn-bg); color: var(--warn-ink); }
        .status-badge.approved { background: var(--success-bg); color: var(--success-ink); }
        .status-badge.rejected { background: var(--danger-bg); color: var(--danger-ink); }
        .status-badge.sent { background: var(--info-bg); color: var(--info-ink); }

        .btn-sm {
            padding: 5px 12px; border: none; border-radius: var(--radius-sm); font-size: 11px;
            font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 4px; transition: transform .2s;
        }
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-approve { background: var(--success-ink); color: #fff; }
        .btn-reject { background: var(--danger-ink); color: #fff; }
        .btn-edit { background: var(--info-ink); color: #fff; }
        .btn-send { background: var(--gold-500); color: var(--navy-950); }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            max-width: 500px; width: 92%; box-shadow: 0 20px 45px rgba(12,26,56,.14);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); margin-bottom: 12px; }
        .modal-content .form-group { margin-bottom: 12px; }
        .modal-content .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--ink-soft); margin-bottom: 4px; text-transform: uppercase; }
        .modal-content .form-group input {
            width: 100%; padding: 8px 12px; border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-size: 13px;
        }
        .modal-content .form-group input:focus { border-color: var(--gold-500); outline: none; }
        .modal-content .btn-group { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .modal-content .btn-save { padding: 10px 24px; background: var(--navy-800); color: #fff; border: none; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; }
        .modal-content .btn-cancel { padding: 10px 24px; background: var(--cream); color: var(--ink-soft); border: 2px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; }

        .copyright-footer {
            text-align: center; padding: 15px 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        .tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
        .tabs .tab {
            padding: 10px 20px; border: none; background: var(--paper); cursor: pointer;
            border-radius: var(--radius-sm); font-family: 'Inter', sans-serif; font-size: 13px;
            font-weight: 500; color: var(--ink-soft); border: 1px solid var(--line);
            transition: all .2s;
        }
        .tabs .tab.active { background: var(--navy-800); color: #fff; border-color: var(--navy-800); }
        .tabs .tab:hover:not(.active) { background: var(--cream); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .header { padding: 10px 16px; }
            table { font-size: 11px; }
            table th, table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            <div class="logo-mark">BG</div>
            <div class="brand-text">BetterGrowth<span>Admin Panel</span></div>
        </div>
        <div class="nav-links">
            <a href="db.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="sales-manage.php" class="active"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📊 Sales Management</h1>
        <p class="page-subtitle">Manage agent sales, targets, and reports</p>

        <!-- Month Selector -->
        <div class="month-selector">
            <select onchange="window.location.href='?month=' + this.value">
                <?php foreach ($availableMonths as $m): 
                    $dateObj = DateTime::createFromFormat('Y-m', $m);
                    $formatted = $dateObj ? $dateObj->format('F Y') : $m;
                ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $selectedMonth ? 'selected' : ''; ?>>
                        <?php echo $formatted; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn-sm btn-edit" onclick="openSetTargetModal()">
                <i class="fas fa-bullseye"></i> Set Monthly Targets
            </button>
            <button class="btn-sm btn-send" onclick="location.href='sales-edit.php?month=<?php echo $selectedMonth; ?>'">
                <i class="fas fa-edit"></i> Edit Sales
            </button>
        </div>

        <!-- Team Stats -->
        <div class="stats-row">
            <div class="stat-card admin-highlight">
                <div class="stat-value"><?php echo number_format($teamTotalSales); ?></div>
                <div class="stat-label">Team Total Sales</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($teamTarget); ?></div>
                <div class="stat-label">Team Target</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $teamTarget > 0 ? round(($teamTotalSales / $teamTarget) * 100) : 0; ?>%</div>
                <div class="stat-label">Team Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($agentTotals); ?></div>
                <div class="stat-label">Active Agents</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                        $pendingReports = 0;
                        if ($reportRequests) {
                            while ($r = $reportRequests->fetch_assoc()) {
                                $pendingReports++;
                            }
                            $reportRequests->data_seek(0);
                        }
                        echo $pendingReports;
                    ?>
                </div>
                <div class="stat-label">Pending Reports</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('overview')">📊 Team Overview</button>
            <button class="tab" onclick="switchTab('leaderboard')">🏆 Leaderboard</button>
            <button class="tab" onclick="switchTab('allsales')">📋 All Sales</button>
            <button class="tab" onclick="switchTab('reports')">📧 Report Requests</button>
            <button class="tab" onclick="switchTab('targets')">🎯 Targets</button>
        </div>

        <!-- Tab: Overview -->
        <div class="tab-content active" id="tab-overview">
            <div class="card">
                <h3><i class="fas fa-chart-bar"></i> Agent Performance Comparison</h3>
                <div class="chart-wrapper">
                    <canvas id="teamChart"></canvas>
                </div>
            </div>

            <div class="card">
                <h3><i class="fas fa-users"></i> Agent Summary</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Credit Score</th>
                                <th>Credit Strong</th>
                                <th>KikOff</th>
                                <th>Other</th>
                                <th>Total</th>
                                <th>Target</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agentTotals as $aid => $data): 
                                $tgt = $targets[$aid]['monthly_target'] ?? 0;
                                $pct = $tgt > 0 ? round(($data['total'] / $tgt) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($data['name']); ?></strong><br><small style="color:var(--ink-soft);"><?php echo $aid; ?></small></td>
                                <td><?php echo $data['credit_score']; ?></td>
                                <td><?php echo $data['credit_strong']; ?></td>
                                <td><?php echo $data['kikoff']; ?></td>
                                <td><?php echo $data['other']; ?></td>
                                <td><strong><?php echo $data['total']; ?></strong></td>
                                <td><?php echo $tgt; ?></td>
                                <td>
                                    <div style="background:var(--cream);border-radius:10px;height:6px;width:80px;overflow:hidden;display:inline-block;vertical-align:middle;">
                                        <div style="height:100%;width:<?php echo min(100,$pct); ?>%;background:<?php echo $pct>=100?'#1e7a41':'#c9962f'; ?>;border-radius:10px;"></div>
                                    </div>
                                    <span style="font-size:11px;margin-left:4px;"><?php echo $pct; ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Leaderboard -->
        <div class="tab-content" id="tab-leaderboard">
            <div class="card">
                <h3>🏆 Top Performers - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>
                <?php 
                // Sort agents by total sales
                $sortedAgents = $agentTotals;
                uasort($sortedAgents, function($a, $b) { return $b['total'] - $a['total']; });
                $rank = 1;
                ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($sortedAgents as $aid => $data): 
                        $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));
                        $bgColor = $rank === 1 ? '#fffdf5' : ($rank === 2 ? '#f8f8f8' : ($rank === 3 ? '#fdf5f0' : '#fff'));
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:<?php echo $bgColor; ?>;border-radius:12px;border:1px solid var(--line);">
                        <div style="font-size:28px;font-weight:700;min-width:40px;text-align:center;"><?php echo $medal ? $medal : '#' . $rank; ?></div>
                        <div style="flex:1;">
                            <strong><?php echo htmlspecialchars($data['name']); ?></strong>
                            <div style="font-size:11px;color:var(--ink-soft);"><?php echo $aid; ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-family:'Fraunces',serif;font-size:22px;font-weight:700;color:var(--navy-950);"><?php echo $data['total']; ?></div>
                            <div style="font-size:11px;color:var(--ink-soft);">total sales</div>
                        </div>
                        <div style="text-align:right;min-width:70px;">
                            <div style="font-size:14px;font-weight:600;color:var(--gold-500);">⭐ <?php echo $data['credit_score']; ?></div>
                            <div style="font-size:10px;color:var(--ink-soft);">credit score</div>
                        </div>
                    </div>
                    <?php $rank++; endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tab: All Sales -->
        <div class="tab-content" id="tab-allsales">
            <div class="card">
                <h3>📋 All Daily Sales - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Agent</th>
                                <th>Credit Score</th>
                                <th>Credit Strong</th>
                                <th>KikOff</th>
                                <th>Other</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allSales as $sale): ?>
                            <tr>
                                <td><?php echo date('d M', strtotime($sale['sale_date'])); ?></td>
                                <td><?php echo htmlspecialchars($sale['full_name']); ?></td>
                                <td><?php echo $sale['credit_score']; ?></td>
                                <td><?php echo $sale['credit_strong']; ?></td>
                                <td><?php echo $sale['kikoff']; ?></td>
                                <td><?php echo $sale['other_sales']; ?></td>
                                <td><strong><?php echo $sale['total_sales']; ?></strong></td>
                                <td><span class="status-badge <?php echo $sale['status']; ?>"><?php echo ucfirst($sale['status']); ?></span></td>
                                <td>
                                    <?php if ($sale['status'] === 'pending'): ?>
                                        <button class="btn-sm btn-approve" onclick="approveSale(<?php echo $sale['id']; ?>)">✓</button>
                                        <button class="btn-sm btn-reject" onclick="rejectSale(<?php echo $sale['id']; ?>)">✗</button>
                                    <?php endif; ?>
                                    <button class="btn-sm btn-edit" onclick="editSale(<?php echo $sale['id']; ?>, '<?php echo $sale['agent_id']; ?>', '<?php echo $sale['sale_date']; ?>', <?php echo $sale['credit_score']; ?>, <?php echo $sale['credit_strong']; ?>, <?php echo $sale['kikoff']; ?>, <?php echo $sale['other_sales']; ?>)">✎</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allSales)): ?>
                            <tr><td colspan="9" style="text-align:center;color:var(--ink-soft);padding:20px;">No sales data for this month</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Report Requests -->
        <div class="tab-content" id="tab-reports">
            <div class="card">
                <h3>📧 Pending Report Requests</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Email</th>
                                <th>Month</th>
                                <th>Requested</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($reportRequests && $reportRequests->num_rows > 0): 
                                while ($req = $reportRequests->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($req['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($req['email']); ?></td>
                                <td><?php echo date('F Y', strtotime($req['month_year'] . '-01')); ?></td>
                                <td><?php echo date('d M H:i', strtotime($req['requested_at'])); ?></td>
                                <td><span class="status-badge <?php echo $req['status']; ?>"><?php echo ucfirst($req['status']); ?></span></td>
                                <td>
                                    <button class="btn-sm btn-approve" onclick="approveReport(<?php echo $req['id']; ?>)">Approve & Send</button>
                                    <button class="btn-sm btn-reject" onclick="rejectReport(<?php echo $req['id']; ?>)">Reject</button>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:20px;">No pending requests</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Targets -->
        <div class="tab-content" id="tab-targets">
            <div class="card">
                <h3>🎯 Monthly Targets - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Monthly Target</th>
                                <th>Credit Score Target</th>
                                <th>Working Days</th>
                                <th>Daily Needed</th>
                                <th>Actual Sales</th>
                                <th>Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $agent): 
                                $aid = $agent['agent_id'];
                                $tgt = $targets[$aid] ?? null;
                                $actualTotal = $agentTotals[$aid]['total'] ?? 0;
                                $monthlyTgt = $tgt['monthly_target'] ?? 0;
                                $pct = $monthlyTgt > 0 ? round(($actualTotal / $monthlyTgt) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($agent['full_name']); ?></strong></td>
                                <td><?php echo $monthlyTgt; ?></td>
                                <td><?php echo $tgt['credit_score_target'] ?? '-'; ?></td>
                                <td><?php echo $tgt['working_days'] ?? 22; ?></td>
                                <td><?php echo $tgt['daily_target'] ?? '-'; ?></td>
                                <td><strong><?php echo $actualTotal; ?></strong></td>
                                <td>
                                    <div style="background:var(--cream);border-radius:10px;height:6px;width:80px;overflow:hidden;display:inline-block;">
                                        <div style="height:100%;width:<?php echo min(100,$pct); ?>%;background:<?php echo $pct>=100?'#1e7a41':'#c9962f'; ?>;border-radius:10px;"></div>
                                    </div>
                                    <span style="font-size:11px;margin-left:4px;"><?php echo $pct; ?>%</span>
                                </td>
                                <td>
                                    <button class="btn-sm btn-edit" onclick="editTarget('<?php echo $aid; ?>', '<?php echo htmlspecialchars($agent['full_name']); ?>', <?php echo $monthlyTgt; ?>, <?php echo $tgt['credit_score_target'] ?? 0; ?>, <?php echo $tgt['working_days'] ?? 22; ?>)">Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Sale Modal -->
    <div id="editSaleModal" class="modal">
        <div class="modal-content">
            <h2>✎ Edit Sale Entry</h2>
            <input type="hidden" id="editSaleId">
            <div class="form-group"><label>Agent ID</label><input type="text" id="editAgentId" readonly></div>
            <div class="form-group"><label>Date</label><input type="date" id="editSaleDate"></div>
            <div class="form-group"><label>Credit Score</label><input type="number" id="editCreditScore" min="0"></div>
            <div class="form-group"><label>Credit Strong</label><input type="number" id="editCreditStrong" min="0"></div>
            <div class="form-group"><label>KikOff Bank</label><input type="number" id="editKikoff" min="0"></div>
            <div class="form-group"><label>Other Sales</label><input type="number" id="editOther" min="0"></div>
            <div class="btn-group">
                <button class="btn-save" onclick="saveEditSale()">💾 Save Changes</button>
                <button class="btn-cancel" onclick="closeEditSaleModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Edit Target Modal -->
    <div id="editTargetModal" class="modal">
        <div class="modal-content">
            <h2>🎯 Set Target</h2>
            <input type="hidden" id="targetAgentId">
            <div class="form-group"><label>Agent</label><input type="text" id="targetAgentName" readonly></div>
            <div class="form-group"><label>Monthly Target (Total Sales)</label><input type="number" id="targetMonthly" min="0"></div>
            <div class="form-group"><label>Credit Score Target</label><input type="number" id="targetCreditScore" min="0"></div>
            <div class="form-group"><label>Working Days</label><input type="number" id="targetWorkingDays" min="1" max="31" value="22"></div>
            <div class="form-group"><label>Daily Target (auto-calculated)</label><input type="text" id="targetDaily" readonly style="background:var(--cream);"></div>
            <div class="btn-group">
                <button class="btn-save" onclick="saveTarget()">💾 Save Target</button>
                <button class="btn-cancel" onclick="closeEditTargetModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Set All Targets Modal -->
    <div id="setTargetsModal" class="modal">
        <div class="modal-content" style="max-width:600px;">
            <h2>🎯 Set Monthly Targets for All Agents</h2>
            <p style="color:var(--ink-soft);font-size:13px;margin-bottom:12px;">Set targets for <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></p>
            <div class="form-group">
                <label>Default Monthly Target (for all agents without individual target)</label>
                <input type="number" id="bulkTarget" min="0" value="<?php echo $adminTarget['total_team_target'] ?? 0; ?>" placeholder="Enter target number">
            </div>
            <div class="form-group">
                <label>Default Working Days</label>
                <input type="number" id="bulkWorkingDays" min="1" max="31" value="22">
            </div>
            <div class="form-group">
                <label>Team Total Target (admin overview)</label>
                <input type="number" id="teamTargetInput" min="0" value="<?php echo $adminTarget['total_team_target'] ?? 0; ?>">
            </div>
            <div class="btn-group">
                <button class="btn-save" onclick="saveBulkTargets()">💾 Save All Targets</button>
                <button class="btn-cancel" onclick="closeSetTargetsModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector(`.tab[onclick*="${tabName}"]`).classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
        }

        // Team Chart
        const teamCtx = document.getElementById('teamChart').getContext('2d');
        new Chart(teamCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartAgentNames); ?>,
                datasets: [
                    {
                        label: 'Actual Sales',
                        data: <?php echo json_encode($chartAgentSales); ?>,
                        backgroundColor: '#c9962f',
                        borderRadius: 6
                    },
                    {
                        label: 'Target',
                        data: <?php echo json_encode($chartAgentTargets); ?>,
                        backgroundColor: 'rgba(12,26,56,.15)',
                        borderColor: '#0c1a38',
                        borderWidth: 2,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });

        // Approve/Reject Sale
        function approveSale(id) {
            if (!confirm('Approve this sale entry?')) return;
            salesAction('approve_sale', { sale_id: id });
        }
        function rejectSale(id) {
            if (!confirm('Reject this sale entry?')) return;
            salesAction('reject_sale', { sale_id: id });
        }
        function salesAction(action, data) {
            fetch('/raj/api/sales-api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action, ...data })
            }).then(r => r.json()).then(d => {
                if (d.success) location.reload();
                else alert('Error: ' + d.message);
            });
        }

        // Edit Sale Modal
        function editSale(id, agentId, date, cs, cstrong, kikoff, other) {
            document.getElementById('editSaleId').value = id;
            document.getElementById('editAgentId').value = agentId;
            document.getElementById('editSaleDate').value = date;
            document.getElementById('editCreditScore').value = cs;
            document.getElementById('editCreditStrong').value = cstrong;
            document.getElementById('editKikoff').value = kikoff;
            document.getElementById('editOther').value = other;
            document.getElementById('editSaleModal').classList.add('show');
        }
        function closeEditSaleModal() {
            document.getElementById('editSaleModal').classList.remove('show');
        }
        function saveEditSale() {
            const data = {
                sale_id: document.getElementById('editSaleId').value,
                credit_score: document.getElementById('editCreditScore').value,
                credit_strong: document.getElementById('editCreditStrong').value,
                kikoff: document.getElementById('editKikoff').value,
                other_sales: document.getElementById('editOther').value,
                sale_date: document.getElementById('editSaleDate').value
            };
            salesAction('edit_sale', data);
        }

        // Edit Target Modal
        function editTarget(aid, name, monthly, csTarget, workingDays) {
            document.getElementById('targetAgentId').value = aid;
            document.getElementById('targetAgentName').value = name;
            document.getElementById('targetMonthly').value = monthly;
            document.getElementById('targetCreditScore').value = csTarget;
            document.getElementById('targetWorkingDays').value = workingDays;
            updateDailyTarget();
            document.getElementById('editTargetModal').classList.add('show');
        }
        function closeEditTargetModal() {
            document.getElementById('editTargetModal').classList.remove('show');
        }
        function updateDailyTarget() {
            const monthly = parseInt(document.getElementById('targetMonthly').value) || 0;
            const days = parseInt(document.getElementById('targetWorkingDays').value) || 22;
            document.getElementById('targetDaily').value = days > 0 ? (monthly / days).toFixed(1) : '0';
        }
        document.getElementById('targetMonthly').addEventListener('input', updateDailyTarget);
        document.getElementById('targetWorkingDays').addEventListener('input', updateDailyTarget);
        function saveTarget() {
            const data = {
                agent_id: document.getElementById('targetAgentId').value,
                month: '<?php echo $selectedMonth; ?>',
                monthly_target: document.getElementById('targetMonthly').value,
                credit_score_target: document.getElementById('targetCreditScore').value,
                working_days: document.getElementById('targetWorkingDays').value
            };
            salesAction('set_target', data);
        }

        // Bulk Targets Modal
        function openSetTargetModal() {
            document.getElementById('setTargetsModal').classList.add('show');
        }
        function closeSetTargetsModal() {
            document.getElementById('setTargetsModal').classList.remove('show');
        }
        function saveBulkTargets() {
            const data = {
                month: '<?php echo $selectedMonth; ?>',
                bulk_target: document.getElementById('bulkTarget').value,
                working_days: document.getElementById('bulkWorkingDays').value,
                team_target: document.getElementById('teamTargetInput').value
            };
            salesAction('set_bulk_targets', data);
        }

        // Report Actions
        function approveReport(id) {
            if (!confirm('Approve and send this report?')) return;
            salesAction('approve_report', { request_id: id });
        }
        function rejectReport(id) {
            if (!confirm('Reject this report request?')) return;
            salesAction('reject_report', { request_id: id });
        }

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('show');
            });
        });
    </script>
</body>
</html>