<?php
// /raj/admin/agent-comparison.php
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
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));

// Get selected agents
$agent1Id = $_GET['agent1'] ?? '';
$agent2Id = $_GET['agent2'] ?? '';

// Get all agents for dropdown
$allAgents = [];
$allQuery = executeQuery("SELECT agent_id, full_name, candidate_photo_path, shift_type, work_location FROM agents WHERE onboarding_completed = 1 ORDER BY full_name");
if ($allQuery) {
    while ($row = $allQuery->fetch_assoc()) {
        $allAgents[] = $row;
    }
}

// Function to get agent photo URL
function getAgentPhoto($agentId, $photoPath) {
    if (!empty($photoPath)) {
        $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath;
        if (file_exists($fullPath)) {
            return '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath);
        }
    }
    return '';
}

// Function to get agent full data
function getAgentComparisonData($agentId, $monthYear) {
    $data = [
        'info' => null,
        'sales' => ['total' => 0, 'cs' => 0, 'cstrong' => 0, 'ko' => 0, 'other' => 0],
        'target' => null,
        'attendance' => ['present' => 0, 'absent' => 0, 'half_day' => 0, 'holiday' => 0],
        'daily_sales' => [],
        'rank' => 0,
        'total_agents' => 0
    ];
    
    // Agent info
    $info = executeQuery("SELECT * FROM agents WHERE agent_id = ?", [$agentId]);
    if ($info && $info->num_rows > 0) {
        $data['info'] = $info->fetch_assoc();
    }
    
    // Monthly sales
    $sales = executeQuery(
        "SELECT COALESCE(SUM(total_sales),0) as total, COALESCE(SUM(credit_score),0) as cs,
                COALESCE(SUM(credit_strong),0) as cstrong, COALESCE(SUM(kikoff),0) as ko,
                COALESCE(SUM(other_sales),0) as other
         FROM daily_sales WHERE agent_id = ? AND DATE_FORMAT(sale_date, '%Y-%m') = ?",
        [$agentId, $monthYear]
    );
    if ($sales && $sales->num_rows > 0) {
        $data['sales'] = $sales->fetch_assoc();
    }
    
    // Target
    $target = executeQuery("SELECT * FROM sales_targets WHERE agent_id = ? AND month_year = ?", [$agentId, $monthYear]);
    if ($target && $target->num_rows > 0) {
        $data['target'] = $target->fetch_assoc();
    }
    
    // Daily sales for chart
    $daily = executeQuery(
        "SELECT sale_date, total_sales, credit_score, credit_strong, kikoff, other_sales 
         FROM daily_sales WHERE agent_id = ? AND DATE_FORMAT(sale_date, '%Y-%m') = ? ORDER BY sale_date",
        [$agentId, $monthYear]
    );
    if ($daily) {
        while ($row = $daily->fetch_assoc()) {
            $data['daily_sales'][$row['sale_date']] = $row;
        }
    }
    
    // Attendance
    $att = executeQuery(
        "SELECT attendance_status, COUNT(*) as cnt FROM attendance 
         WHERE agent_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? GROUP BY attendance_status",
        [$agentId, $monthYear]
    );
    if ($att) {
        while ($row = $att->fetch_assoc()) {
            $data['attendance'][$row['attendance_status']] = (int)$row['cnt'];
        }
    }
    
    // Rank
    $rankQ = executeQuery("
        SELECT COUNT(*) + 1 as rank FROM (
            SELECT agent_id, SUM(total_sales) as t FROM daily_sales 
            WHERE DATE_FORMAT(sale_date, '%Y-%m') = ? GROUP BY agent_id
        ) s WHERE s.t > ?
    ", [$monthYear, (int)$data['sales']['total']]);
    if ($rankQ && $rankQ->num_rows > 0) {
        $data['rank'] = (int)$rankQ->fetch_assoc()['rank'];
    }
    
    // Total agents
    $totalQ = executeQuery("SELECT COUNT(*) as c FROM agents WHERE onboarding_completed = 1");
    if ($totalQ && $totalQ->num_rows > 0) {
        $data['total_agents'] = (int)$totalQ->fetch_assoc()['c'];
    }
    
    return $data;
}

$agent1 = null;
$agent2 = null;
$comparisonReady = false;

if (!empty($agent1Id) && !empty($agent2Id) && $agent1Id !== $agent2Id) {
    $agent1 = getAgentComparisonData($agent1Id, $selectedMonth);
    $agent2 = getAgentComparisonData($agent2Id, $selectedMonth);
    $comparisonReady = true;
}

// Chart data
$chartLabels = [];
$chart1Sales = [];
$chart2Sales = [];
$chart1CS = [];
$chart2CS = [];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%s-%02d', $selectedMonth, $d);
    $chartLabels[] = $d;
    
    if ($comparisonReady) {
        $chart1Sales[] = (int)($agent1['daily_sales'][$date]['total_sales'] ?? 0);
        $chart2Sales[] = (int)($agent2['daily_sales'][$date]['total_sales'] ?? 0);
        $chart1CS[] = (int)($agent1['daily_sales'][$date]['credit_score'] ?? 0);
        $chart2CS[] = (int)($agent2['daily_sales'][$date]['credit_score'] ?? 0);
    }
}

