<?php
// /raj/admin/targets-manage.php
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
$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;

// ============================================================
// HANDLE FORM SUBMISSIONS
// ============================================================
$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ========== SAVE TEAM TARGET ==========
    if ($action === 'save_team_target') {
        $teamTarget = (int)($_POST['team_target'] ?? 0);
        $csTeamTarget = (int)($_POST['cs_team_target'] ?? 0);
        
        $result = executeQuery(
            "INSERT INTO admin_targets (month_year, total_team_target, credit_score_team_target) 
             VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE total_team_target = ?, credit_score_team_target = ?, updated_at = NOW()",
            [$selectedMonth, $teamTarget, $csTeamTarget, $teamTarget, $csTeamTarget]
        );
        
        $message = $result !== false ? '✅ Team target saved successfully!' : '❌ Error saving team target';
    }
    
    // ========== SAVE SINGLE AGENT TARGET ==========
    if ($action === 'save_agent_target') {
        $agentId = $_POST['agent_id'] ?? '';
        $monthlyTarget = (int)($_POST['monthly_target'] ?? 0);
        $creditScoreTarget = (int)($_POST['credit_score_target'] ?? 0);
        $creditStrongTarget = (int)($_POST['credit_strong_target'] ?? 0);
        $kikoffTarget = (int)($_POST['kikoff_target'] ?? 0);
        $otherTarget = (int)($_POST['other_target'] ?? 0);
        $workingDays = (int)($_POST['working_days'] ?? 22);
        $weeklyTarget = (int)($_POST['weekly_target'] ?? 0);
        $dailyTarget = $workingDays > 0 ? round($monthlyTarget / $workingDays, 2) : 0;
        
        if (!empty($agentId)) {
            $result = executeQuery(
                "INSERT INTO sales_targets (agent_id, month_year, monthly_target, credit_score_target, credit_strong_target, kikoff_target, other_target, working_days, weekly_target, daily_target, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    monthly_target = ?, credit_score_target = ?, credit_strong_target = ?, 
                    kikoff_target = ?, other_target = ?, working_days = ?, 
                    weekly_target = ?, daily_target = ?, updated_at = NOW()",
                [$agentId, $selectedMonth, $monthlyTarget, $creditScoreTarget, $creditStrongTarget, $kikoffTarget, $otherTarget, $workingDays, $weeklyTarget, $dailyTarget, $adminId,
                 $monthlyTarget, $creditScoreTarget, $creditStrongTarget, $kikoffTarget, $otherTarget, $workingDays, $weeklyTarget, $dailyTarget]
            );
            
            $message = $result !== false ? '✅ Agent target saved!' : '❌ Error saving target';
        }
    }
    
    // ========== BULK SET ALL AGENTS ==========
    if ($action === 'bulk_set_targets') {
        $bulkMonthly = (int)($_POST['bulk_monthly'] ?? 0);
        $bulkWorkingDays = (int)($_POST['bulk_working_days'] ?? 22);
        $bulkWeekly = (int)($_POST['bulk_weekly'] ?? 0);
        $bulkDaily = $bulkWorkingDays > 0 ? round($bulkMonthly / $bulkWorkingDays, 2) : 0;
        
        $agents = executeQuery("SELECT agent_id FROM agents WHERE onboarding_completed = 1");
        $count = 0;
        if ($agents) {
            while ($agent = $agents->fetch_assoc()) {
                executeQuery(
                    "INSERT INTO sales_targets (agent_id, month_year, monthly_target, working_days, weekly_target, daily_target, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE monthly_target = ?, working_days = ?, weekly_target = ?, daily_target = ?, updated_at = NOW()",
                    [$agent['agent_id'], $selectedMonth, $bulkMonthly, $bulkWorkingDays, $bulkWeekly, $bulkDaily, $adminId,
                     $bulkMonthly, $bulkWorkingDays, $bulkWeekly, $bulkDaily]
                );
                $count++;
            }
        }
        
        $message = "✅ Bulk target set for $count agents!";
    }
}

// ============================================================
// GET DATA
// ============================================================

