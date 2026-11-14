<?php
// /raj/admin/agent-daily-report.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$selectedAgent = $_GET['agent_id'] ?? '';
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$monthStart = date('Y-m-01', strtotime($selectedDate));
$monthEnd = date('Y-m-t', strtotime($selectedDate));

// Get all agents for dropdown
$allAgents = executeQuery("SELECT agent_id, full_name FROM agents ORDER BY full_name ASC");

// Get selected agent details
$agent = null;
if ($selectedAgent) {
    $agent = getAgent($selectedAgent);
}

// Get daily report data
$dailyData = null;
if ($selectedAgent) {
    $dailyData = executeQuery("
        SELECT 
            att.*,
            a.full_name,
            a.email,
            a.mobile,
            a.created_at as joining_date
        FROM attendance att
        JOIN agents a ON att.agent_id = a.agent_id
        WHERE att.agent_id = ? AND att.date = ?
    ", [$selectedAgent, $selectedDate]);
}

// Get monthly summary
$monthlyData = null;
if ($selectedAgent) {
    $monthlyData = executeQuery("
        SELECT 
            date,
            login_time,
            logout_time,
            job_start_time,
            job_end_time,
            lunch_start,
            lunch_end,
            snack_start,
            snack_end,
            bio_start,
            bio_end,
            bio_count,
            status
        FROM attendance
        WHERE agent_id = ? AND date >= ? AND date <= ?
        ORDER BY date DESC
    ", [$selectedAgent, $monthStart, $monthEnd]);
}

// Calculate monthly totals
$monthlyTotals = [
    'total_days' => 0,
    'total_hours' => 0,
    'total_lunch' => 0,
    'total_snack' => 0,
    'total_bio' => 0,
    'total_breaks' => 0
];

if ($monthlyData) {
    while ($row = $monthlyData->fetch_assoc()) {
        $monthlyTotals['total_days']++;
        if (!empty($row['lunch_start']) && !empty($row['lunch_end'])) {
            $monthlyTotals['total_lunch']++;
        }
        if (!empty($row['snack_start']) && !empty($row['snack_end'])) {
            $monthlyTotals['total_snack']++;
        }
        if (!empty($row['bio_start']) && !empty($row['bio_end'])) {
            $monthlyTotals['total_bio']++;
        }
        if (!empty($row['lunch_start']) || !empty($row['snack_start']) || !empty($row['bio_start'])) {
            $monthlyTotals['total_breaks']++;
        }
        // Calculate hours
        if (!empty($row['job_start_time']) && !empty($row['job_end_time'])) {
            $s = strtotime($row['job_end_time']) - strtotime($row['job_start_time']);
            $monthlyTotals['total_hours'] += max(0, $s);
        }
    }
    // Reset pointer
    $monthlyData = executeQuery("
        SELECT 
            date,
            login_time,
            logout_time,
            job_start_time,
            job_end_time,
            lunch_start,
            lunch_end,
            snack_start,
            snack_end,
            bio_start,
            bio_end,
            bio_count,
            status
        FROM attendance
        WHERE agent_id = ? AND date >= ? AND date <= ?
        ORDER BY date DESC
    ", [$selectedAgent, $monthStart, $monthEnd]);
}

// Format hours
$totalHoursFormatted = '00:00';
if ($monthlyTotals['total_hours'] > 0) {
    $h = floor($monthlyTotals['total_hours'] / 3600);
    $m = floor(($monthlyTotals['total_hours'] % 3600) / 60);
    $totalHoursFormatted = sprintf("%02d:%02d", $h, $m);
}

// Get break stats for the day
$breakStats = [
    'lunch' => ['taken' => 0, 'total' => 0],
    'snack' => ['taken' => 0, 'total' => 0],
    'bio' => ['taken' => 0, 'total' => 0]
];

if ($dailyData && $dailyData->num_rows > 0) {
    $day = $dailyData->fetch_assoc();
    if (!empty($day['lunch_start']) && !empty($day['lunch_end'])) {
        $breakStats['lunch']['taken'] = 1;
    }
    if (!empty($day['snack_start']) && !empty($day['snack_end'])) {
        $breakStats['snack']['taken'] = 1;
    }
    if (!empty($day['bio_start']) && !empty($day['bio_end'])) {
        $breakStats['bio']['taken'] = 1;
    }
    // Reset for display
    $dailyData = executeQuery("
        SELECT 
            att.*,
            a.full_name,
            a.email,
            a.mobile,
            a.created_at as joining_date
        FROM attendance att
        JOIN agents a ON att.agent_id = a.agent_id
        WHERE att.agent_id = ? AND att.date = ?
    ", [$selectedAgent, $selectedDate]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Daily Report - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
    
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38;
            --navy-800: #163063;
            --gold-500: #c9962f;
            --gold-400: #dcae4d;
            --gold-300: #e9c877;
            --cream: #f8f7f3;
            --paper: #ffffff;
            --ink: #1c2536;
            --ink-soft: #667085;
            --line: #ecebe6;
            --success-bg: #e4f4e8;
            --success-ink: #1e7a41;
            --danger-bg: #fbe7e7;
            --danger-ink: #b3352f;
            --warn-bg: #fdf1de;
            --warn-ink: #9a6a10;
            --info-bg: #e6eefb;
            --info-ink: #29538c;
            --shadow-sm: 0 1px 2px rgba(12,26,56,.05), 0 1px 3px rgba(12,26,56,.06);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08);
            --shadow-lg: 0 20px 45px rgba(12,26,56,.14);
            --ease: cubic-bezier(.22,.61,.36,1);
            --radius: 16px;
            --radius-sm: 10px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: var(--cream);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        .admin-wrapper { display: flex; min-height: 100vh; }

        .sidebar {
            width: 264px;
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 22px 0 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 40;
            transition: transform .35s var(--ease);
            border-right: 1px solid rgba(255,255,255,.06);
        }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 10px; }
        .sidebar-brand {
            padding: 4px 22px 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            margin-bottom: 18px;
        }
        .sidebar-brand .logo-mark { display: flex; align-items: center; gap: 12px; }
        .sidebar-brand .logo-mark .logo-fallback {
            display: flex; height: 40px; width: 40px; border-radius: 10px;
            align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 18px;
            color: var(--navy-950);
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-brand .brand-text {
            font-family: 'Fraunces', serif;
            font-size: 15px;
            line-height: 1.25;
        }
        .sidebar-brand .brand-text b { color: #fff; font-weight: 600; display:block; font-size: 16px; }
        .sidebar-brand .brand-text span { color: var(--gold-300); font-size: 11px; letter-spacing: 2px; text-transform: uppercase; }

        .sidebar-nav { padding: 6px 12px; display: flex; flex-direction: column; gap: 2px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 11px 14px;
            color: rgba(233,238,250,.68); text-decoration: none; border-radius: 10px;
            font-size: 14px; font-weight: 500; position: relative;
            transition: background .25s var(--ease), color .25s var(--ease);
        }
        .sidebar-nav a .icon { width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-nav a .icon i { font-size: 16px; }
        .sidebar-nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
        .sidebar-nav a.active {
            background: linear-gradient(90deg, rgba(201,150,47,.22), rgba(201,150,47,.05));
            color: #fff;
        }
        .sidebar-nav a.active::before {
            content: '';
            position: absolute; left: -12px; top: 6px; bottom: 6px; width: 3px;
            border-radius: 3px;
            background: linear-gradient(180deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-nav .nav-divider { height: 1px; background: rgba(255,255,255,.08); margin: 12px 14px; }
        .sidebar-nav .logout { color: #f2a3a0; }
        .sidebar-nav .logout:hover { background: rgba(240,90,80,.12); color: #ffb3b0; }
        .sidebar-nav .badge-count {
            margin-left: auto;
            background: rgba(255,255,255,.12);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .main-content {
            margin-left: 264px;
            padding: 26px 32px 50px;
            flex: 1;
            min-width: 0;
        }
        .main-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; flex-wrap: wrap; gap: 16px;
        }
        .main-header .heading-group { display: flex; align-items: center; gap: 14px; }
        .menu-toggle {
            display: none; width: 38px; height: 38px; border-radius: 9px;
            border: 1px solid var(--line); background: var(--paper);
            align-items: center; justify-content: center; cursor: pointer;
            font-size: 18px; color: var(--ink);
        }
        .main-header h1 {
            font-family: 'Fraunces', serif; font-weight: 600;
            color: var(--navy-950); font-size: 26px; letter-spacing: -.2px;
        }
        .main-header .subtitle { color: var(--ink-soft); font-size: 13.5px; margin-top: 2px; }

        .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .admin-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--paper); border: 1px solid var(--line);
            padding: 6px 14px 6px 6px; border-radius: 999px; box-shadow: var(--shadow-sm);
        }
        .admin-chip .avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            color: var(--navy-950); font-weight: 700; font-size: 12.5px;
            display: flex; align-items: center; justify-content: center;
        }
        .admin-chip .name { font-size: 13.5px; font-weight: 600; color: var(--ink); }
        .admin-chip .role { font-size: 11px; color: var(--ink-soft); }

        .filter-bar {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
        }
        .filter-bar .filter-group {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 10px 16px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: var(--cream);
            color: var(--ink);
            min-width: 180px;
        }
        .filter-bar select:focus, .filter-bar input:focus {
            border-color: var(--gold-500);
            outline: none;
        }
        .filter-bar .btn {
            padding: 10px 24px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s;
        }
        .filter-bar .btn:hover { transform: translateY(-2px); }
        .filter-bar .btn-secondary {
            background: var(--cream);
            color: var(--ink-soft);
            border: 2px solid var(--line);
        }

        .report-card {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            margin-bottom: 20px;
        }
        .report-card h3 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .report-card h3 i { color: var(--gold-500); }

        .stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px; margin-bottom: 16px;
        }
        .stat-item {
            background: var(--cream); padding: 14px 16px;
            border-radius: var(--radius-sm); text-align: center;
            border: 1px solid var(--line);
        }
        .stat-item .number {
            font-family: 'Fraunces', serif; font-size: 24px; font-weight: 700;
            color: var(--navy-950);
        }
        .stat-item .number.gold { color: var(--gold-500); }
        .stat-item .number.green { color: var(--success-ink); }
        .stat-item .number.blue { color: var(--info-ink); }
        .stat-item .number.orange { color: var(--warn-ink); }
        .stat-item .label { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.3px; font-weight: 500; }

        .break-details {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 12px; margin-bottom: 16px;
        }
        .break-item {
            background: var(--cream); padding: 12px 16px;
            border-radius: var(--radius-sm); text-align: center;
            border: 1px solid var(--line);
        }
        .break-item .label { font-size: 11px; color: var(--ink-soft); font-weight: 500; text-transform: uppercase; }
        .break-item .value { font-size: 14px; font-weight: 600; color: var(--navy-950); margin-top: 4px; }
        .break-item .value.done { color: var(--success-ink); }
        .break-item .value.pending { color: var(--warn-ink); }
        .break-item .value .time { font-size: 12px; font-weight: 400; color: var(--ink-soft); display: block; }

        .info-row {
            display: flex; padding: 6px 0; border-bottom: 1px solid var(--cream);
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { width: 140px; color: var(--ink-soft); font-weight: 500; flex-shrink: 0; }
        .info-row .value { flex: 1; color: var(--ink); font-weight: 500; }

        .monthly-table { width: 100%; border-collapse: collapse; }
        .monthly-table th {
            text-align: left; padding: 8px 10px;
            background: var(--cream); color: var(--ink-soft);
            font-weight: 600; font-size: 11px; text-transform: uppercase;
            border-bottom: 1px solid var(--line);
        }
        .monthly-table td { padding: 8px 10px; border-bottom: 1px solid var(--cream); font-size: 13px; }
        .monthly-table .badge {
            padding: 2px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 600;
        }
        .monthly-table .badge-success { background: var(--success-bg); color: var(--success-ink); }
        .monthly-table .badge-warning { background: var(--warn-bg); color: var(--warn-ink); }
        .monthly-table .badge-secondary { background: #eef0f2; color: #55606e; }

        .empty-state {
            text-align: center; padding: 40px 20px; color: var(--ink-soft);
        }
        .empty-state i { font-size: 40px; opacity: 0.3; display: block; margin-bottom: 12px; }

        .sidebar-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(12,26,56,.45);
            z-index: 30;
        }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
            .main-header { flex-direction: column; align-items: flex-start; }
            .break-details { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .sidebar-nav .badge-count { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .filter-bar .filter-group { flex-direction: column; }
            .filter-bar select, .filter-bar input { width: 100%; min-width: unset; }
            .stat-grid { grid-template-columns: 1fr 1fr; }
            .info-row { flex-direction: column; }
            .info-row .label { width: 100%; }
        }

        @media (max-width: 400px) {
            .stat-grid { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition-duration: 0.001ms !important; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div class="logo-mark">
                    <div class="logo-fallback">BG</div>
                    <div class="brand-text">
                        <b>BetterGrowth</b>
                        <span>Solutions</span>
                    </div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php">
                    <span class="icon"><i class="fas fa-th-large"></i></span>
                    <span>Dashboard</span>
                </a>
                <a href="create-agent.php">
                    <span class="icon"><i class="fas fa-user-plus"></i></span>
                    <span>Create Agent</span>
                </a>
                <a href="agents.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                </a>
                <div class="nav-divider"></div>
                <a href="agent-daily-report.php" class="active">
                    <span class="icon"><i class="fas fa-file-alt"></i></span>
                    <span>Daily Report</span>
                </a>
                <div class="nav-divider"></div>
                <a href="logout.php" class="logout">
                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <div class="main-header">
                <div class="heading-group">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>Agent Daily Report</h1>
                        <div class="subtitle">View detailed attendance and break reports</div>
                    </div>
                </div>
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-primary" style="padding:10px 22px;font-size:14px;background:var(--gold-500);color:var(--navy-950);">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <div class="admin-chip">
                        <div class="avatar">AD</div>
                        <div>
                            <div class="name">Admin</div>
                            <div class="role">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filter-bar">
                <form method="get" style="width:100%;">
                    <div class="filter-group">
                        <select name="agent_id" required>
                            <option value="">Select Agent</option>
                            <?php while ($row = $allAgents->fetch_assoc()): ?>
                                <option value="<?php echo $row['agent_id']; ?>" <?php echo $selectedAgent == $row['agent_id'] ? 'selected' : ''; ?>>
                                    <?php echo $row['agent_id']; ?> - <?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="date" name="date" value="<?php echo $selectedDate; ?>">
                        <button type="submit" class="btn"><i class="fas fa-search"></i> View Report</button>
                        <a href="?agent_id=<?php echo $selectedAgent; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">Today</a>
                        <a href="?agent_id=<?php echo $selectedAgent; ?>&date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>" class="btn btn-secondary">Yesterday</a>
                    </div>
                </form>
            </div>

            <?php if ($selectedAgent && $agent): ?>

            <!-- Agent Info -->
            <div class="report-card">
                <h3><i class="fas fa-user-circle"></i> Agent Information</h3>
                <div class="info-row">
                    <span class="label">Agent ID</span>
                    <span class="value"><?php echo htmlspecialchars($agent['agent_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Full Name</span>
                    <span class="value"><?php echo htmlspecialchars($agent['full_name'] ?: 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Mobile</span>
                    <span class="value"><?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Joining Date</span>
                    <span class="value"><?php echo date('d M Y', strtotime($agent['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Verification Status</span>
                    <span class="value">
                        <span class="badge <?php 
                            echo $agent['verification_status'] === 'verified' ? 'badge-success' : 
                                ($agent['verification_status'] === 'rejected' ? 'badge-danger' : 
                                ($agent['verification_status'] === 'correction_requested' ? 'badge-warning' : 'badge-info')); 
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $agent['verification_status'] ?: 'Pending')); ?>
                        </span>
                    </span>
                </div>
            </div>

            <?php if ($dailyData && $dailyData->num_rows > 0): 
                $day = $dailyData->fetch_assoc();
                // Calculate daily hours
                $dailyHours = '00:00';
                if (!empty($day['job_start_time']) && !empty($day['job_end_time'])) {
                    $s = strtotime($day['job_end_time']) - strtotime($day['job_start_time']);
                    $h = floor($s / 3600);
                    $m = floor(($s % 3600) / 60);
                    $dailyHours = sprintf("%02d:%02d", $h, $m);
                }
            ?>
            <!-- Daily Report -->
            <div class="report-card">
                <h3><i class="fas fa-calendar-day"></i> Daily Report - <?php echo date('d M Y', strtotime($selectedDate)); ?></h3>
                
                <div class="stat-grid">
                    <div class="stat-item">
                        <div class="number gold"><?php echo $dailyHours; ?></div>
                        <div class="label">Total Hours</div>
                    </div>
                    <div class="stat-item">
                        <div class="number green"><?php echo !empty($day['login_time']) ? date('h:i A', strtotime($day['login_time'])) : '--:--'; ?></div>
                        <div class="label">Login Time</div>
                    </div>
                    <div class="stat-item">
                        <div class="number <?php echo !empty($day['logout_time']) ? 'green' : 'orange'; ?>">
                            <?php echo !empty($day['logout_time']) ? date('h:i A', strtotime($day['logout_time'])) : 'Active'; ?>
                        </div>
                        <div class="label">Logout Time</div>
                    </div>
                    <div class="stat-item">
                        <div class="number blue"><?php echo !empty($day['job_start_time']) ? date('h:i A', strtotime($day['job_start_time'])) : '--:--'; ?></div>
                        <div class="label">Job Start</div>
                    </div>
                </div>

                <!-- Break Details -->
                <div class="break-details">
                    <div class="break-item">
                        <div class="label">🍽️ Lunch Break (30m)</div>
                        <div class="value <?php echo !empty($day['lunch_start']) && !empty($day['lunch_end']) ? 'done' : 'pending'; ?>">
                            <?php
                                if (!empty($day['lunch_start']) && !empty($day['lunch_end'])) {
                                    echo '✅ Done';
                                    echo '<span class="time">' . date('h:i A', strtotime($day['lunch_start'])) . ' - ' . date('h:i A', strtotime($day['lunch_end'])) . '</span>';
                                } elseif (!empty($day['lunch_start'])) {
                                    echo '⏳ In Progress';
                                    echo '<span class="time">Started: ' . date('h:i A', strtotime($day['lunch_start'])) . '</span>';
                                } else {
                                    echo '⏳ Not Taken';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="break-item">
                        <div class="label">🍪 Snack Break (15m)</div>
                        <div class="value <?php echo !empty($day['snack_start']) && !empty($day['snack_end']) ? 'done' : 'pending'; ?>">
                            <?php
                                if (!empty($day['snack_start']) && !empty($day['snack_end'])) {
                                    echo '✅ Done';
                                    echo '<span class="time">' . date('h:i A', strtotime($day['snack_start'])) . ' - ' . date('h:i A', strtotime($day['snack_end'])) . '</span>';
                                } elseif (!empty($day['snack_start'])) {
                                    echo '⏳ In Progress';
                                    echo '<span class="time">Started: ' . date('h:i A', strtotime($day['snack_start'])) . '</span>';
                                } else {
                                    echo '⏳ Not Taken';
                                }
                            ?>
                            <span style="display:inline-block;background:var(--warn-bg);padding:0 8px;border-radius:10px;font-size:10px;color:var(--warn-ink);">
                                <?php echo (int)($day['snack_count'] ?? 0); ?>/2
                            </span>
                        </div>
                    </div>
                    <div class="break-item">
                        <div class="label">🚽 Bio Break (No Limit)</div>
                        <div class="value <?php echo !empty($day['bio_start']) && !empty($day['bio_end']) ? 'done' : 'pending'; ?>">
                            <?php
                                if (!empty($day['bio_start']) && !empty($day['bio_end'])) {
                                    echo '✅ Done';
                                    echo '<span class="time">' . date('h:i A', strtotime($day['bio_start'])) . ' - ' . date('h:i A', strtotime($day['bio_end'])) . '</span>';
                                } elseif (!empty($day['bio_start'])) {
                                    echo '⏳ In Progress';
                                    echo '<span class="time">Started: ' . date('h:i A', strtotime($day['bio_start'])) . '</span>';
                                } else {
                                    echo '⏳ Not Taken';
                                }
                            ?>
                            <span style="display:inline-block;background:var(--info-bg);padding:0 8px;border-radius:10px;font-size:10px;color:var(--info-ink);">
                                Total: <?php echo (int)($day['bio_count'] ?? 0); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <div><strong>Status:</strong> 
                        <span class="badge <?php 
                            echo $day['status'] === 'logged_out' ? 'badge-secondary' : 
                                ($day['status'] === 'on_break' ? 'badge-warning' : 
                                ($day['status'] === 'paused' ? 'badge-info' : 'badge-success')); 
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $day['status'] ?: 'Not Started')); ?>
                        </span>
                    </div>
                    <div><strong>Bio Count:</strong> <span class="badge badge-info"><?php echo (int)($day['bio_count'] ?? 0); ?></span></div>
                </div>
            </div>

            <?php else: ?>
            <div class="report-card">
                <div class="empty-state">
                    <i class="fas fa-calendar"></i>
                    <p>No attendance data found for <?php echo date('d M Y', strtotime($selectedDate)); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Monthly Summary -->
            <div class="report-card">
                <h3><i class="fas fa-calendar-alt"></i> Monthly Summary - <?php echo date('M Y', strtotime($selectedDate)); ?></h3>
                
                <div class="stat-grid">
                    <div class="stat-item">
                        <div class="number gold"><?php echo $monthlyTotals['total_days']; ?></div>
                        <div class="label">Working Days</div>
                    </div>
                    <div class="stat-item">
                        <div class="number green"><?php echo $totalHoursFormatted; ?></div>
                        <div class="label">Total Hours</div>
                    </div>
                    <div class="stat-item">
                        <div class="number orange"><?php echo $monthlyTotals['total_lunch']; ?></div>
                        <div class="label">🍽️ Lunch Breaks</div>
                    </div>
                    <div class="stat-item">
                        <div class="number blue"><?php echo $monthlyTotals['total_snack']; ?></div>
                        <div class="label">🍪 Snack Breaks</div>
                    </div>
                    <div class="stat-item">
                        <div class="number" style="color:var(--success-ink);"><?php echo $monthlyTotals['total_bio']; ?></div>
                        <div class="label">🚽 Bio Breaks</div>
                    </div>
                    <div class="stat-item">
                        <div class="number" style="color:var(--gold-500);"><?php echo $monthlyTotals['total_breaks']; ?></div>
                        <div class="label">☕ Total Breaks</div>
                    </div>
                </div>

                <?php if ($monthlyData && $monthlyData->num_rows > 0): ?>
                <table class="monthly-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Login</th>
                            <th>Logout</th>
                            <th>Lunch</th>
                            <th>Snack</th>
                            <th>Bio</th>
                            <th>Bio Count</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $monthlyData->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                            <td><?php echo !empty($row['login_time']) ? date('h:i A', strtotime($row['login_time'])) : '--'; ?></td>
                            <td><?php echo !empty($row['logout_time']) ? date('h:i A', strtotime($row['logout_time'])) : '--'; ?></td>
                            <td>
                                <?php if (!empty($row['lunch_start']) && !empty($row['lunch_end'])): ?>
                                    <span class="badge badge-success">✅</span>
                                <?php elseif (!empty($row['lunch_start'])): ?>
                                    <span class="badge badge-warning">⏳</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['snack_start']) && !empty($row['snack_end'])): ?>
                                    <span class="badge badge-success">✅</span>
                                <?php elseif (!empty($row['snack_start'])): ?>
                                    <span class="badge badge-warning">⏳</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['bio_start']) && !empty($row['bio_end'])): ?>
                                    <span class="badge badge-success">✅</span>
                                <?php elseif (!empty($row['bio_start'])): ?>
                                    <span class="badge badge-warning">⏳</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info"><?php echo (int)($row['bio_count'] ?? 0); ?></span></td>
                            <td>
                                <span class="badge <?php 
                                    echo $row['status'] === 'logged_out' ? 'badge-secondary' : 
                                        ($row['status'] === 'on_break' ? 'badge-warning' : 
                                        ($row['status'] === 'paused' ? 'badge-info' : 'badge-success')); 
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $row['status'] ?: 'Not Started')); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar"></i>
                    <p>No records found for this month</p>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="report-card">
                <div class="empty-state">
                    <i class="fas fa-user-search"></i>
                    <p>Select an agent and date to view the report</p>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuToggle = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('show');
        }

        menuToggle?.addEventListener('click', toggleSidebar);
        backdrop?.addEventListener('click', toggleSidebar);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });
    </script>
</body>
</html>