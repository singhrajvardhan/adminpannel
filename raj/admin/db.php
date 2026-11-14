<?php
// /raj/admin/dashboard.php
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
$today = date('Y-m-d');
$daysInMonth = date('t');

// ============================================================
// HELPERS
// ============================================================
function getWorkingDays($monthYear) {
    $days = date('t', strtotime($monthYear . '-01'));
    $working = 0;
    for ($d = 1; $d <= $days; $d++) {
        if (date('N', strtotime(sprintf('%s-%02d', $monthYear, $d))) < 6) $working++;
    }
    return $working;
}
function getWorkingDaysPassed($monthYear) {
    $today = date('Y-m-d');
    $passed = 0;
    for ($d = 1; $d <= date('d'); $d++) {
        $date = sprintf('%s-%02d', $monthYear, $d);
        if ($date > $today) break;
        if (date('N', strtotime($date)) < 6) $passed++;
    }
    return $passed;
}

$totalWorkingDays = getWorkingDays($currentMonth);
$workingDaysPassed = getWorkingDaysPassed($currentMonth);

// ============================================================
// 1. AGENT COUNTS
// ============================================================
$countQuery = executeQuery("SELECT COUNT(*) as total FROM agents WHERE onboarding_completed = 1");
$totalAgents = 0;
if ($countQuery && $countQuery->num_rows > 0) {
    $totalAgents = (int)$countQuery->fetch_assoc()['total'];
}

// ============================================================
// 2. TEAM SALES
// ============================================================
$ts = executeQuery(
    "SELECT COALESCE(SUM(total_sales),0) as total, COALESCE(SUM(credit_score),0) as cs,
            COALESCE(SUM(credit_strong),0) as cstrong, COALESCE(SUM(kikoff),0) as ko,
            COALESCE(SUM(other_sales),0) as other
     FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?", [$currentMonth]
);
$teamSales = ['total'=>0,'cs'=>0,'cstrong'=>0,'ko'=>0,'other'=>0];
if ($ts && $ts->num_rows>0) $teamSales = $ts->fetch_assoc();

// ============================================================
// 3. TEAM TARGET
// ============================================================
$tt = executeQuery("SELECT total_team_target FROM admin_targets WHERE month_year=?", [$currentMonth]);
$teamTarget = 0;
if ($tt && $tt->num_rows>0) $teamTarget = (int)$tt->fetch_assoc()['total_team_target'];
$teamProgress = $teamTarget>0 ? min(100, round(((int)$teamSales['total']/$teamTarget)*100)) : 0;

// ============================================================
// 4. DAILY SALES CHART DATA
// ============================================================
$dailyData = [];
$dq = executeQuery("SELECT sale_date, SUM(total_sales) as t FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=? GROUP BY sale_date", [$currentMonth]);
if ($dq) { while($r=$dq->fetch_assoc()) $dailyData[$r['sale_date']]=(int)$r['t']; }

$chartDays=[]; $chartSales=[];
for($d=1;$d<=$daysInMonth;$d++){
    $dt = sprintf('%s-%02d',$currentMonth,$d);
    $chartDays[] = $d;
    $chartSales[] = $dailyData[$dt] ?? 0;
}

// ============================================================
// 5. 6 MONTH TREND
// ============================================================
$histLabels=[]; $histData=[];
for($i=5;$i>=0;$i--){
    $m = date('Y-m',strtotime("-$i months"));
    $histLabels[] = date('M',strtotime($m.'-01'));
    $ms = executeQuery("SELECT COALESCE(SUM(total_sales),0) as t FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?",[$m]);
    $histData[] = (int)(($ms && $ms->num_rows>0) ? $ms->fetch_assoc()['t'] : 0);
}