// Team Target
$teamTargetQuery = executeQuery("SELECT * FROM admin_targets WHERE month_year = ?", [$selectedMonth]);
$teamTarget = $teamTargetQuery ? $teamTargetQuery->fetch_assoc() : null;
$teamTargetValue = (int)($teamTarget['total_team_target'] ?? 0);
$csTeamTargetValue = (int)($teamTarget['credit_score_team_target'] ?? 0);

// All agents with their targets
$agentsQuery = executeQuery("
    SELECT a.agent_id, a.full_name, a.candidate_photo_path, a.shift_type,
           COALESCE(t.monthly_target, 0) as monthly_target,
           COALESCE(t.credit_score_target, 0) as credit_score_target,
           COALESCE(t.credit_strong_target, 0) as credit_strong_target,
           COALESCE(t.kikoff_target, 0) as kikoff_target,
           COALESCE(t.other_target, 0) as other_target,
           COALESCE(t.working_days, 22) as working_days,
           COALESCE(t.weekly_target, 0) as weekly_target,
           COALESCE(t.daily_target, 0) as daily_target,
           COALESCE(s.total_sales, 0) as actual_sales,
           COALESCE(s.credit_score, 0) as actual_cs
    FROM agents a
    LEFT JOIN sales_targets t ON a.agent_id = t.agent_id AND t.month_year = ?
    LEFT JOIN (
        SELECT agent_id, SUM(total_sales) as total_sales, SUM(credit_score) as credit_score 
        FROM daily_sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ? GROUP BY agent_id
    ) s ON a.agent_id = s.agent_id
    WHERE a.onboarding_completed = 1
    ORDER BY a.full_name ASC
", [$selectedMonth, $selectedMonth]);

$agents = [];
$totalMonthlyTarget = 0;
$totalActualSales = 0;
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
        $totalMonthlyTarget += (int)$row['monthly_target'];
        $totalActualSales += (int)$row['actual_sales'];
    }
}

// Working days calculation
function getWorkingDays($monthYear) {
    $days = date('t', strtotime($monthYear . '-01'));
    $wd = 0;
    for ($d = 1; $d <= $days; $d++) {
        if (date('N', strtotime(sprintf('%s-%02d', $monthYear, $d))) < 6) $wd++;
    }
    return $wd;
}
$totalWorkingDays = getWorkingDays($selectedMonth);

