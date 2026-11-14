<?php
// /raj/admin/agent-detail.php
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

$agentId = $_GET['id'] ?? '';
if (empty($agentId)) {
    header('Location: agents-manage.php');
    exit;
}

$agent = getAgent($agentId);
if (!$agent) {
    header('Location: agents-manage.php');
    exit;
}

$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));

// Agent stats
$name = $agent['full_name'] ?: 'Employee';
$photoPath = $agent['candidate_photo_path'] ?? '';
$photoExists = !empty($photoPath) && file_exists(__DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath);
$photoUrl = $photoExists ? '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath) : '';

// Sales data
$salesQuery = executeQuery(
    "SELECT * FROM daily_sales WHERE agent_id = ? AND DATE_FORMAT(sale_date, '%Y-%m') = ? ORDER BY sale_date ASC",
    [$agentId, $selectedMonth]
);
$dailySales = [];
$totalSales = 0; $totalCS = 0; $totalCStrong = 0; $totalKO = 0; $totalOther = 0;
if ($salesQuery) {
    while ($row = $salesQuery->fetch_assoc()) {
        $dailySales[$row['sale_date']] = $row;
        $totalSales += $row['total_sales'];
        $totalCS += $row['credit_score'];
        $totalCStrong += $row['credit_strong'];
        $totalKO += $row['kikoff'];
        $totalOther += $row['other_sales'];
    }
}

// Target
$targetQuery = executeQuery("SELECT * FROM sales_targets WHERE agent_id = ? AND month_year = ?", [$agentId, $selectedMonth]);
$target = $targetQuery ? $targetQuery->fetch_assoc() : null;
$monthlyTarget = $target['monthly_target'] ?? 0;
$salesProgress = $monthlyTarget > 0 ? min(100, round(($totalSales / $monthlyTarget) * 100)) : 0;

// Attendance data
$attendanceData = [];
$attQuery = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date ASC", [$agentId, $selectedMonth]);
if ($attQuery) {
    while ($row = $attQuery->fetch_assoc()) {
        $attendanceData[$row['date']] = $row;
    }
}

// Attendance stats
$presentDays = 0; $absentDays = 0; $halfDays = 0; $holidays = 0; $weekends = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%s-%02d', $selectedMonth, $d);
    $dayOfWeek = date('N', strtotime($date));
    
    if (isset($attendanceData[$date])) {
        $status = $attendanceData[$date]['attendance_status'] ?? 'present';
        if ($status === 'present') $presentDays++;
        elseif ($status === 'absent') $absentDays++;
        elseif ($status === 'half_day') $halfDays++;
        elseif ($status === 'holiday') $holidays++;
    } else {
        if ($dayOfWeek >= 6) $weekends++;
    }
}

// Working days calculation
$totalWorkingDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    if (date('N', strtotime(sprintf('%s-%02d', $selectedMonth, $d))) < 6) $totalWorkingDays++;
}
$attendancePercent = $totalWorkingDays > 0 ? round(($presentDays / $totalWorkingDays) * 100) : 0;

// Chart data
$chartLabels = []; $chartSalesData = []; $chartCSData = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%s-%02d', $selectedMonth, $d);
    $chartLabels[] = $d;
    $chartSalesData[] = $dailySales[$date]['total_sales'] ?? 0;
    $chartCSData[] = $dailySales[$date]['credit_score'] ?? 0;
}

