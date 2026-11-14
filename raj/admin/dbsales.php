<?php
// /raj/admin/dbsales.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));

function getWD($my) {
    $d = date('t', strtotime($my . '-01')); $w = 0;
    for ($i = 1; $i <= $d; $i++) { if (date('N', strtotime(sprintf('%s-%02d', $my, $i))) < 6) $w++; }
    return $w;
}
$workingDays = getWD($selectedMonth);

// Team sales
$teamSales = ['total' => 0, 'cs' => 0, 'cstrong' => 0, 'ko' => 0, 'other' => 0];
$ts = executeQuery("SELECT COALESCE(SUM(total_sales),0) as t, COALESCE(SUM(credit_score),0) as cs, COALESCE(SUM(credit_strong),0) as cs2, COALESCE(SUM(kikoff),0) as ko, COALESCE(SUM(other_sales),0) as o FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?", [$selectedMonth]);
if ($ts && $ts->num_rows > 0) {
    $row = $ts->fetch_assoc();
    $teamSales = [
        'total' => (int)($row['t'] ?? 0),
        'cs' => (int)($row['cs'] ?? 0),
        'cstrong' => (int)($row['cs2'] ?? 0),
        'ko' => (int)($row['ko'] ?? 0),
        'other' => (int)($row['o'] ?? 0)
    ];
}

// Team target
$teamTarget = 0;
$tt = executeQuery("SELECT total_team_target FROM admin_targets WHERE month_year=?", [$selectedMonth]);
if ($tt && $tt->num_rows > 0) {
    $r = $tt->fetch_assoc();
    $teamTarget = (int)($r['total_team_target'] ?? 0);
}
$teamPct = $teamTarget > 0 ? min(100, round(($teamSales['total']/$teamTarget)*100)) : 0;

// Daily trend
$dailyData = [];
$dq = executeQuery("SELECT sale_date, SUM(total_sales) as t, SUM(credit_score) as cs FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=? GROUP BY sale_date ORDER BY sale_date", [$selectedMonth]);
if ($dq) { while ($r = $dq->fetch_assoc()) $dailyData[$r['sale_date']] = $r; }

$days = []; $salesArr = []; $csArr = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dt = sprintf('%s-%02d', $selectedMonth, $d);
    $days[] = $d;
    $salesArr[] = (int)($dailyData[$dt]['t'] ?? 0);
    $csArr[] = (int)($dailyData[$dt]['cs'] ?? 0);
}

// Agent performance
$agents = [];
$aq = executeQuery("
    SELECT a.agent_id, a.full_name, 
           COALESCE(s.t,0) as sales, COALESCE(t.monthly_target,0) as target,
           COALESCE(s.cs,0) as cs, COALESCE(s.cs2,0) as cstrong, COALESCE(s.ko,0) as ko
    FROM agents a
    LEFT JOIN (
        SELECT agent_id, SUM(total_sales) as t, SUM(credit_score) as cs, SUM(credit_strong) as cs2, SUM(kikoff) as ko 
        FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=? GROUP BY agent_id
    ) s ON a.agent_id=s.agent_id
    LEFT JOIN sales_targets t ON a.agent_id=t.agent_id AND t.month_year=?
    WHERE a.onboarding_completed=1
    ORDER BY sales DESC
", [$selectedMonth, $selectedMonth]);
if ($aq) { while ($r = $aq->fetch_assoc()) $agents[] = $r; }

$agentNames = []; $agentSales = []; $agentTargets = [];
foreach ($agents as $ag) {
    $agentNames[] = $ag['full_name'];
    $agentSales[] = (int)$ag['sales'];
    $agentTargets[] = (int)$ag['target'];
}

// Pie data
$pieData = [$teamSales['cs'], $teamSales['cstrong'], $teamSales['ko'], $teamSales['other']];

// 6 months
$histMonths = []; $histSales = []; $histTargets = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $histMonths[] = date('M', strtotime($m.'-01'));
    $ms = executeQuery("SELECT COALESCE(SUM(total_sales),0) as t FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?", [$m]);
    $histSales[] = (int)(($ms && $ms->num_rows>0) ? $ms->fetch_assoc()['t'] : 0);
    $mt = executeQuery("SELECT total_team_target FROM admin_targets WHERE month_year=?", [$m]);
    $histTargets[] = (int)(($mt && $mt->num_rows>0) ? $mt->fetch_assoc()['total_team_target'] : 0);
}