// Navigation
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Comparison - Admin</title>
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
            --agent1-color: #c9962f; --agent2-color: #29538c;
            --radius: 18px; --radius-sm: 12px;
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
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-300); }

        .content { max-width: 1500px; margin: 0 auto; padding: 22px 28px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 6px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 18px; }

        /* Selection Bar */
        .selection-bar {
            background: var(--paper); border-radius: var(--radius); padding: 20px 24px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .selection-bar select {
            padding: 10px 16px; border: 2px solid var(--line); border-radius: 25px;
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper);
            min-width: 200px; cursor: pointer;
        }
        .selection-bar select:focus { border-color: var(--gold-500); outline: none; }
        .selection-bar .vs-badge {
            font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700;
            color: var(--gold-500); padding: 8px 16px; background: var(--gold-100);
            border-radius: 50%; width: 50px; height: 50px; display: flex;
            align-items: center; justify-content: center; flex-shrink: 0;
        }
        .selection-bar .btn-compare {
            padding: 10px 24px; background: var(--navy-800); color: #fff;
            border: none; border-radius: 25px; font-weight: 600; cursor: pointer;
            font-family: 'Inter', sans-serif; font-size: 13px;
        }
        .selection-bar .btn-compare:hover { background: var(--navy-950); }
        .selection-bar .month-nav { display: flex; gap: 6px; margin-left: auto; }
        .selection-bar .month-nav a {
            padding: 8px 14px; background: var(--cream); border: 1px solid var(--line);
            border-radius: 20px; text-decoration: none; color: var(--ink); font-size: 12px;
        }

        /* Profile Cards */
        .compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .profile-card {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            text-align: center; position: relative; overflow: hidden;
        }
        .profile-card.agent1 { border-top: 5px solid var(--agent1-color); }
        .profile-card.agent2 { border-top: 5px solid var(--agent2-color); }
        .profile-card .photo {
            width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
            border: 4px solid; margin: 0 auto 12px;
        }
        .profile-card.agent1 .photo { border-color: var(--agent1-color); }
        .profile-card.agent2 .photo { border-color: var(--agent2-color); }
        .profile-card .photo-placeholder {
            width: 90px; height: 90px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: #bbb; border: 3px dashed var(--line);
            margin: 0 auto 12px;
        }
        .profile-card h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); }
        .profile-card .agent-id { font-size: 11px; color: var(--ink-soft); margin: 4px 0; }
        .profile-card .rank-badge {
            display: inline-block; padding: 6px 18px; border-radius: 20px;
            font-weight: 700; font-size: 13px; margin-top: 6px;
        }
        .profile-card .rank-badge.top { background: #fff9e6; color: #d4a017; }
        .profile-card .rank-badge.mid { background: #f0f0f0; color: var(--ink-soft); }
        .profile-card .badges { display: flex; gap: 6px; justify-content: center; margin-top: 8px; flex-wrap: wrap; }
        .profile-card .badge {
            padding: 4px 10px; border-radius: 12px; font-size: 10px; font-weight: 600;
        }
        .badge.shift { background: var(--gold-100); color: var(--gold-500); }
        .badge.location { background: #e6eefb; color: var(--info); }

        /* Stats Comparison */
        .stats-compare { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0; margin-bottom: 20px; }
        .stat-col {
            background: var(--paper); padding: 16px 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .stat-col.agent1-col { border-radius: var(--radius) 0 0 var(--radius); border-right: none; }
        .stat-col.agent2-col { border-radius: 0 var(--radius) var(--radius) 0; border-left: none; }
        .stat-col .stat-row {
            display: flex; justify-content: space-between; padding: 8px 0;
            border-bottom: 1px solid var(--line); font-size: 13px;
        }
        .stat-col .stat-row:last-child { border-bottom: none; }
        .stat-col .stat-val { font-weight: 700; font-family: 'Fraunces', serif; font-size: 16px; }
        .stat-col .stat-val.higher { color: var(--success); }
        .stat-col .stat-val.lower { color: var(--danger); }
        .winner-badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 9px; font-weight: 700; margin-left: 4px;
        }
        .winner-badge.win { background: #e4f4e8; color: var(--success); }

        /* Charts */
        .chart-card {
            background: var(--paper); border-radius: var(--radius); padding: 22px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .chart-card h3 {
            font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950);
            margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
        }
        .chart-wrap { position: relative; height: 320px; }
        canvas { width: 100% !important; }

        /* Attendance Comparison */
        .att-compare {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
        }
        .att-card {
            padding: 16px; border-radius: var(--radius-sm); text-align: center;
        }
        .att-card.agent1-att { background: #fdf8f0; border: 1px solid var(--agent1-color); }
        .att-card.agent2-att { background: #f0f4f8; border: 1px solid var(--agent2-color); }
        .att-card .att-val { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 700; }
        .att-card .att-lbl { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--ink-soft);
        }
        .empty-state i { font-size: 60px; color: #ddd; margin-bottom: 12px; display: block; }
        .empty-state h3 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); }

        @media (max-width: 1024px) {
            .compare-grid { grid-template-columns: 1fr; }
            .stats-compare { grid-template-columns: 1fr; }
            .stat-col.agent1-col, .stat-col.agent2-col { border-radius: var(--radius); border: 1px solid var(--line); }
        }
        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .selection-bar { flex-direction: column; align-items: stretch; }
            .selection-bar .month-nav { margin-left: 0; justify-content: center; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">⚔️ Agent Comparison</div>
        <div class="nav">
            <a href="db.php"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="dbsales.php"><i class="fas fa-chart-pie"></i> Analytics</a>
            <a href="agents-manage.php"><i class="fas fa-users"></i> Agents</a>
            <a href="agent-comparison.php" class="active"><i class="fas fa-balance-scale"></i> Compare</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="targets-manage.php"><i class="fas fa-bullseye"></i> Targets</a>
            <a href="attendance-manage.php"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">⚔️ Agent Comparison</h1>
        <p class="page-subtitle">Compare two agents side-by-side for <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></p>

        <!-- Selection Bar -->
        <form class="selection-bar" method="GET">
            <select name="agent1" required>
                <option value="">Select Agent 1</option>
                <?php foreach ($allAgents as $ag): ?>
                <option value="<?php echo $ag['agent_id']; ?>" <?php echo $agent1Id === $ag['agent_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ag['full_name']); ?> (<?php echo $ag['agent_id']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            
            <div class="vs-badge">VS</div>
            
            <select name="agent2" required>
                <option value="">Select Agent 2</option>
                <?php foreach ($allAgents as $ag): ?>
                <option value="<?php echo $ag['agent_id']; ?>" <?php echo $agent2Id === $ag['agent_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ag['full_name']); ?> (<?php echo $ag['agent_id']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            
            <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
            <button type="submit" class="btn-compare">🔍 Compare</button>
            
            <div class="month-nav">
                <a href="?agent1=<?php echo $agent1Id; ?>&agent2=<?php echo $agent2Id; ?>&month=<?php echo $prevMonth; ?>">← Prev</a>
                <span style="font-size:12px;font-weight:600;padding:8px;"><?php echo date('M Y', strtotime($selectedMonth . '-01')); ?></span>
                <a href="?agent1=<?php echo $agent1Id; ?>&agent2=<?php echo $agent2Id; ?>&month=<?php echo $nextMonth; ?>">Next →</a>
            </div>
        </form>

        <?php if ($comparisonReady): ?>
        
        <!-- Profile Cards -->
        <div class="compare-grid">
            <?php 
            $agents = [
                ['data' => $agent1, 'class' => 'agent1', 'color' => 'var(--agent1-color)'],
                ['data' => $agent2, 'class' => 'agent2', 'color' => 'var(--agent2-color)']
            ];
            foreach ($agents as $ag):
                $d = $ag['data'];
                $info = $d['info'];
                $photo = getAgentPhoto($info['agent_id'], $info['candidate_photo_path'] ?? '');
                $rankClass = $d['rank'] <= 3 ? 'top' : 'mid';
            ?>
            <div class="profile-card <?php echo $ag['class']; ?>">
                <?php if (!empty($photo)): ?>
                    <img src="<?php echo $photo; ?>" class="photo" alt="Photo">
                <?php else: ?>
                    <div class="photo-placeholder">👤</div>
                <?php endif; ?>
                <h2><?php echo htmlspecialchars($info['full_name']); ?></h2>
                <div class="agent-id"><?php echo $info['agent_id']; ?></div>
                <div class="rank-badge <?php echo $rankClass; ?>">
                    🏆 Rank #<?php echo $d['rank']; ?> of <?php echo $d['total_agents']; ?>
                </div>
                <div class="badges">
                    <span class="badge shift"><i class="fas <?php echo ($info['shift_type']??'day')==='day'?'fa-sun':'fa-moon'; ?>"></i> <?php echo ucfirst($info['shift_type']??'day'); ?></span>
                    <span class="badge location"><i class="fas <?php echo ($info['work_location']??'office')==='office'?'fa-building':'fa-home'; ?>"></i> <?php echo ucfirst($info['work_location']??'office'); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Stats Comparison Table -->
        <div class="stats-compare">
            <?php 
            $statRows = [
                ['label' => 'Total Sales', 'key1' => (int)$agent1['sales']['total'], 'key2' => (int)$agent2['sales']['total'], 'format' => 'number'],
                ['label' => 'Credit Score', 'key1' => (int)$agent1['sales']['cs'], 'key2' => (int)$agent2['sales']['cs'], 'format' => 'number'],
                ['label' => 'Credit Strong', 'key1' => (int)$agent1['sales']['cstrong'], 'key2' => (int)$agent2['sales']['cstrong'], 'format' => 'number'],
                ['label' => 'KikOff', 'key1' => (int)$agent1['sales']['ko'], 'key2' => (int)$agent2['sales']['ko'], 'format' => 'number'],
                ['label' => 'Other Sales', 'key1' => (int)$agent1['sales']['other'], 'key2' => (int)$agent2['sales']['other'], 'format' => 'number'],
                ['label' => 'Monthly Target', 'key1' => (int)($agent1['target']['monthly_target']??0), 'key2' => (int)($agent2['target']['monthly_target']??0), 'format' => 'number'],
                ['label' => 'Target Progress', 'key1' => ((int)($agent1['target']['monthly_target']??0)>0?round(((int)$agent1['sales']['total']/(int)($agent1['target']['monthly_target']??1))*100):0), 'key2' => ((int)($agent2['target']['monthly_target']??0)>0?round(((int)$agent2['sales']['total']/(int)($agent2['target']['monthly_target']??1))*100):0), 'format' => 'percent'],
                ['label' => 'Daily Target', 'key1' => (float)($agent1['target']['daily_target']??0), 'key2' => (float)($agent2['target']['daily_target']??0), 'format' => 'decimal'],
                ['label' => 'Weekly Target', 'key1' => (int)($agent1['target']['weekly_target']??0), 'key2' => (int)($agent2['target']['weekly_target']??0), 'format' => 'number'],
                ['label' => 'Present Days', 'key1' => $agent1['attendance']['present'], 'key2' => $agent2['attendance']['present'], 'format' => 'number'],
                ['label' => 'Absent Days', 'key1' => $agent1['attendance']['absent'], 'key2' => $agent2['attendance']['absent'], 'format' => 'number'],
                ['label' => 'Half Days', 'key1' => $agent1['attendance']['half_day'], 'key2' => $agent2['attendance']['half_day'], 'format' => 'number'],
                ['label' => 'Holidays', 'key1' => $agent1['attendance']['holiday'], 'key2' => $agent2['attendance']['holiday'], 'format' => 'number'],
            ];
            
            foreach (['agent1-col','agent2-col'] as $idx => $colClass):
                $d = $idx === 0 ? $agent1 : $agent2;
            ?>
            <div class="stat-col <?php echo $colClass; ?>">
                <?php foreach ($statRows as $row): 
                    $val = $idx === 0 ? $row['key1'] : $row['key2'];
                    $otherVal = $idx === 0 ? $row['key2'] : $row['key1'];
                    $isHigher = $val > $otherVal;
                    $isLower = $val < $otherVal;
                    
                    if ($row['format'] === 'number') $display = number_format($val);
                    elseif ($row['format'] === 'percent') $display = $val . '%';
                    elseif ($row['format'] === 'decimal') $display = number_format($val, 1);
                    else $display = $val;
                ?>
                <div class="stat-row">
                    <span><?php echo $row['label']; ?></span>
                    <span class="stat-val <?php echo $isHigher ? 'higher' : ($isLower ? 'lower' : ''); ?>">
                        <?php echo $display; ?>
                        <?php if ($isHigher && $val > 0): ?>
                            <span class="winner-badge win">▲</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts -->
        <div class="chart-card">
            <h3><i class="fas fa-chart-line"></i> Daily Sales Comparison</h3>
            <div style="display:flex;gap:16px;margin-bottom:10px;font-size:12px;">
                <span style="color:var(--agent1-color);">● <?php echo htmlspecialchars($agent1['info']['full_name']); ?></span>
                <span style="color:var(--agent2-color);">● <?php echo htmlspecialchars($agent2['info']['full_name']); ?></span>
            </div>
            <div class="chart-wrap"><canvas id="dailyCompareChart"></canvas></div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-star"></i> Credit Score Comparison</h3>
            <div class="chart-wrap"><canvas id="csCompareChart"></canvas></div>
        </div>

        <!-- Attendance Comparison -->
        <div class="chart-card">
            <h3><i class="fas fa-calendar-check"></i> Attendance Comparison</h3>
            <div class="att-compare">
                <div class="att-card agent1-att">
                    <div style="font-size:14px;font-weight:600;color:var(--navy-950);margin-bottom:10px;">
                        <?php echo htmlspecialchars($agent1['info']['full_name']); ?>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><div class="att-val" style="color:var(--success);"><?php echo $agent1['attendance']['present']; ?></div><div class="att-lbl">Present</div></div>
                        <div><div class="att-val" style="color:var(--danger);"><?php echo $agent1['attendance']['absent']; ?></div><div class="att-lbl">Absent</div></div>
                        <div><div class="att-val" style="color:var(--warn);"><?php echo $agent1['attendance']['half_day']; ?></div><div class="att-lbl">Half Day</div></div>
                        <div><div class="att-val" style="color:var(--info);"><?php echo $agent1['attendance']['holiday']; ?></div><div class="att-lbl">Holiday</div></div>
                    </div>
                </div>
                <div class="att-card agent2-att">
                    <div style="font-size:14px;font-weight:600;color:var(--navy-950);margin-bottom:10px;">
                        <?php echo htmlspecialchars($agent2['info']['full_name']); ?>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><div class="att-val" style="color:var(--success);"><?php echo $agent2['attendance']['present']; ?></div><div class="att-lbl">Present</div></div>
                        <div><div class="att-val" style="color:var(--danger);"><?php echo $agent2['attendance']['absent']; ?></div><div class="att-lbl">Absent</div></div>
                        <div><div class="att-val" style="color:var(--warn);"><?php echo $agent2['attendance']['half_day']; ?></div><div class="att-lbl">Half Day</div></div>
                        <div><div class="att-val" style="color:var(--info);"><?php echo $agent2['attendance']['holiday']; ?></div><div class="att-lbl">Holiday</div></div>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state" style="background:var(--paper);border-radius:var(--radius);box-shadow:var(--shadow-sm);border:1px solid var(--line);">
            <i class="fas fa-balance-scale"></i>
            <h3>Select Two Agents to Compare</h3>
            <p style="color:var(--ink-soft);margin-top:6px;">Choose two agents from the dropdowns above and click "Compare" to see side-by-side performance.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <?php if ($comparisonReady): ?>
    <script>
        // Daily Sales Comparison Chart
        new Chart(document.getElementById('dailyCompareChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: '<?php echo addslashes(htmlspecialchars($agent1['info']['full_name'])); ?>',
                        data: <?php echo json_encode($chart1Sales); ?>,
                        backgroundColor: 'rgba(201,150,47,.7)',
                        borderColor: '#c9962f',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: '<?php echo addslashes(htmlspecialchars($agent2['info']['full_name'])); ?>',
                        data: <?php echo json_encode($chart2Sales); ?>,
                        backgroundColor: 'rgba(41,83,140,.6)',
                        borderColor: '#29538c',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15 } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });

        // Credit Score Comparison
        new Chart(document.getElementById('csCompareChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: '<?php echo addslashes(htmlspecialchars($agent1['info']['full_name'])); ?> CS',
                        data: <?php echo json_encode($chart1CS); ?>,
                        borderColor: '#c9962f',
                        backgroundColor: 'rgba(201,150,47,.05)',
                        fill: false,
                        tension: .3,
                        borderWidth: 2,
                        pointRadius: 3
                    },
                    {
                        label: '<?php echo addslashes(htmlspecialchars($agent2['info']['full_name'])); ?> CS',
                        data: <?php echo json_encode($chart2CS); ?>,
                        borderColor: '#29538c',
                        backgroundColor: 'rgba(41,83,140,.05)',
                        fill: false,
                        tension: .3,
                        borderWidth: 2,
                        pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15 } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>