// Month navigation
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Target Management - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --gold-300: #e9c877; --gold-100: #fdf1de;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f;
            --warn: #9a6a10; --info: #29538c; --purple: #7c3aed;
            --radius: 18px; --radius-sm: 12px; --radius-xs: 8px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-300); font-weight: 600; }

        .content { max-width: 1400px; margin: 0 auto; padding: 22px 28px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 18px; }

        .alert {
            padding: 14px 20px; border-radius: var(--radius-sm); margin-bottom: 16px;
            font-size: 13px; font-weight: 500;
        }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        /* Month Nav */
        .month-bar {
            display: flex; gap: 8px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;
        }
        .month-bar select {
            padding: 10px 18px; border: 2px solid var(--line); border-radius: 25px;
            font-family: 'Inter', sans-serif; font-size: 14px; background: var(--paper); cursor: pointer; font-weight: 500;
        }
        .month-bar .nav-btn {
            padding: 10px 18px; background: var(--paper); border: 1px solid var(--line);
            border-radius: 25px; text-decoration: none; color: var(--ink); font-size: 13px;
        }
        .month-bar .nav-btn:hover { background: var(--cream); }

        /* Grid Layout */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .card h3 {
            font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950);
            margin-bottom: 16px; display: flex; align-items: center; gap: 10px;
        }
        .card h3 i { color: var(--gold-500); }

        /* Form Styles */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 2px solid var(--line);
            border-radius: var(--radius-sm); font-family: 'Inter', sans-serif; font-size: 13px;
            background: var(--paper); transition: border-color .3s;
        }
        .form-group input:focus, .form-group select:focus { border-color: var(--gold-500); outline: none; }
        .form-group .input-hint { font-size: 10px; color: var(--ink-soft); margin-top: 4px; }
        .form-group .auto-calc {
            background: var(--cream); color: var(--navy-950); font-weight: 600;
        }

        .btn {
            padding: 10px 22px; border: none; border-radius: 25px; font-weight: 600;
            font-size: 13px; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 8px; transition: transform .2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-primary { background: var(--navy-800); color: #fff; }
        .btn-gold { background: var(--gold-500); color: var(--navy-950); }
        .btn-outline { background: var(--paper); color: var(--ink-soft); border: 2px solid var(--line); }

        /* Team Target Card */
        .team-target-card {
            background: linear-gradient(135deg, #fffdf5, #fff9e6);
            border: 2px solid var(--gold-500);
        }
        .team-target-card .big-number {
            font-family: 'Fraunces', serif; font-size: 48px; font-weight: 700;
            color: var(--navy-950); text-align: center; margin: 10px 0;
        }
        .team-target-card .sub-info {
            text-align: center; font-size: 13px; color: var(--ink-soft);
        }

        /* Stats Row */
        .stats-mini {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px; margin-bottom: 10px;
        }
        .stat-mini {
            background: var(--cream); padding: 12px; border-radius: var(--radius-sm);
            text-align: center; border: 1px solid var(--line);
        }
        .stat-mini .sv { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 700; color: var(--navy-950); }
        .stat-mini .sl { font-size: 9px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }

        /* Agent Target Cards */
        .agent-targets-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
        }
        .agent-target-card {
            background: var(--paper); border-radius: var(--radius); padding: 18px 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: transform .2s;
        }
        .agent-target-card:hover { transform: translateY(-2px); }
        .agent-target-card .at-header {
            display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
            padding-bottom: 10px; border-bottom: 1px solid var(--line);
        }
        .agent-target-card .at-photo {
            width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--gold-500);
        }
        .agent-target-card .at-photo-placeholder {
            width: 40px; height: 40px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .agent-target-card .at-name { font-weight: 600; font-size: 14px; color: var(--navy-950); }
        .agent-target-card .at-id { font-size: 10px; color: var(--ink-soft); }
        .agent-target-card .at-targets {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .agent-target-card .at-item {
            text-align: center; padding: 8px; background: var(--cream);
            border-radius: var(--radius-xs); border: 1px solid var(--line);
        }
        .agent-target-card .at-item .at-val {
            font-family: 'Fraunces', serif; font-size: 18px; font-weight: 700; color: var(--navy-950);
        }
        .agent-target-card .at-item .at-lbl {
            font-size: 9px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600;
        }
        .agent-target-card .at-progress {
            margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line);
        }
        .agent-target-card .at-progress .prog-bar {
            background: var(--cream); border-radius: 20px; height: 8px; overflow: hidden;
        }
        .agent-target-card .at-progress .prog-fill {
            height: 100%; border-radius: 20px; transition: width .5s;
        }

        /* Modal */
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 28px;
            max-width: 550px; width: 94%; max-height: 85vh; overflow-y: auto;
            box-shadow: 0 20px 50px rgba(12,26,56,.2);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); margin-bottom: 16px; }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 40px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        .weekly-highlight {
            background: linear-gradient(135deg, #e6eefb, #d0e0f8);
            border: 2px solid var(--info); border-radius: var(--radius-sm);
            padding: 14px; text-align: center; margin-top: 10px;
        }
        .weekly-highlight .wh-val { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--info); }
        .weekly-highlight .wh-lbl { font-size: 10px; color: var(--info); text-transform: uppercase; font-weight: 600; }

        @media (max-width: 1024px) { .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .agent-targets-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">🎯 Target Management</div>
        <div class="nav">
            <a href="db.php"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="dbsales.php"><i class="fas fa-chart-pie"></i> Analytics</a>
            <a href="agents-manage.php"><i class="fas fa-users"></i> Agents</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="targets-manage.php" class="active"><i class="fas fa-bullseye"></i> Targets</a>
            <a href="attendance-manage.php"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">🎯 Target Management</h1>
        <p class="page-subtitle">Set team targets, agent monthly/weekly targets for <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></p>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Month Navigation -->
        <div class="month-bar">
            <a href="?month=<?php echo $prevMonth; ?>" class="nav-btn">← Previous</a>
            <select onchange="window.location.href='?month='+this.value">
                <?php for ($m=1;$m<=12;$m++): $v=sprintf('%04d-%02d',date('Y'),$m); ?>
                <option value="<?php echo $v; ?>" <?php echo $v===$selectedMonth?'selected':''; ?>><?php echo date('F Y',strtotime($v.'-01')); ?></option>
                <?php endfor; ?>
            </select>
            <a href="?month=<?php echo $nextMonth; ?>" class="nav-btn">Next →</a>
            <span style="margin-left:auto;font-size:12px;color:var(--ink-soft);">
                📅 Working Days: <strong><?php echo $totalWorkingDays; ?></strong> (Mon-Fri)
            </span>
        </div>

        <!-- ============================================================ -->
        <!-- SECTION 1: TEAM TARGET -->
        <!-- ============================================================ -->
        <div class="grid-2">
            <div class="card team-target-card">
                <h3><i class="fas fa-users"></i> Team Monthly Target</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_team_target">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Team Sales Target</label>
                            <input type="number" name="team_target" value="<?php echo $teamTargetValue; ?>" min="0" placeholder="e.g. 500" required>
                            <div class="input-hint">Total sales target for all agents combined</div>
                        </div>
                        <div class="form-group">
                            <label>Credit Score Team Target</label>
                            <input type="number" name="cs_team_target" value="<?php echo $csTeamTargetValue; ?>" min="0" placeholder="e.g. 200">
                            <div class="input-hint">Main KPI target for team</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-gold">💾 Save Team Target</button>
                </form>

                <!-- Quick Stats -->
                <div class="stats-mini" style="margin-top:16px;">
                    <div class="stat-mini">
                        <div class="sv"><?php echo number_format($teamTargetValue); ?></div>
                        <div class="sl">Team Target</div>
                    </div>
                    <div class="stat-mini">
                        <div class="sv"><?php echo number_format($totalMonthlyTarget); ?></div>
                        <div class="sl">Sum of Agent Targets</div>
                    </div>
                    <div class="stat-mini">
                        <div class="sv"><?php echo number_format($totalActualSales); ?></div>
                        <div class="sl">Actual Sales</div>
                    </div>
                    <div class="stat-mini">
                        <div class="sv"><?php echo $teamTargetValue > 0 ? round(($totalActualSales/$teamTargetValue)*100) : 0; ?>%</div>
                        <div class="sl">Team Progress</div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SECTION 2: BULK SET ALL AGENTS -->
            <!-- ============================================================ -->
            <div class="card">
                <h3><i class="fas fa-layer-group"></i> Bulk Set All Agents</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_set_targets">
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Monthly Target</label>
                            <input type="number" name="bulk_monthly" value="0" min="0" placeholder="e.g. 100" required>
                        </div>
                        <div class="form-group">
                            <label>Weekly Target</label>
                            <input type="number" name="bulk_weekly" value="0" min="0" placeholder="e.g. 25">
                            <div class="input-hint">Auto-calculated if blank</div>
                        </div>
                        <div class="form-group">
                            <label>Working Days</label>
                            <input type="number" name="bulk_working_days" value="22" min="1" max="31">
                        </div>
                    </div>
                    <div class="input-hint" style="margin-bottom:10px;">
                        💡 Daily target = Monthly ÷ Working Days | Weekly = Monthly ÷ 4
                    </div>
                    <button type="submit" class="btn btn-primary">⚡ Apply to All Agents</button>
                </form>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SECTION 3: INDIVIDUAL AGENT TARGETS -->
        <!-- ============================================================ -->
        <div class="card" style="margin-bottom:20px;">
            <h3><i class="fas fa-user-check"></i> Individual Agent Targets</h3>
            <p style="font-size:12px;color:var(--ink-soft);margin-bottom:14px;">
                Click on any agent card to edit their targets. Changes save automatically.
            </p>

            <div class="agent-targets-grid">
                <?php foreach ($agents as $agent): 
                    $aid = $agent['agent_id'];
                    $mt = (int)$agent['monthly_target'];
                    $wt = (int)$agent['weekly_target'];
                    $dt = (float)$agent['daily_target'];
                    $wd = (int)$agent['working_days'];
                    $cs = (int)$agent['credit_score_target'];
                    $cst = (int)$agent['credit_strong_target'];
                    $ko = (int)$agent['kikoff_target'];
                    $ot = (int)$agent['other_target'];
                    $actual = (int)$agent['actual_sales'];
                    $pct = $mt > 0 ? min(100, round(($actual / $mt) * 100)) : 0;
                    
                    $photoPath = $agent['candidate_photo_path'] ?? '';
                    $photoUrl = '';
                    if (!empty($photoPath)) {
                        $fullPath = __DIR__ . '/../storage/agents/' . $aid . '/' . $photoPath;
                        if (file_exists($fullPath)) {
                            $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $aid . '&file=' . urlencode($photoPath);
                        }
                    }
                ?>
                <div class="agent-target-card" onclick="openEditModal('<?php echo $aid; ?>', '<?php echo htmlspecialchars(addslashes($agent['full_name'])); ?>', <?php echo $mt; ?>, <?php echo $cs; ?>, <?php echo $cst; ?>, <?php echo $ko; ?>, <?php echo $ot; ?>, <?php echo $wd; ?>, <?php echo $wt; ?>)" style="cursor:pointer;">
                    <div class="at-header">
                        <?php if (!empty($photoUrl)): ?>
                            <img src="<?php echo $photoUrl; ?>" class="at-photo" alt="Photo">
                        <?php else: ?>
                            <div class="at-photo-placeholder">👤</div>
                        <?php endif; ?>
                        <div>
                            <div class="at-name"><?php echo htmlspecialchars($agent['full_name']); ?></div>
                            <div class="at-id"><?php echo $aid; ?> | <?php echo ucfirst($agent['shift_type'] ?? 'day'); ?></div>
                        </div>
                    </div>

                    <div class="at-targets">
                        <div class="at-item">
                            <div class="at-val"><?php echo number_format($mt); ?></div>
                            <div class="at-lbl">📅 Monthly</div>
                        </div>
                        <div class="at-item">
                            <div class="at-val"><?php echo number_format($wt); ?></div>
                            <div class="at-lbl">📆 Weekly</div>
                        </div>
                        <div class="at-item">
                            <div class="at-val"><?php echo number_format($dt, 1); ?></div>
                            <div class="at-lbl">📌 Daily</div>
                        </div>
                        <div class="at-item">
                            <div class="at-val"><?php echo $wd; ?></div>
                            <div class="at-lbl">💼 Work Days</div>
                        </div>
                    </div>

                    <!-- Weekly Target Highlight -->
                    <?php if ($wt > 0): ?>
                    <div class="weekly-highlight">
                        <div class="wh-val">🎯 <?php echo number_format($wt); ?> / week</div>
                        <div class="wh-lbl">Weekly Target (<?php echo ceil($totalWorkingDays / 5); ?> weeks)</div>
                    </div>
                    <?php endif; ?>

                    <!-- Progress -->
                    <div class="at-progress">
                        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--ink-soft);margin-bottom:4px;">
                            <span>Progress</span>
                            <span><?php echo number_format($actual); ?> / <?php echo number_format($mt); ?></span>
                        </div>
                        <div class="prog-bar">
                            <div class="prog-fill" style="width:<?php echo $pct; ?>%; background: <?php echo $pct >= 100 ? 'var(--success)' : ($pct >= 50 ? 'var(--gold-500)' : 'var(--danger)'); ?>;"></div>
                        </div>
                        <div style="text-align:right;font-size:10px;font-weight:600;color:var(--navy-950);margin-top:2px;"><?php echo $pct; ?>%</div>
                    </div>

                    <div style="text-align:center;margin-top:8px;">
                        <button class="btn btn-outline" style="font-size:10px;padding:5px 14px;" onclick="event.stopPropagation(); openEditModal('<?php echo $aid; ?>', '<?php echo htmlspecialchars(addslashes($agent['full_name'])); ?>', <?php echo $mt; ?>, <?php echo $cs; ?>, <?php echo $cst; ?>, <?php echo $ko; ?>, <?php echo $ot; ?>, <?php echo $wd; ?>, <?php echo $wt; ?>)">
                            ✏️ Edit Target
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- EDIT AGENT TARGET MODAL -->
    <!-- ============================================================ -->
    <div id="editTargetModal" class="modal">
        <div class="modal-content">
            <h2>✏️ Edit Agent Target</h2>
            <form method="POST" id="editTargetForm">
                <input type="hidden" name="action" value="save_agent_target">
                <input type="hidden" name="agent_id" id="editAgentId">
                
                <div class="form-group">
                    <label>Agent</label>
                    <input type="text" id="editAgentName" readonly style="background:var(--cream);font-weight:600;">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>📅 Monthly Target *</label>
                        <input type="number" name="monthly_target" id="editMonthly" min="0" required oninput="calcTargets()">
                    </div>
                    <div class="form-group">
                        <label>💼 Working Days</label>
                        <input type="number" name="working_days" id="editWorkDays" min="1" max="31" value="22" oninput="calcTargets()">
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>📆 Weekly Target</label>
                        <input type="number" name="weekly_target" id="editWeekly" min="0" class="auto-calc" readonly>
                        <div class="input-hint">Auto: Monthly ÷ 4</div>
                    </div>
                    <div class="form-group">
                        <label>📌 Daily Target</label>
                        <input type="text" id="editDaily" class="auto-calc" readonly>
                        <div class="input-hint">Auto: Monthly ÷ Days</div>
                    </div>
                    <div class="form-group">
                        <label>📊 Weeks in Month</label>
                        <input type="text" id="editWeeks" class="auto-calc" readonly>
                        <div class="input-hint">Working days ÷ 5</div>
                    </div>
                </div>

                <h4 style="font-size:13px;color:var(--navy-950);margin:12px 0 8px;">Sales Type Targets (Optional)</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>⭐ Credit Score</label>
                        <input type="number" name="credit_score_target" id="editCS" min="0">
                    </div>
                    <div class="form-group">
                        <label>🛡️ Credit Strong</label>
                        <input type="number" name="credit_strong_target" id="editCStrong" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>🏦 KikOff Bank</label>
                        <input type="number" name="kikoff_target" id="editKO" min="0">
                    </div>
                    <div class="form-group">
                        <label>📦 Other</label>
                        <input type="number" name="other_target" id="editOther" min="0">
                    </div>
                </div>

                <div class="btn-group" style="display:flex;gap:10px;margin-top:16px;">
                    <button type="submit" class="btn btn-primary">💾 Save Target</button>
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        // Open edit modal
        function openEditModal(agentId, name, monthly, cs, cstrong, ko, other, workDays, weekly) {
            document.getElementById('editAgentId').value = agentId;
            document.getElementById('editAgentName').value = name;
            document.getElementById('editMonthly').value = monthly;
            document.getElementById('editCS').value = cs;
            document.getElementById('editCStrong').value = cstrong;
            document.getElementById('editKO').value = ko;
            document.getElementById('editOther').value = other;
            document.getElementById('editWorkDays').value = workDays;
            
            calcTargets();
            
            document.getElementById('editTargetModal').classList.add('show');
        }
        
        function closeEditModal() {
            document.getElementById('editTargetModal').classList.remove('show');
        }
        
        // Auto calculate targets
        function calcTargets() {
            const monthly = parseInt(document.getElementById('editMonthly').value) || 0;
            const workDays = parseInt(document.getElementById('editWorkDays').value) || 22;
            const weeks = Math.ceil(workDays / 5);
            
            const daily = workDays > 0 ? (monthly / workDays).toFixed(1) : '0';
            const weekly = weeks > 0 ? Math.round(monthly / weeks) : 0;
            
            document.getElementById('editDaily').value = daily;
            document.getElementById('editWeekly').value = weekly;
            document.getElementById('editWeeks').value = weeks + ' weeks';
        }
        
        // Close modal on outside click
        document.getElementById('editTargetModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
    </script>
</body>
</html>