// Attendance
$attData = [];
$adq = executeQuery("SELECT agent_id, attendance_status, COUNT(*) as c FROM attendance WHERE DATE_FORMAT(date,'%Y-%m')=? GROUP BY agent_id, attendance_status", [$selectedMonth]);
if ($adq) {
    while ($r = $adq->fetch_assoc()) {
        $attData[$r['agent_id']][$r['attendance_status']] = (int)$r['c'];
    }
}

$prevMonth = date('Y-m', strtotime($selectedMonth.'-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth.'-01 +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - <?php echo APP_NAME; ?></title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 18px; --radius-sm: 12px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav a { color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px; font-size: 12px; }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-500); }

        .content { max-width: 1500px; margin: 0 auto; padding: 24px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 6px; }
        .month-nav { display: flex; gap: 8px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .month-nav select { padding: 8px 16px; border: 2px solid var(--line); border-radius: 25px; font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper); }
        .month-nav a { padding: 8px 16px; background: var(--paper); border: 1px solid var(--line); border-radius: 25px; text-decoration: none; color: var(--ink); font-size: 13px; }

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .kpi { background: var(--paper); border-radius: var(--radius); padding: 20px; text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--line); }
        .kpi .kv { font-family: 'Fraunces', serif; font-size: 30px; font-weight: 700; color: var(--navy-950); }
        .kpi .kl { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }
        .card { background: var(--paper); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow-sm); border: 1px solid var(--line); }
        .card h3 { font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .card h3 i { color: var(--gold-500); }

        .chart-wrap { position: relative; height: 300px; }
        canvas { width: 100% !important; }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table th { background: var(--cream); padding: 10px 12px; text-align: left; font-weight: 600; color: var(--ink-soft); font-size: 10px; text-transform: uppercase; }
        table td { padding: 10px 12px; border-bottom: 1px solid var(--line); }
        table tr:hover { background: var(--cream); }
        .mini-bar { display: inline-block; width: 80px; height: 6px; background: var(--cream); border-radius: 10px; overflow: hidden; vertical-align: middle; margin-right: 6px; }
        .mini-bar .fill { height: 100%; border-radius: 10px; }

        .copyright-footer { text-align: center; padding: 20px; margin-top: 30px; border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft); }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 1024px) { .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .content { padding: 14px 16px; } }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📊 Sales Analytics</div>
        <div class="nav">
            <a href="db.php">Dashboard</a>
            <a href="dbsales.php" class="active">Analytics</a>
            <a href="agents-manage.php">Agents</a>
            <a href="sales-manage.php">Sales</a>
            <a href="attendance-manage.php">Attendance</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📈 Sales Analytics Dashboard</h1>
        <div class="month-nav">
            <a href="?month=<?php echo $prevMonth; ?>">← Previous</a>
            <select onchange="location.href='?month='+this.value">
                <?php for ($m=1;$m<=12;$m++): $v=sprintf('%04d-%02d',date('Y'),$m); ?>
                <option value="<?php echo $v; ?>" <?php echo $v===$selectedMonth?'selected':''; ?>><?php echo date('F Y',strtotime($v.'-01')); ?></option>
                <?php endfor; ?>
            </select>
            <a href="?month=<?php echo $nextMonth; ?>">Next →</a>
            <span style="margin-left:auto;font-size:12px;color:var(--ink-soft);">Working Days: <?php echo $workingDays; ?></span>
        </div>

        <div class="kpi-row">
            <div class="kpi"><div class="kv"><?php echo number_format($teamSales['total']); ?></div><div class="kl">Total Sales</div></div>
            <div class="kpi"><div class="kv"><?php echo number_format($teamTarget); ?></div><div class="kl">Team Target</div></div>
            <div class="kpi"><div class="kv"><?php echo $teamPct; ?>%</div><div class="kl">Achievement</div></div>
            <div class="kpi"><div class="kv"><?php echo number_format($teamSales['cs']); ?></div><div class="kl">Credit Score</div></div>
            <div class="kpi"><div class="kv"><?php echo number_format($teamSales['cstrong']); ?></div><div class="kl">Credit Strong</div></div>
            <div class="kpi"><div class="kv"><?php echo number_format($teamSales['ko']); ?></div><div class="kl">KikOff</div></div>
        </div>

        <div class="grid-2">
            <div class="card">
                <h3><i class="fas fa-chart-bar"></i> Daily Sales Trend</h3>
                <div class="chart-wrap"><canvas id="dailyChart"></canvas></div>
            </div>
            <div class="card">
                <h3><i class="fas fa-chart-pie"></i> Sales Distribution</h3>
                <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <h3><i class="fas fa-users"></i> Agent Performance</h3>
                <div class="chart-wrap"><canvas id="agentChart"></canvas></div>
            </div>
            <div class="card">
                <h3><i class="fas fa-chart-line"></i> 6 Month Trend</h3>
                <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <div class="card">
            <h3><i class="fas fa-table"></i> Agent Summary - <?php echo date('F Y', strtotime($selectedMonth.'-01')); ?></h3>
            <div style="overflow-x:auto;">
                <table>
                    <thead><tr><th>Agent</th><th>Credit Score</th><th>Credit Strong</th><th>KikOff</th><th>Total</th><th>Target</th><th>Progress</th><th>Present</th><th>Absent</th></tr></thead>
                    <tbody>
                        <?php foreach ($agents as $ag): 
                            $pct = ((int)$ag['target'] > 0) ? min(100, round(((int)$ag['sales']/(int)$ag['target'])*100)) : 0;
                            $att = $attData[$ag['agent_id']] ?? [];
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ag['full_name']); ?></strong></td>
                            <td><?php echo number_format((int)$ag['cs']); ?></td>
                            <td><?php echo number_format((int)$ag['cstrong']); ?></td>
                            <td><?php echo number_format((int)$ag['ko']); ?></td>
                            <td><strong><?php echo number_format((int)$ag['sales']); ?></strong></td>
                            <td><?php echo number_format((int)$ag['target']); ?></td>
                            <td>
                                <div class="mini-bar"><div class="fill" style="width:<?php echo $pct; ?>%;background:<?php echo $pct>=100?'var(--success)':($pct>=50?'var(--gold-500)':'var(--danger)'); ?>;"></div></div>
                                <?php echo $pct; ?>%
                            </td>
                            <td style="color:var(--success);"><?php echo $att['present'] ?? 0; ?></td>
                            <td style="color:var(--danger);"><?php echo $att['absent'] ?? 0; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($days); ?>,
                datasets: [
                    { label: 'Total Sales', data: <?php echo json_encode($salesArr); ?>, backgroundColor: 'rgba(201,150,47,.7)', borderRadius: 6 },
                    { label: 'Credit Score', data: <?php echo json_encode($csArr); ?>, backgroundColor: 'rgba(41,83,140,.5)', borderRadius: 6 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
        });

        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Credit Score', 'Credit Strong', 'KikOff', 'Other'],
                datasets: [{ data: <?php echo json_encode($pieData); ?>, backgroundColor: ['#c9962f', '#29538c', '#1e7a41', '#9a6a10'], borderWidth: 2, borderColor: '#fff' }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } } }
        });

        new Chart(document.getElementById('agentChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($agentNames); ?>,
                datasets: [
                    { label: 'Sales', data: <?php echo json_encode($agentSales); ?>, backgroundColor: '#c9962f', borderRadius: 6 },
                    { label: 'Target', data: <?php echo json_encode($agentTargets); ?>, backgroundColor: 'rgba(12,26,56,.15)', borderColor: '#0c1a38', borderWidth: 2, borderRadius: 6 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
        });

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($histMonths); ?>,
                datasets: [
                    { label: 'Sales', data: <?php echo json_encode($histSales); ?>, borderColor: '#c9962f', backgroundColor: 'rgba(201,150,47,.1)', fill: true, tension: .4, borderWidth: 3, pointRadius: 5 },
                    { label: 'Target', data: <?php echo json_encode($histTargets); ?>, borderColor: '#0c1a38', borderDash: [6,4], borderWidth: 2, pointRadius: 3 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } }, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } } }
        });
    </script>
</body>
</html>