// ============================================================
// 6. TOP PERFORMERS
// ============================================================
$topQ = executeQuery("
    SELECT a.agent_id, a.full_name, COALESCE(s.t,0) as sales, COALESCE(t.monthly_target,0) as target
    FROM agents a
    LEFT JOIN (SELECT agent_id, SUM(total_sales) as t FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=? GROUP BY agent_id) s ON a.agent_id=s.agent_id
    LEFT JOIN sales_targets t ON a.agent_id=t.agent_id AND t.month_year=?
    WHERE a.onboarding_completed=1 ORDER BY sales DESC LIMIT 5
", [$currentMonth,$currentMonth]);
$topAgents = [];
if($topQ) while($r=$topQ->fetch_assoc()) $topAgents[] = $r;

// ============================================================
// 7. TODAY'S ATTENDANCE
// ============================================================
$attQ = executeQuery("
    SELECT a.agent_id, ag.full_name, a.attendance_status
    FROM attendance a JOIN agents ag ON a.agent_id=ag.agent_id
    WHERE a.date=? ORDER BY ag.full_name
", [$today]);
$todayAtt = []; $presentToday=0; $absentToday=0;
if($attQ) {
    while($r=$attQ->fetch_assoc()) {
        $todayAtt[] = $r;
        if(($r['attendance_status']??'present')==='present') $presentToday++;
        else $absentToday++;
    }
}

// ============================================================
// 8. PENDING COUNTS
// ============================================================
$ps = executeQuery("SELECT COUNT(*) as c FROM daily_sales WHERE status='pending'");
$pendingSales = ($ps && $ps->num_rows>0) ? (int)$ps->fetch_assoc()['c'] : 0;
$pr = executeQuery("SELECT COUNT(*) as c FROM sales_report_requests WHERE status='pending'");
$pendingReports = ($pr && $pr->num_rows>0) ? (int)$pr->fetch_assoc()['c'] : 0;

// ============================================================
// 9. AGENT ATTENDANCE SUMMARY
// ============================================================
$attSumQ = executeQuery("
    SELECT attendance_status, COUNT(*) as c FROM attendance WHERE DATE_FORMAT(date,'%Y-%m')=? GROUP BY attendance_status
", [$currentMonth]);
$attSummary = ['present'=>0,'absent'=>0,'half_day'=>0,'holiday'=>0];
if($attSumQ) while($r=$attSumQ->fetch_assoc()) $attSummary[$r['attendance_status']]=(int)$r['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo APP_NAME; ?></title>
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
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f;
            --warn: #9a6a10; --info: #29538c; --purple: #7c3aed;
            --radius: 18px; --radius-sm: 12px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
            --ease: cubic-bezier(.22,.61,.36,1);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f5f4f0; color:var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515 60%, #5a2020);
            color:#fce8e8; padding:14px 28px; display:flex;
            justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.2); position:sticky; top:0; z-index:100;
        }
        .header .brand{display:flex;align-items:center;gap:12px;}
        .header .logo{
            width:40px;height:40px;border-radius:10px;
            background:linear-gradient(135deg,var(--gold-300),var(--gold-500));
            display:flex;align-items:center;justify-content:center;
            font-family:'Fraunces',serif;font-weight:700;font-size:16px;color:#3d1515;
        }
        .header .title{font-family:'Fraunces',serif;font-size:17px;font-weight:600;color:#fff;}
        .header .title span{color:var(--gold-300);font-size:9px;letter-spacing:3px;text-transform:uppercase;display:block;}
        .header .nav{display:flex;gap:4px;flex-wrap:wrap;}
        .header .nav a{
            color:#fce8e8;text-decoration:none;padding:7px 14px;border-radius:20px;
            font-size:11px;font-weight:500;transition:all .2s;white-space:nowrap;
        }
        .header .nav a:hover,.header .nav a.active{background:rgba(255,255,255,.12);}
        .header .nav a.active{color:var(--gold-300);font-weight:600;}
        .header .nav a.badge-link{position:relative;}
        .header .nav a .nav-badge{
            position:absolute;top:-4px;right:-4px;background:var(--danger);
            color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;
        }

        .content{max-width:1500px;margin:0 auto;padding:22px 28px 40px;}

        .greeting{font-family:'Fraunces',serif;font-size:26px;color:var(--navy-950);margin-bottom:2px;}
        .date-line{color:var(--ink-soft);font-size:13px;margin-bottom:20px;}

        /* KPI Grid */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:22px;}
        .kpi-card{
            background:var(--paper);border-radius:var(--radius);padding:20px 22px;
            box-shadow:var(--shadow-sm);border:1px solid var(--line);
            display:flex;align-items:center;gap:14px;cursor:pointer;
            transition:transform .3s var(--ease),box-shadow .3s var(--ease);
        }
        .kpi-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);}
        .kpi-icon{
            width:52px;height:52px;border-radius:14px;display:flex;
            align-items:center;justify-content:center;font-size:20px;flex-shrink:0;
        }
        .kpi-icon.gold{background:linear-gradient(135deg,#fdf1de,#fce8c0);color:var(--gold-500);}
        .kpi-icon.green{background:linear-gradient(135deg,#e4f4e8,#c8f0d0);color:var(--success);}
        .kpi-icon.blue{background:linear-gradient(135deg,#e6eefb,#c8ddf8);color:var(--info);}
        .kpi-icon.red{background:linear-gradient(135deg,#fbe7e7,#f8c8c8);color:var(--danger);}
        .kpi-icon.purple{background:linear-gradient(135deg,#f3e8ff,#e0c8f8);color:var(--purple);}
        .kpi-info .kpi-val{font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--navy-950);line-height:1;}
        .kpi-info .kpi-lbl{font-size:11px;color:var(--ink-soft);font-weight:500;margin-top:2px;}
        .kpi-info .kpi-sub{font-size:10px;color:var(--ink-soft);margin-top:1px;}

        /* Grid */
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px;}
        .grid-3{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px;}
        .grid-full{margin-bottom:22px;}

        .card{
            background:var(--paper);border-radius:var(--radius);padding:22px 24px;
            box-shadow:var(--shadow-sm);border:1px solid var(--line);
        }
        .card h3{
            font-family:'Fraunces',serif;font-size:17px;color:var(--navy-950);
            margin-bottom:14px;display:flex;align-items:center;gap:8px;
        }
        .card h3 i{color:var(--gold-500);}
        .card .card-link{font-size:11px;color:var(--info);text-decoration:none;font-weight:600;}
        .card .card-link:hover{text-decoration:underline;}

        .chart-wrap{position:relative;width:100%;}
        .chart-h300{height:300px;}
        .chart-h250{height:250px;}
        canvas{width:100%!important;}

        /* Progress */
        .prog-wrap{margin:8px 0;}
        .prog-bar{background:var(--cream);border-radius:30px;height:16px;overflow:hidden;border:1px solid var(--line);}
        .prog-fill{height:100%;border-radius:30px;background:linear-gradient(90deg,#e9c877,#c9962f,#dcae4d);transition:width .8s var(--ease);}
        .prog-info{display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:var(--ink-soft);}
        .prog-info strong{color:var(--navy-950);}

        /* Agent List */
        .agent-list{display:flex;flex-direction:column;gap:8px;}
        .agent-item{
            display:flex;align-items:center;gap:12px;padding:12px 14px;
            border-radius:var(--radius-sm);background:var(--cream);border:1px solid var(--line);
            transition:transform .2s;
        }
        .agent-item:hover{transform:translateX(4px);}
        .agent-item .rank{
            width:32px;height:32px;border-radius:50%;display:flex;
            align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;
        }
        .agent-item .rank.gold{background:#fff9e6;color:#d4a017;}
        .agent-item .rank.silver{background:#f8f8f8;color:#888;}
        .agent-item .rank.bronze{background:#fdf5f0;color:#c9783a;}
        .agent-item .rank.normal{background:#f0f0f0;color:var(--ink-soft);}
        .agent-item .agent-name{flex:1;font-weight:600;font-size:13px;}
        .agent-item .agent-sales{font-family:'Fraunces',serif;font-weight:700;font-size:15px;color:var(--navy-950);}
        .agent-item .mini-prog{width:60px;height:5px;background:#e0e0e0;border-radius:5px;overflow:hidden;}
        .agent-item .mini-prog .mp-fill{height:100%;border-radius:5px;}

        /* Attendance Grid */
        .att-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;}
        .att-chip{
            display:flex;align-items:center;gap:8px;padding:8px 12px;
            border-radius:20px;font-size:11px;font-weight:500;
        }
        .att-chip.present{background:#e4f4e8;color:var(--success);}
        .att-chip.absent{background:#fbe7e7;color:var(--danger);}
        .att-chip .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
        .att-chip.present .dot{background:var(--success);}
        .att-chip.absent .dot{background:var(--danger);}

        /* Quick Links */
        .quick-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;}
        .ql-card{
            background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-sm);
            padding:18px 14px;text-align:center;text-decoration:none;color:var(--ink);
            transition:transform .2s,box-shadow .2s;cursor:pointer;
        }
        .ql-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
        .ql-card .ql-icon{font-size:26px;margin-bottom:6px;}
        .ql-card .ql-label{font-size:11px;font-weight:600;}
        .ql-card .ql-badge{
            display:inline-block;padding:2px 8px;border-radius:10px;
            font-size:9px;font-weight:700;margin-top:4px;
        }
        .ql-card .ql-badge.alert{background:#fbe7e7;color:var(--danger);}

        .copyright-footer{
            text-align:center;padding:20px;margin-top:30px;
            border-top:1px solid var(--line);font-size:12px;color:var(--ink-soft);
        }
        .copyright-footer a{color:var(--gold-500);text-decoration:none;font-weight:600;}

        @media(max-width:1024px){.grid-2,.grid-3{grid-template-columns:1fr;}}
        @media(max-width:768px){
            .content{padding:12px 14px 30px;}
            .header{padding:10px 14px;}
            .header .nav a{font-size:10px;padding:5px 10px;}
            .greeting{font-size:22px;}
            .kpi-grid{grid-template-columns:repeat(2,1fr);}
            .kpi-info .kpi-val{font-size:22px;}
            .kpi-card{padding:14px 16px;}
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            <div class="logo">BG</div>
            <div class="title">BetterGrowth<span>Admin Panel</span></div>
        </div>
        <div class="nav">
            <a href="db.php" class="active"><i class="fas fa-th-large"></i> Dashboard</a>
                 <a href="dashboard.php"><i class="fas fa-chart-pie"></i> Main Dashboard</a>
            <a href="dbsales.php"><i class="fas fa-chart-pie"></i> Analytics</a>
            <a href="agents-manage.php"><i class="fas fa-users"></i> Agents</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="sales-edit.php"><i class="fas fa-edit"></i> Edit</a>
            <a href="targets-manage.php"><i class="fas fa-bullseye"></i> Targets</a>
            <a href="attendance-manage.php"><i class="fas fa-calendar-check"></i> Attendance</a>
              <a href="agent-comparison.php"><i class="fas fa-calendar-check"></i> Agent-Comparison</a>
              <a href="weekly-report.php"><i class="fas fa-calendar-check"></i> Weekly Report</a>
              <a href="salary-assign.php"><i class="fas fa-calendar-check"></i> Salary Assign  </a>
               <a href="view-letter.php"><i class="fas fa-calendar-check"></i> Salary Updates </a>
            <a href="reports-manage.php" class="badge-link">
                <i class="fas fa-envelope"></i> Reports
                <?php if($pendingReports>0): ?><span class="nav-badge"><?php echo $pendingReports; ?></span><?php endif; ?>
            </a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>
    
    <!-- Add this in a new card on dashboard.php -->
<div class="card grid-full">
    <h3><i class="fas fa-shopping-cart"></i> Today's Sales (<?php echo date('d M'); ?>)</h3>
    <?php
    $todaySales = executeQuery("
        SELECT a.full_name, ds.credit_score, ds.credit_strong, ds.kikoff, ds.other_sales, ds.total_sales
        FROM daily_sales ds 
        JOIN agents a ON ds.agent_id = a.agent_id 
        WHERE ds.sale_date = ? AND ds.total_sales > 0
        ORDER BY ds.total_sales DESC
    ", [$today]);
    ?>
    <?php if($todaySales && $todaySales->num_rows > 0): ?>
    <table style="width:100%;font-size:12px;">
        <thead><tr><th>Agent</th><th>CS</th><th>CSt</th><th>KO</th><th>Other</th><th>Total</th></tr></thead>
        <tbody>
        <?php while($s = $todaySales->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($s['full_name']); ?></td>
            <td><?php echo $s['credit_score']; ?></td>
            <td><?php echo $s['credit_strong']; ?></td>
            <td><?php echo $s['kikoff']; ?></td>
            <td><?php echo $s['other_sales']; ?></td>
            <td><strong><?php echo $s['total_sales']; ?></strong></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color:var(--ink-soft);text-align:center;padding:20px;">No sales recorded today yet</p>
    <?php endif; ?>
</div>

    <div class="content">
        <h1 class="greeting">📊 Good <?php echo date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening'); ?>, Admin</h1>
        <p class="date-line"><?php echo date('l, F d, Y'); ?> | Working Days: <?php echo $workingDaysPassed; ?>/<?php echo $totalWorkingDays; ?> | Month: <?php echo date('F Y'); ?></p>

        <!-- ===== KPI CARDS ===== -->
        <div class="kpi-grid">
            <div class="kpi-card" onclick="location.href='sales-manage.php'">
                <div class="kpi-icon gold"><i class="fas fa-chart-bar"></i></div>
                <div class="kpi-info">
                    <div class="kpi-val"><?php echo number_format((int)$teamSales['total']); ?></div>
                    <div class="kpi-lbl">Team Sales</div>
                    <div class="kpi-sub">Target: <?php echo number_format($teamTarget); ?></div>
                </div>
            </div>
            <div class="kpi-card" onclick="location.href='dbsales.php'">
                <div class="kpi-icon green"><i class="fas fa-bullseye"></i></div>
                <div class="kpi-info">
                    <div class="kpi-val"><?php echo $teamProgress; ?>%</div>
                    <div class="kpi-lbl">Target Achieved</div>
                    <div class="kpi-sub"><?php echo number_format((int)$teamSales['total']); ?> / <?php echo number_format($teamTarget); ?></div>
                </div>
            </div>
            <div class="kpi-card" onclick="location.href='agents-manage.php'">
                <div class="kpi-icon blue"><i class="fas fa-users"></i></div>
                <div class="kpi-info">
                    <div class="kpi-val"><?php echo $totalAgents; ?></div>
                    <div class="kpi-lbl">Total Agents</div>
                    <div class="kpi-sub">Onboarded & active</div>
                </div>
            </div>
            <div class="kpi-card" onclick="location.href='attendance-manage.php'">
                <div class="kpi-icon red"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-info">
                    <div class="kpi-val"><?php echo $presentToday; ?>/<?php echo count($todayAtt); ?></div>
                    <div class="kpi-lbl">Present Today</div>
                    <div class="kpi-sub"><?php echo $absentToday; ?> absent</div>
                </div>
            </div>
            <div class="kpi-card" onclick="location.href='reports-manage.php'">
                <div class="kpi-icon purple"><i class="fas fa-clock"></i></div>
                <div class="kpi-info">
                    <div class="kpi-val"><?php echo $pendingSales + $pendingReports; ?></div>
                    <div class="kpi-lbl">Pending Actions</div>
                    <div class="kpi-sub"><?php echo $pendingSales; ?> sales | <?php echo $pendingReports; ?> reports</div>
                </div>
            </div>
        </div>

        <!-- ===== TARGET PROGRESS BAR ===== -->
        <div class="card grid-full">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h3 style="margin:0;"><i class="fas fa-trophy"></i> Monthly Team Target</h3>
                <span style="font-size:12px;color:var(--ink-soft);"><?php echo $workingDaysPassed; ?>/<?php echo $totalWorkingDays; ?> working days</span>
            </div>
            <div class="prog-wrap">
                <div class="prog-bar"><div class="prog-fill" style="width:<?php echo $teamProgress;?>%;"></div></div>
                <div class="prog-info">
                    <span>0</span>
                    <span><strong><?php echo number_format((int)$teamSales['total']); ?></strong> of <strong><?php echo number_format($teamTarget); ?></strong></span>
                    <span><?php echo number_format($teamTarget); ?></span>
                </div>
            </div>
            <div style="display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;font-size:11px;color:var(--ink-soft);">
                <span>⭐ Credit Score: <strong><?php echo number_format((int)$teamSales['cs']); ?></strong></span>
                <span>🛡️ Credit Strong: <strong><?php echo number_format((int)$teamSales['cstrong']); ?></strong></span>
                <span>🏦 KikOff: <strong><?php echo number_format((int)$teamSales['ko']); ?></strong></span>
                <span>📦 Other: <strong><?php echo number_format((int)$teamSales['other']); ?></strong></span>
            </div>
        </div>

        <!-- ===== CHARTS ROW ===== -->
        <div class="grid-2">
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3><i class="fas fa-chart-line"></i> Daily Sales (<?php echo date('M'); ?>)</h3>
                    <a href="dbsales.php" class="card-link">Full Analytics →</a>
                </div>
                <div class="chart-wrap chart-h300"><canvas id="dailyChart"></canvas></div>
            </div>
            <div class="card">
                <h3><i class="fas fa-chart-area"></i> 6 Month Performance</h3>
                <div class="chart-wrap chart-h300"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <!-- ===== TOP PERFORMERS + ATTENDANCE ===== -->
        <div class="grid-2">
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3><i class="fas fa-medal"></i> Top Performers</h3>
                    <a href="agents-manage.php" class="card-link">View All →</a>
                </div>
                <div class="agent-list">
                    <?php foreach($topAgents as $i=>$ag): 
                        $pct = ((int)$ag['target']>0) ? min(100,round(((int)$ag['sales']/(int)$ag['target'])*100)) : 0;
                        $rankClass = $i===0?'gold':($i===1?'silver':($i===2?'bronze':'normal'));
                    ?>
                    <div class="agent-item">
                        <div class="rank <?php echo $rankClass; ?>"><?php echo $i+1; ?></div>
                        <div class="agent-name"><?php echo htmlspecialchars($ag['full_name']); ?></div>
                        <div class="mini-prog"><div class="mp-fill" style="width:<?php echo $pct;?>%;background:var(--gold-500);"></div></div>
                        <div class="agent-sales"><?php echo number_format((int)$ag['sales']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3><i class="fas fa-user-check"></i> Today's Attendance</h3>
                    <a href="attendance-manage.php" class="card-link">Manage →</a>
                </div>
                <div class="att-grid">
                    <?php foreach(array_slice($todayAtt,0,16) as $att): ?>
                    <div class="att-chip <?php echo ($att['attendance_status']??'present')==='present'?'present':'absent'; ?>">
                        <div class="dot"></div>
                        <?php echo htmlspecialchars($att['full_name']); ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($todayAtt)): ?>
                    <div style="grid-column:1/-1;text-align:center;color:var(--ink-soft);padding:20px;">No data for today</div>
                    <?php endif; ?>
                </div>
                <?php if(count($todayAtt)>16): ?>
                <p style="text-align:center;margin-top:8px;font-size:11px;color:var(--ink-soft);">+<?php echo count($todayAtt)-16; ?> more</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== ATTENDANCE SUMMARY + QUICK LINKS ===== -->
        <div class="grid-2">
            <div class="card">
                <h3><i class="fas fa-calendar-alt"></i> Attendance Summary (<?php echo date('M'); ?>)</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div style="text-align:center;padding:12px;background:#e4f4e8;border-radius:var(--radius-sm);">
                        <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--success);"><?php echo $attSummary['present']; ?></div>
                        <div style="font-size:10px;color:var(--success);font-weight:600;">Present</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:#fbe7e7;border-radius:var(--radius-sm);">
                        <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--danger);"><?php echo $attSummary['absent']; ?></div>
                        <div style="font-size:10px;color:var(--danger);font-weight:600;">Absent</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:#fdf1de;border-radius:var(--radius-sm);">
                        <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--warn);"><?php echo $attSummary['half_day']; ?></div>
                        <div style="font-size:10px;color:var(--warn);font-weight:600;">Half Day</div>
                    </div>
                    <div style="text-align:center;padding:12px;background:#e6eefb;border-radius:var(--radius-sm);">
                        <div style="font-family:'Fraunces',serif;font-size:28px;font-weight:700;color:var(--info);"><?php echo $attSummary['holiday']; ?></div>
                        <div style="font-size:10px;color:var(--info);font-weight:600;">Holiday</div>
                    </div>
                </div>
            </div>
            <div class="card">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="quick-links">
                    <a href="dbsales.php" class="ql-card">
                        <div class="ql-icon">📊</div><div class="ql-label">Sales Analytics</div>
                    </a>
                    <a href="agents-manage.php" class="ql-card">
                        <div class="ql-icon">👥</div><div class="ql-label">Manage Agents</div>
                    </a>
                    <a href="sales-edit.php" class="ql-card">
                        <div class="ql-icon">✏️</div><div class="ql-label">Edit Sales</div>
                    </a>
                    <a href="targets-manage.php" class="ql-card">
                        <div class="ql-icon">🎯</div><div class="ql-label">Set Targets</div>
                    </a>
                    <a href="attendance-manage.php" class="ql-card">
                        <div class="ql-icon">📅</div><div class="ql-label">Attendance</div>
                    </a>
                    <a href="reports-manage.php" class="ql-card">
                        <div class="ql-icon">📧</div><div class="ql-label">Reports
                            <?php if($pendingReports>0): ?><div class="ql-badge alert"><?php echo $pendingReports; ?></div><?php endif; ?>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Daily Sales Chart
        new Chart(document.getElementById('dailyChart'),{
            type:'bar',
            data:{
                labels:<?php echo json_encode($chartDays); ?>,
                datasets:[{
                    label:'Sales',data:<?php echo json_encode($chartSales); ?>,
                    backgroundColor:'rgba(201,150,47,.7)',borderRadius:6,borderColor:'#c9962f',borderWidth:1
                }]
            },
            options:{
                responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{y:{beginAtZero:true,grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}
            }
        });

        // 6 Month Trend
        new Chart(document.getElementById('trendChart'),{
            type:'line',
            data:{
                labels:<?php echo json_encode($histLabels); ?>,
                datasets:[{
                    label:'Sales',data:<?php echo json_encode($histData); ?>,
                    borderColor:'#c9962f',backgroundColor:'rgba(201,150,47,.1)',
                    fill:true,tension:.4,borderWidth:3,pointRadius:5,pointBackgroundColor:'#c9962f'
                }]
            },
            options:{
                responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{y:{beginAtZero:true,grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}
            }
        });
    </script>
</body>
</html>