// Last 6 months sales
$last6Months = [];
$historicalQuery = executeQuery("
    SELECT DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(total_sales) as total
    FROM daily_sales WHERE agent_id = ? 
    AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month ASC
", [$agentId]);
if ($historicalQuery) {
    while ($row = $historicalQuery->fetch_assoc()) {
        $last6Months[$row['month']] = $row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($name); ?> - Agent Detail</title>
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
            --warn: #9a6a10; --info: #29538c;
            --radius: 16px; --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(12,26,56,.06);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--cream); color: var(--ink); }

        .header {
            background: linear-gradient(190deg, #6b1d1d, #8b2525);
            color: #fce8e8; padding: 12px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
        }
        .header .back-btn { color: #fce8e8; text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .header .nav a { color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px; font-size: 12px; }
        .header .nav a:hover { background: rgba(255,255,255,.15); }

        .content { max-width: 1200px; margin: 0 auto; padding: 20px 28px 40px; }

        .profile-hero {
            background: var(--paper); border-radius: var(--radius); padding: 28px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
        }
        .profile-hero .photo {
            width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
            border: 4px solid var(--gold-500);
        }
        .profile-hero .photo-placeholder {
            width: 100px; height: 100px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: #aaa; border: 3px dashed var(--line);
        }
        .profile-hero .info h1 { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); }
        .profile-hero .info .meta { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 6px; color: var(--ink-soft); font-size: 13px; }
        .profile-hero .info .meta span { display: flex; align-items: center; gap: 5px; }
        .profile-hero .info .badges { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .badge {
            padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .badge.active { background: #e4f4e8; color: var(--success); }
        .badge.shift { background: #fdf1de; color: var(--warn); }
        .badge.location { background: #e6eefb; color: var(--info); }

        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px; margin-bottom: 20px;
        }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 16px 20px;
            text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .stat-card .sv { font-family: 'Fraunces', serif; font-size: 30px; font-weight: 700; color: var(--navy-950); }
        .stat-card .sl { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }
        .stat-card.gold { border-top: 4px solid var(--gold-500); }
        .stat-card.green { border-top: 4px solid var(--success); }
        .stat-card.blue { border-top: 4px solid var(--info); }
        .stat-card.red { border-top: 4px solid var(--danger); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .card h3 { font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .card h3 i { color: var(--gold-500); }
        .chart-wrap { position: relative; max-height: 280px; }
        .chart-wrap canvas { width: 100% !important; }

        .progress-wrap { margin-top: 8px; }
        .progress-wrap .p-bar { background: var(--cream); border-radius: 20px; height: 14px; overflow: hidden; }
        .progress-wrap .p-fill { height: 100%; background: linear-gradient(90deg, var(--gold-300), var(--gold-500)); border-radius: 20px; transition: width .5s; }
        .progress-wrap .p-info { display: flex; justify-content: space-between; font-size: 12px; color: var(--ink-soft); margin-top: 6px; }

        .att-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .att-day { text-align: center; padding: 8px 4px; border-radius: 6px; font-size: 10px; }
        .att-day .day-num { font-weight: 700; font-size: 13px; }
        .att-day.present { background: #e4f4e8; color: var(--success); }
        .att-day.absent { background: #fbe7e7; color: var(--danger); }
        .att-day.half_day { background: #fdf1de; color: var(--warn); }
        .att-day.holiday { background: #e6eefb; color: var(--info); }
        .att-day.weekend { background: #f5f5f5; color: #bbb; }
        .att-day.empty { background: #fff; color: #ddd; border: 1px dashed #eee; }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table th { background: var(--cream); padding: 8px; text-align: left; font-weight: 600; color: var(--ink-soft); font-size: 10px; text-transform: uppercase; }
        table td { padding: 8px; border-bottom: 1px solid var(--line); }
        table tr:hover { background: var(--cream); }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 40px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            .content { padding: 12px 14px 30px; }
            .profile-hero { flex-direction: column; text-align: center; }
            .profile-hero .info .meta { justify-content: center; }
            .profile-hero .info .badges { justify-content: center; }
            .att-grid { grid-template-columns: repeat(7, 1fr); gap: 2px; }
            .att-day { padding: 4px 2px; font-size: 9px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="agents-manage.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Agents</a>
        <div class="nav">
            <a href="sales-manage.php">Sales</a>
            <a href="attendance-manage.php">Attendance</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <!-- Profile Hero -->
        <div class="profile-hero">
            <?php if ($photoExists): ?>
                <img src="<?php echo $photoUrl; ?>" class="photo" alt="Photo">
            <?php else: ?>
                <div class="photo-placeholder">👤</div>
            <?php endif; ?>
            <div class="info">
                <h1><?php echo htmlspecialchars($name); ?></h1>
                <div class="meta">
                    <span><i class="fas fa-id-badge"></i> <?php echo $agentId; ?></span>
                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></span>
                    <span><i class="fas fa-calendar"></i> Joined: <?php echo date('d M Y', strtotime($agent['joining_date'] ?? 'now')); ?></span>
                </div>
                <div class="badges">
                    <span class="badge active"><?php echo $agent['status'] === 'active' ? '✅ Active' : '❌ Inactive'; ?></span>
                    <span class="badge shift"><i class="fas <?php echo $agent['shift_type'] === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i> <?php echo ucfirst($agent['shift_type'] ?? 'day'); ?> Shift</span>
                    <span class="badge location"><i class="fas <?php echo ($agent['work_location'] ?? 'office') === 'office' ? 'fa-building' : 'fa-home'; ?>"></i> <?php echo ucfirst($agent['work_location'] ?? 'office'); ?></span>
                    <span class="badge" style="background:#f3e8ff;color:#7c3aed;"><?php echo htmlspecialchars($agent['department'] ?: 'General'); ?></span>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card gold">
                <div class="sv"><?php echo number_format($totalSales); ?></div>
                <div class="sl">Monthly Sales</div>
            </div>
            <div class="stat-card green">
                <div class="sv"><?php echo number_format($monthlyTarget); ?></div>
                <div class="sl">Target</div>
            </div>
            <div class="stat-card blue">
                <div class="sv"><?php echo $presentDays; ?>/<?php echo $totalWorkingDays; ?></div>
                <div class="sl">Present Days (Mon-Fri)</div>
            </div>
            <div class="stat-card red">
                <div class="sv"><?php echo $absentDays; ?></div>
                <div class="sl">Absent Days</div>
            </div>
            <div class="stat-card">
                <div class="sv"><?php echo $attendancePercent; ?>%</div>
                <div class="sl">Attendance %</div>
            </div>
        </div>

        <!-- Progress -->
        <div class="card" style="margin-bottom:20px;">
            <h3><i class="fas fa-bullseye"></i> Sales Target Progress - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>
            <div class="progress-wrap">
                <div class="p-bar"><div class="p-fill" style="width:<?php echo $salesProgress; ?>%;"></div></div>
                <div class="p-info">
                    <span><?php echo number_format($totalSales); ?> sales</span>
                    <span><strong><?php echo $salesProgress; ?>%</strong></span>
                    <span><?php echo number_format($monthlyTarget); ?> target</span>
                </div>
            </div>
            <?php if ($target && $target['daily_target'] > 0): ?>
            <p style="font-size:12px;color:var(--ink-soft);margin-top:8px;">
                📌 Daily target needed: <strong><?php echo number_format($target['daily_target'], 1); ?></strong> sales/day 
                (<?php echo $target['working_days'] ?? 22; ?> working days)
            </p>
            <?php endif; ?>
        </div>

        <!-- Charts & Attendance Grid -->
        <div class="grid-2">
            <div class="card">
                <h3><i class="fas fa-chart-bar"></i> Daily Sales</h3>
                <div class="chart-wrap"><canvas id="dailySalesChart"></canvas></div>
            </div>
            <div class="card">
                <h3><i class="fas fa-calendar-check"></i> Attendance Calendar - <?php echo date('M Y', strtotime($selectedMonth . '-01')); ?></h3>
                <div class="att-grid">
                    <?php 
                    $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    foreach ($dayNames as $dn): ?>
                    <div style="text-align:center;font-size:9px;font-weight:600;color:var(--ink-soft);padding:4px;"><?php echo $dn; ?></div>
                    <?php endforeach;
                    
                    $firstDay = date('N', strtotime($selectedMonth . '-01'));
                    for ($i = 1; $i < $firstDay; $i++): ?>
                    <div class="att-day empty"></div>
                    <?php endfor;
                    
                    for ($d = 1; $d <= $daysInMonth; $d++):
                        $date = sprintf('%s-%02d', $selectedMonth, $d);
                        $dayOfWeek = date('N', strtotime($date));
                        $att = $attendanceData[$date] ?? null;
                        $status = $att ? ($att['attendance_status'] ?? 'present') : ($dayOfWeek >= 6 ? 'weekend' : 'empty');
                    ?>
                    <div class="att-day <?php echo $status; ?>" title="<?php echo date('d M D', strtotime($date)); ?>">
                        <div class="day-num"><?php echo $d; ?></div>
                        <?php 
                            if ($status === 'present') echo '✓';
                            elseif ($status === 'absent') echo '✗';
                            elseif ($status === 'half_day') echo '½';
                            elseif ($status === 'holiday') echo '☀';
                            elseif ($status === 'weekend') echo '·';
                        ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <div style="display:flex;gap:12px;margin-top:10px;font-size:10px;flex-wrap:wrap;">
                    <span style="color:var(--success);">✓ Present (<?php echo $presentDays; ?>)</span>
                    <span style="color:var(--danger);">✗ Absent (<?php echo $absentDays; ?>)</span>
                    <span style="color:var(--warn);">½ Half (<?php echo $halfDays; ?>)</span>
                    <span style="color:var(--info);">☀ Holiday (<?php echo $holidays; ?>)</span>
                    <span style="color:#bbb;">· Weekend (<?php echo $weekends; ?>)</span>
                </div>
            </div>
        </div>

        <!-- Historical Sales -->
        <div class="card" style="margin-bottom:20px;">
            <h3><i class="fas fa-chart-line"></i> Last 6 Months Performance</h3>
            <div class="chart-wrap"><canvas id="historyChart"></canvas></div>
        </div>

        <!-- Sales Table -->
        <div class="card">
            <h3><i class="fas fa-table"></i> Daily Sales Details</h3>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Credit Score</th>
                            <th>Credit Strong</th>
                            <th>KikOff</th>
                            <th>Other</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): 
                            $date = sprintf('%s-%02d', $selectedMonth, $d);
                            $sale = $dailySales[$date] ?? null;
                        ?>
                        <tr>
                            <td><strong><?php echo date('D, M d', strtotime($date)); ?></strong></td>
                            <td><?php echo $sale['credit_score'] ?? '-'; ?></td>
                            <td><?php echo $sale['credit_strong'] ?? '-'; ?></td>
                            <td><?php echo $sale['kikoff'] ?? '-'; ?></td>
                            <td><?php echo $sale['other_sales'] ?? '-'; ?></td>
                            <td><strong><?php echo $sale['total_sales'] ?? '-'; ?></strong></td>
                            <td>
                                <?php if ($sale): ?>
                                <span style="padding:3px 10px;border-radius:12px;font-size:10px;font-weight:600;
                                    background:<?php echo $sale['status'] === 'approved' ? '#e4f4e8' : ($sale['status'] === 'pending' ? '#fdf1de' : '#fbe7e7'); ?>;
                                    color:<?php echo $sale['status'] === 'approved' ? 'var(--success)' : ($sale['status'] === 'pending' ? 'var(--warn)' : 'var(--danger)'); ?>;">
                                    <?php echo ucfirst($sale['status']); ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#ccc;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="copyright-footer">
        <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Daily Sales Chart
        new Chart(document.getElementById('dailySalesChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Credit Score',
                    data: <?php echo json_encode($chartCSData); ?>,
                    backgroundColor: '#c9962f', borderRadius: 4
                }, {
                    label: 'Total Sales',
                    data: <?php echo json_encode($chartSalesData); ?>,
                    type: 'line', borderColor: '#0c1a38', backgroundColor: 'transparent',
                    borderWidth: 2, pointRadius: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 10 } } } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } } },
                    x: { ticks: { font: { size: 9 } }, grid: { display: false } }
                }
            }
        });

        // Historical Chart
        const histMonths = <?php echo json_encode(array_keys($last6Months)); ?>;
        const histData = <?php echo json_encode(array_values($last6Months)); ?>;
        new Chart(document.getElementById('historyChart'), {
            type: 'line',
            data: {
                labels: histMonths.map(m => {
                    const d = new Date(m + '-01');
                    return d.toLocaleString('default', { month: 'short', year: '2-digit' });
                }),
                datasets: [{
                    label: 'Monthly Sales',
                    data: histData,
                    borderColor: '#c9962f',
                    backgroundColor: 'rgba(201,150,47,.1)',
                    fill: true, tension: 0.3, borderWidth: 3,
                    pointBackgroundColor: '#c9962f', pointRadius: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>
</body>
</html>