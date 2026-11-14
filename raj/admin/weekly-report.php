<?php
// /raj/admin/weekly-report.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

// Get current week's Monday and Sunday
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));

// Weekly sales
$weekSales = executeQuery("
    SELECT a.full_name, 
           COALESCE(SUM(ds.total_sales),0) as sales,
           COALESCE(SUM(ds.credit_score),0) as cs,
           COUNT(DISTINCT CASE WHEN ds.total_sales > 0 THEN ds.sale_date END) as active_days
    FROM agents a
    LEFT JOIN daily_sales ds ON a.agent_id = ds.agent_id AND ds.sale_date BETWEEN ? AND ?
    WHERE a.onboarding_completed = 1
    GROUP BY a.agent_id, a.full_name
    ORDER BY sales DESC
", [$monday, $sunday]);

// Weekly attendance
$weekAtt = executeQuery("
    SELECT ag.full_name,
           COUNT(CASE WHEN a.attendance_status='present' THEN 1 END) as present,
           COUNT(CASE WHEN a.attendance_status='absent' THEN 1 END) as absent
    FROM agents ag
    LEFT JOIN attendance a ON ag.agent_id = a.agent_id AND a.date BETWEEN ? AND ?
    WHERE ag.onboarding_completed = 1
    GROUP BY ag.agent_id, ag.full_name
", [$monday, $sunday]);

$attData = [];
if($weekAtt) while($r=$weekAtt->fetch_assoc()) $attData[$r['full_name']] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Report - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:'Inter',sans-serif;background:#f5f4f0;margin:0;padding:20px;}
        .card{background:#fff;border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.05);}
        h2{color:#0c1a38;margin-bottom:16px;}
        table{width:100%;border-collapse:collapse;font-size:13px;}
        th{background:#163063;color:#fff;padding:10px;text-align:left;font-size:11px;text-transform:uppercase;}
        td{padding:10px;border-bottom:1px solid #eee;}
        tr:hover{background:#f8f7f3;}
        .highlight{color:#c9962f;font-weight:700;}
        .btn-back{display:inline-block;padding:8px 16px;background:#163063;color:#fff;text-decoration:none;border-radius:20px;font-size:12px;margin-bottom:16px;}
        @media print{
            body{background:#fff;}
            .btn-back{display:none;}
        }
    </style>
</head>
<body>
    <a href="db.php" class="btn-back">← Back to Dashboard</a>
    <button onclick="window.print()" style="float:right;padding:8px 16px;background:#c9962f;color:#fff;border:none;border-radius:20px;cursor:pointer;font-size:12px;">🖨️ Print Report</button>
    
    <h1>📊 Weekly Report: <?php echo date('d M', strtotime($monday)); ?> - <?php echo date('d M Y', strtotime($sunday)); ?></h1>

    <div class="card">
        <h2>💰 Weekly Sales</h2>
        <table>
            <thead><tr><th>Agent</th><th>Credit Score</th><th>Total Sales</th><th>Active Days</th></tr></thead>
            <tbody>
                <?php 
                $totalWeekSales = 0;
                if($weekSales): while($r=$weekSales->fetch_assoc()): $totalWeekSales += $r['sales'];
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($r['full_name']); ?></strong></td>
                    <td><?php echo number_format($r['cs']); ?></td>
                    <td class="highlight"><?php echo number_format($r['sales']); ?></td>
                    <td><?php echo $r['active_days']; ?>/5</td>
                </tr>
                <?php endwhile; endif; ?>
                <tr style="font-weight:700;background:#fdf1de;">
                    <td>TOTAL</td><td></td>
                    <td class="highlight"><?php echo number_format($totalWeekSales); ?></td><td></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>📅 Weekly Attendance</h2>
        <table>
            <thead><tr><th>Agent</th><th>Present</th><th>Absent</th><th>Rate</th></tr></thead>
            <tbody>
                <?php foreach($attData as $name=>$d): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                    <td style="color:#1e7a41;"><?php echo $d['present']; ?></td>
                    <td style="color:#b3352f;"><?php echo $d['absent']; ?></td>
                    <td><?php echo ($d['present']+$d['absent'])>0 ? round(($d['present']/($d['present']+$d['absent']))*100).'%' : 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="text-align:center;color:#999;font-size:11px;margin-top:20px;">Generated on <?php echo date('d M Y H:i'); ?> | BetterGrowth Solutions</p>
</body>
</html>