<?php
// /raj/agent/sales.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$agentId = $_SESSION['agent_id'] ?? '';
$agent = getAgent($agentId);
if (!$agent) {
    header('Location: login.php');
    exit;
}

$name = $agent['full_name'] ?: 'Employee';
$photoPath = $agent['candidate_photo_path'] ?? '';
$shiftType = $agent['shift_type'] ?? 'day';
$workLocation = $agent['work_location'] ?? 'office';

// Get current month
$currentMonth = date('Y-m');
$selectedMonth = $_GET['month'] ?? $currentMonth;
$today = date('Y-m-d');
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));

// ============================================================
// WORKING DAYS CALCULATION (Mon-Fri only)
// ============================================================
function getWorkingDaysInMonth($monthYear) {
    $days = date('t', strtotime($monthYear . '-01'));
    $wd = 0;
    for ($d = 1; $d <= $days; $d++) {
        if (date('N', strtotime(sprintf('%s-%02d', $monthYear, $d))) < 6) $wd++;
    }
    return $wd;
}
function getWorkingDaysPassed($monthYear) {
    $today = date('Y-m-d');
    $passed = 0;
    $days = date('t', strtotime($monthYear . '-01'));
    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%s-%02d', $monthYear, $d);
        if ($date > $today) break;
        if (date('N', strtotime($date)) < 6) $passed++;
    }
    return $passed;
}

$totalWorkingDays = getWorkingDaysInMonth($selectedMonth);
$workingDaysPassed = getWorkingDaysPassed($selectedMonth);

// ============================================================
// GET TARGET
// ============================================================
$targetQuery = executeQuery(
    "SELECT * FROM sales_targets WHERE agent_id = ? AND month_year = ?",
    [$agentId, $selectedMonth]
);
$target = $targetQuery ? $targetQuery->fetch_assoc() : null;
$monthlyTarget = $target['monthly_target'] ?? 0;
$creditScoreTarget = $target['credit_score_target'] ?? 0;
$workingDaysTarget = $target['working_days'] ?? 22;
$dailyTarget = $target['daily_target'] ?? ($monthlyTarget > 0 ? round($monthlyTarget / max($workingDaysTarget, 1), 1) : 0);

// ============================================================
// GET DAILY SALES
// ============================================================
$salesQuery = executeQuery(
    "SELECT * FROM daily_sales WHERE agent_id = ? AND DATE_FORMAT(sale_date, '%Y-%m') = ? ORDER BY sale_date ASC",
    [$agentId, $selectedMonth]
);

$dailySales = [];
$totalCreditScore = 0;
$totalCreditStrong = 0;
$totalKikoff = 0;
$totalOther = 0;
$totalAllSales = 0;
$totalWorkingDaysWithSales = 0;
$approvedSales = 0;
$pendingSales = 0;

if ($salesQuery) {
    while ($row = $salesQuery->fetch_assoc()) {
        $dailySales[$row['sale_date']] = $row;
        $totalCreditScore += (int)$row['credit_score'];
        $totalCreditStrong += (int)$row['credit_strong'];
        $totalKikoff += (int)$row['kikoff'];
        $totalOther += (int)$row['other_sales'];
        $totalAllSales += (int)$row['total_sales'];
        if ($row['total_sales'] > 0) $totalWorkingDaysWithSales++;
        if ($row['status'] === 'approved') $approvedSales++;
        if ($row['status'] === 'pending') $pendingSales++;
    }
}

// ============================================================
// LEADERBOARD - Get all agents' sales for ranking
// ============================================================
$leaderboardQuery = executeQuery("
    SELECT 
        a.agent_id, 
        a.full_name, 
        a.candidate_photo_path,
        COALESCE(s.total_sales, 0) as total_sales,
        COALESCE(s.credit_score, 0) as credit_score,
        COALESCE(t.monthly_target, 0) as monthly_target
    FROM agents a
    LEFT JOIN (
        SELECT agent_id, 
               SUM(total_sales) as total_sales, 
               SUM(credit_score) as credit_score
        FROM daily_sales 
        WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?
        GROUP BY agent_id
    ) s ON a.agent_id = s.agent_id
    LEFT JOIN sales_targets t ON a.agent_id = t.agent_id AND t.month_year = ?
    WHERE a.onboarding_completed = 1
    ORDER BY total_sales DESC, credit_score DESC
", [$selectedMonth, $selectedMonth]);
$leaderboard = [];
$myRank = 0;
$myData = null;
$rank = 1;

if ($leaderboardQuery) {
    while ($row = $leaderboardQuery->fetch_assoc()) {
        $leaderboard[] = $row;
        if ($row['agent_id'] === $agentId) {
            $myRank = $rank;
            $myData = $row;
        }
        $rank++;
    }
}

// Top 3 for podium
$top3 = array_slice($leaderboard, 0, 3);
$totalAgents = count($leaderboard);

// Get nearby agents (2 above and 2 below my rank)
$nearbyAgents = [];
if ($myRank > 0) {
    $startIdx = max(0, $myRank - 3);
    $endIdx = min($totalAgents - 1, $myRank + 1);
    for ($i = $startIdx; $i <= $endIdx; $i++) {
        if (isset($leaderboard[$i])) {
            $nearbyAgents[] = $leaderboard[$i];
        }
    }
}

// ============================================================
// CALCULATE PROGRESS
// ============================================================
$progressPercent = $monthlyTarget > 0 ? min(100, round(($totalAllSales / $monthlyTarget) * 100, 1)) : 0;
$creditScoreProgress = $creditScoreTarget > 0 ? min(100, round(($totalCreditScore / $creditScoreTarget) * 100, 1)) : 0;

// Average daily sales
$avgDailySales = $totalWorkingDaysWithSales > 0 ? round($totalAllSales / $totalWorkingDaysWithSales, 1) : 0;

// Projected month-end sales
$projectedSales = $avgDailySales > 0 ? round($avgDailySales * $totalWorkingDays) : 0;

// Days remaining
$daysRemaining = max(0, $totalWorkingDays - $workingDaysPassed);
$salesNeededPerDay = $daysRemaining > 0 && $monthlyTarget > $totalAllSales ? 
    round(($monthlyTarget - $totalAllSales) / $daysRemaining, 1) : 0;

// ============================================================
// CHART DATA
// ============================================================
$chartLabels = [];
$chartTotalSales = [];
$chartCreditScore = [];
$chartCreditStrong = [];
$chartKikoff = [];
$chartOther = [];

for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%s-%02d', $selectedMonth, $day);
    $chartLabels[] = $day;
    $chartTotalSales[] = (int)($dailySales[$date]['total_sales'] ?? 0);
    $chartCreditScore[] = (int)($dailySales[$date]['credit_score'] ?? 0);
    $chartCreditStrong[] = (int)($dailySales[$date]['credit_strong'] ?? 0);
    $chartKikoff[] = (int)($dailySales[$date]['kikoff'] ?? 0);
    $chartOther[] = (int)($dailySales[$date]['other_sales'] ?? 0);
}

// Cumulative sales for trend line
$cumulativeSales = [];
$runningTotal = 0;
foreach ($chartTotalSales as $val) {
    $runningTotal += $val;
    $cumulativeSales[] = $runningTotal;
}

// ============================================================
// LAST 6 MONTHS TREND
// ============================================================
$trendMonths = [];
$trendSales = [];
$trendTargets = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $trendMonths[] = date('M', strtotime($m . '-01'));
    
    $ms = executeQuery("SELECT COALESCE(SUM(total_sales), 0) as t FROM daily_sales WHERE agent_id = ? AND DATE_FORMAT(sale_date, '%Y-%m') = ?", [$agentId, $m]);
    $trendSales[] = (int)(($ms && $ms->num_rows > 0) ? $ms->fetch_assoc()['t'] : 0);
    
    $mt = executeQuery("SELECT monthly_target FROM sales_targets WHERE agent_id = ? AND month_year = ?", [$agentId, $m]);
    $trendTargets[] = (int)(($mt && $mt->num_rows > 0) ? $mt->fetch_assoc()['monthly_target'] : 0);
}

// ============================================================
// MONTH SELECTOR DATA
// ============================================================
$monthsQuery = executeQuery(
    "SELECT DISTINCT DATE_FORMAT(sale_date, '%Y-%m') as month_year FROM daily_sales WHERE agent_id = ? UNION SELECT ? as month_year ORDER BY month_year DESC LIMIT 12",
    [$agentId, $currentMonth]
);
$availableMonths = [];
if ($monthsQuery) {
    while ($row = $monthsQuery->fetch_assoc()) {
        $availableMonths[] = $row['month_year'];
    }
}
if (empty($availableMonths)) $availableMonths = [$currentMonth];

// Photo URL
$photoUrl = '';
if (!empty($photoPath)) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath;
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath);
    }
}

// Get attendance summary for this month
$attQuery = executeQuery(
    "SELECT attendance_status, COUNT(*) as cnt FROM attendance WHERE agent_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? GROUP BY attendance_status",
    [$agentId, $selectedMonth]
);
$attSummary = ['present' => 0, 'absent' => 0, 'half_day' => 0, 'holiday' => 0];
if ($attQuery) {
    while ($row = $attQuery->fetch_assoc()) {
        $attSummary[$row['attendance_status']] = (int)$row['cnt'];
    }
}

// Helper function for agent photo
function getAgentPhotoUrl($agentId, $photoPath) {
    if (!empty($photoPath)) {
        $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath;
        if (file_exists($fullPath)) {
            return '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath);
        }
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sales - <?php echo APP_NAME; ?></title>
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
            --ease: cubic-bezier(.22,.61,.36,1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--navy-950), var(--navy-800) 80%);
            color: #eef2fa; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.15); position: sticky; top: 0; z-index: 100;
        }
        .header .brand { display: flex; align-items: center; gap: 10px; }
        .header .brand .logo {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 16px; color: var(--navy-950);
        }
        .header .brand .title { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600; color: #fff; }
        .header .brand .title span { color: var(--gold-300); font-size: 9px; letter-spacing: 2px; text-transform: uppercase; display: block; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #eef2fa; text-decoration: none; padding: 7px 15px; border-radius: 25px;
            font-size: 12px; font-weight: 500; transition: all .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.1); }
        .header .nav a.active { color: var(--gold-300); font-weight: 600; }

        .content { max-width: 1200px; margin: 0 auto; padding: 22px 28px 40px; }

        /* ============ POPUP TOAST ============ */
        .toast-overlay {
            position: fixed; top: 80px; right: 20px; z-index: 9999;
            display: flex; flex-direction: column; gap: 10px;
        }
        .toast-msg {
            background: var(--paper); border-radius: var(--radius-sm); padding: 14px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,.15); border-left: 4px solid var(--info);
            display: flex; align-items: flex-start; gap: 12px; max-width: 380px;
            animation: slideInRight .4s var(--ease), fadeOut .5s ease 2.5s forwards;
            font-size: 13px; color: var(--ink); line-height: 1.5;
        }
        .toast-msg .toast-icon { font-size: 20px; color: var(--info); flex-shrink: 0; margin-top: 2px; }
        .toast-msg .toast-close {
            background: none; border: none; color: var(--ink-soft); cursor: pointer;
            font-size: 16px; padding: 0 4px; flex-shrink: 0;
        }
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; transform: translateX(30px); }
        }

        /* Profile Bar */
        .profile-bar {
            background: var(--paper); border-radius: var(--radius); padding: 16px 22px;
            margin-bottom: 18px; display: flex; align-items: center; gap: 16px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); flex-wrap: wrap;
        }
        .profile-bar .photo {
            width: 52px; height: 52px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--gold-500); flex-shrink: 0;
        }
        .profile-bar .photo-placeholder {
            width: 52px; height: 52px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: #bbb; border: 2px dashed var(--line); flex-shrink: 0;
        }
        .profile-bar .info h2 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); }
        .profile-bar .info .meta { font-size: 11px; color: var(--ink-soft); display: flex; gap: 12px; flex-wrap: wrap; margin-top: 2px; }
        .profile-bar .badges { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }
        .badge {
            padding: 5px 12px; border-radius: 20px; font-size: 10px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .badge.shift { background: var(--gold-100); color: var(--gold-500); }
        .badge.location { background: #e6eefb; color: var(--info); }

        /* Month Selector */
        .month-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
        }
        .month-bar select {
            padding: 10px 18px; border: 2px solid var(--line); border-radius: 25px;
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper);
            cursor: pointer; font-weight: 500;
        }
        .month-bar .btn-report {
            padding: 10px 20px; border: none; border-radius: 25px;
            background: var(--gold-500); color: var(--navy-950); font-weight: 700;
            font-size: 12px; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 6px;
            transition: transform .2s, box-shadow .2s;
        }
        .month-bar .btn-report:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .month-bar .btn-report:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .month-bar .working-days-info {
            font-size: 12px; color: var(--ink-soft); font-weight: 500;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px; margin-bottom: 20px;
        }
        .kpi-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            text-align: center; transition: transform .3s, box-shadow .3s;
            position: relative; overflow: hidden;
        }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .kpi-card .kpi-icon {
            width: 44px; height: 44px; border-radius: 12px; display: flex;
            align-items: center; justify-content: center; font-size: 18px;
            margin: 0 auto 8px;
        }
        .kpi-card .kpi-icon.gold { background: var(--gold-100); color: var(--gold-500); }
        .kpi-card .kpi-icon.green { background: #e4f4e8; color: var(--success); }
        .kpi-card .kpi-icon.blue { background: #e6eefb; color: var(--info); }
        .kpi-card .kpi-icon.red { background: #fbe7e7; color: var(--danger); }
        .kpi-card .kpi-icon.purple { background: #f3e8ff; color: var(--purple); }
        .kpi-card .kpi-value { font-family: 'Fraunces', serif; font-size: 30px; font-weight: 700; color: var(--navy-950); line-height: 1; }
        .kpi-card .kpi-label { font-size: 11px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
        .kpi-card .kpi-sub { font-size: 10px; color: var(--ink-soft); margin-top: 2px; }
        .kpi-card.highlight { border: 2px solid var(--gold-500); background: #fffdf8; }

        /* ============ LEADERBOARD SECTION ============ */
        .leaderboard-section {
            background: var(--paper); border-radius: var(--radius); padding: 22px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .leaderboard-section h3 {
            font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950);
            margin-bottom: 16px; display: flex; align-items: center; gap: 10px;
        }
        .leaderboard-section h3 i { color: var(--gold-500); }

        /* Podium */
        .podium {
            display: flex; justify-content: center; align-items: flex-end; gap: 12px;
            margin-bottom: 20px; padding: 10px 0;
        }
        .podium-item {
            text-align: center; display: flex; flex-direction: column; align-items: center;
            transition: transform .3s;
        }
        .podium-item:hover { transform: translateY(-6px); }
        .podium-item .podium-rank {
            width: 44px; height: 44px; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 20px;
            font-weight: 700; margin-bottom: 4px; border: 3px solid;
        }
        .podium-item.gold .podium-rank { background: #fff9e6; color: #d4a017; border-color: #d4a017; font-size: 24px; }
        .podium-item.silver .podium-rank { background: #f8f8f8; color: #888; border-color: #aaa; }
        .podium-item.bronze .podium-rank { background: #fdf5f0; color: #c9783a; border-color: #c9783a; }
        .podium-item .podium-photo {
            width: 50px; height: 50px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--line); margin-bottom: 4px;
        }
        .podium-item .podium-photo-placeholder {
            width: 50px; height: 50px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #aaa;
        }
        .podium-item .podium-name { font-weight: 600; font-size: 12px; color: var(--navy-950); max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .podium-item .podium-sales { font-family: 'Fraunces', serif; font-weight: 700; font-size: 14px; color: var(--navy-950); }
        .podium-item .podium-bar {
            width: 70px; border-radius: 8px 8px 0 0; margin-top: 4px;
        }
        .podium-item.gold .podium-bar { background: linear-gradient(180deg, #ffd700, #ffb800); height: 80px; }
        .podium-item.silver .podium-bar { background: linear-gradient(180deg, #c0c0c0, #a0a0a0); height: 60px; }
        .podium-item.bronze .podium-bar { background: linear-gradient(180deg, #cd7f32, #a0522d); height: 40px; }

        /* My Rank Card */
        .my-rank-card {
            background: linear-gradient(135deg, #fffdf5, #fff9e6);
            border: 2px solid var(--gold-500); border-radius: var(--radius-sm);
            padding: 16px 20px; display: flex; align-items: center; gap: 16px;
            margin-bottom: 16px; flex-wrap: wrap;
        }
        .my-rank-card .rank-badge {
            width: 56px; height: 56px; border-radius: 50%; background: var(--gold-500);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-size: 24px; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }
        .my-rank-card .rank-info h4 { font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950); }
        .my-rank-card .rank-info .rank-detail { font-size: 12px; color: var(--ink-soft); }
        .my-rank-card .rank-stats { display: flex; gap: 20px; margin-left: auto; flex-wrap: wrap; }
        .my-rank-card .rank-stat { text-align: center; }
        .my-rank-card .rank-stat .rs-val { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--navy-950); }
        .my-rank-card .rank-stat .rs-lbl { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; }

        /* Leaderboard Table */
        .lb-table-wrap { max-height: 350px; overflow-y: auto; }
        .lb-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .lb-table th {
            background: var(--navy-800); color: #fff; padding: 10px 12px; text-align: left;
            font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
            position: sticky; top: 0; z-index: 1;
        }
        .lb-table td { padding: 10px 12px; border-bottom: 1px solid var(--line); }
        .lb-table tr:hover { background: var(--cream); }
        .lb-table tr.is-me { background: #fffdf5; border-left: 3px solid var(--gold-500); }
        .lb-table .lb-rank { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 700; text-align: center; width: 50px; }
        .lb-table .lb-photo {
            width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
        }
        .lb-table .lb-photo-placeholder {
            width: 32px; height: 32px; border-radius: 50%; background: var(--cream);
            display: inline-flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .lb-table .lb-progress {
            display: inline-block; width: 60px; height: 5px; background: var(--cream);
            border-radius: 5px; overflow: hidden; vertical-align: middle; margin-right: 6px;
        }
        .lb-table .lb-progress .lb-fill { height: 100%; border-radius: 5px; }

        /* Target Progress */
        .target-card {
            background: var(--paper); border-radius: var(--radius); padding: 22px 26px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .target-card h3 {
            font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950);
            margin-bottom: 14px; display: flex; align-items: center; gap: 10px;
        }
        .target-card h3 i { color: var(--gold-500); }
        .progress-main { margin-bottom: 14px; }
        .progress-main .prog-bar {
            background: var(--cream); border-radius: 30px; height: 16px; overflow: hidden;
            border: 1px solid var(--line);
        }
        .progress-main .prog-fill {
            height: 100%; border-radius: 30px;
            background: linear-gradient(90deg, #e9c877, #c9962f, #dcae4d);
            transition: width .8s var(--ease); position: relative;
        }
        .progress-main .prog-info {
            display: flex; justify-content: space-between; margin-top: 8px;
            font-size: 13px; color: var(--ink-soft);
        }
        .progress-main .prog-info strong { color: var(--navy-950); }
        .target-insights {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px; margin-top: 12px;
        }
        .insight-item {
            background: var(--cream); padding: 12px 14px; border-radius: var(--radius-sm);
            text-align: center; border: 1px solid var(--line);
        }
        .insight-item .insight-value { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--navy-950); }
        .insight-item .insight-label { font-size: 10px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; }

        /* Charts Grid */
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }
        .chart-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
        }
        .chart-card h3 {
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }
        .chart-card h3 i { color: var(--gold-500); }
        .chart-wrap { position: relative; height: 300px; }
        .chart-wrap canvas { width: 100% !important; }

        /* Sales Table */
        .table-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); overflow-x: auto;
        }
        .table-card h3 {
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }
        .sales-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .sales-table thead th {
            background: var(--navy-800); color: #fff; padding: 10px 12px; text-align: center;
            font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
            position: sticky; top: 0;
        }
        .sales-table thead th:first-child { text-align: left; border-radius: 8px 0 0 0; }
        .sales-table thead th:last-child { border-radius: 0 8px 0 0; }
        .sales-table tbody td {
            padding: 10px 12px; text-align: center; border-bottom: 1px solid var(--line);
        }
        .sales-table tbody td:first-child { text-align: left; font-weight: 500; }
        .sales-table tbody tr:hover { background: var(--cream); }
        .sales-table tbody tr.today { background: #fffdf5; }
        .sales-table .day-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 600;
        }
        .sales-table .day-badge.weekday { background: #f0f0f0; color: var(--ink-soft); }
        .sales-table .day-badge.weekend { background: #fef0f0; color: var(--danger); }
        .status-dot {
            display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px;
        }
        .status-dot.approved { background: var(--success); }
        .status-dot.pending { background: var(--warn); }
        .status-dot.rejected { background: var(--danger); }
        .no-data { color: #ddd; }

        /* Attendance Summary */
        .att-summary { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .att-chip {
            padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 600;
        }
        .att-chip.present { background: #e4f4e8; color: var(--success); }
        .att-chip.absent { background: #fbe7e7; color: var(--danger); }
        .att-chip.half { background: var(--gold-100); color: var(--warn); }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 1024px) { .charts-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-card .kpi-value { font-size: 24px; }
            .header { padding: 10px 16px; }
            .profile-bar { flex-direction: column; text-align: center; }
            .profile-bar .badges { margin-left: 0; justify-content: center; }
            .chart-wrap { height: 250px; }
            .sales-table { font-size: 10px; }
            .sales-table thead th, .sales-table tbody td { padding: 6px 8px; }
            .podium-item .podium-bar { width: 50px; }
            .podium-item.gold .podium-bar { height: 50px; }
            .podium-item.silver .podium-bar { height: 38px; }
            .podium-item.bronze .podium-bar { height: 28px; }
            .my-rank-card { flex-direction: column; text-align: center; }
            .my-rank-card .rank-stats { margin-left: 0; justify-content: center; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            <div class="logo">BG</div>
            <div class="title">BetterGrowth<span>Solutions</span></div>
        </div>
        <div class="nav">
            <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="sales.php" class="active"><i class="fas fa-chart-line"></i> My Sales</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <!-- ===== TOAST POPUP ===== -->
    <div class="toast-overlay" id="toastContainer">
        <div class="toast-msg" id="infoToast">
            <span class="toast-icon">ℹ️</span>
            <div>
                <strong>📢 Important Note</strong><br>
                Sales data updates at the <strong>end of each day</strong>. Changes may reflect the <strong>next day</strong>.<br>
                For <strong>live sales tracking</strong>, please use the live sheet provided by your manager.
            </div>
            <button class="toast-close" onclick="closeToast()">×</button>
        </div>
    </div>

    <div class="content">
        <!-- Profile Bar -->
        <div class="profile-bar">
            <?php if (!empty($photoUrl)): ?>
                <img src="<?php echo $photoUrl; ?>" class="photo" alt="Photo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <div class="photo-placeholder" style="display:none;">👤</div>
            <?php else: ?>
                <div class="photo-placeholder">👤</div>
            <?php endif; ?>
            <div class="info">
                <h2><?php echo htmlspecialchars($name); ?></h2>
                <div class="meta">
                    <span><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($agentId); ?></span>
                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div class="badges">
                <span class="badge shift"><i class="fas <?php echo $shiftType === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i> <?php echo ucfirst($shiftType); ?> Shift</span>
                <span class="badge location"><i class="fas <?php echo $workLocation === 'office' ? 'fa-building' : 'fa-home'; ?>"></i> <?php echo ucfirst($workLocation); ?></span>
            </div>
        </div>

        <!-- Month Selector -->
        <div class="month-bar">
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
            <span class="working-days-info">
                📅 <?php echo $workingDaysPassed; ?>/<?php echo $totalWorkingDays; ?> working days | 
                ✅ Present: <?php echo $attSummary['present']; ?> |
                ❌ Absent: <?php echo $attSummary['absent']; ?>
            </span>
            <button class="btn-report" onclick="requestReport()" id="btnRequest">
                <i class="fas fa-envelope"></i> Request Email Report
            </button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card highlight">
                <div class="kpi-icon gold"><i class="fas fa-trophy"></i></div>
                <div class="kpi-value"><?php echo number_format($totalAllSales); ?></div>
                <div class="kpi-label">Total Sales</div>
                <div class="kpi-sub">Target: <?php echo number_format($monthlyTarget); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fas fa-bullseye"></i></div>
                <div class="kpi-value"><?php echo $progressPercent; ?>%</div>
                <div class="kpi-label">Target Achieved</div>
                <div class="kpi-sub"><?php echo $daysRemaining; ?> days remaining</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fas fa-star"></i></div>
                <div class="kpi-value"><?php echo number_format($totalCreditScore); ?></div>
                <div class="kpi-label">Credit Score</div>
                <div class="kpi-sub">Main KPI</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fas fa-ranking-star"></i></div>
                <div class="kpi-value">#<?php echo $myRank; ?> / <?php echo $totalAgents; ?></div>
                <div class="kpi-label">Your Rank</div>
                <div class="kpi-sub">Leaderboard Position</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-value"><?php echo $attSummary['present']; ?>/<?php echo $totalWorkingDays; ?></div>
                <div class="kpi-label">Present Days</div>
                <div class="kpi-sub">
                    <span class="att-chip present"><?php echo $attSummary['present']; ?> P</span>
                    <span class="att-chip absent"><?php echo $attSummary['absent']; ?> A</span>
                </div>
            </div>
        </div>
  <!-- -->
        
        
        
        
        
        
        <?php
/*
    
    
    
    
    <!-- ============ LEADERBOARD SECTION ============ 
    <!--    <div class="leaderboard-section">
            <h3><i class="fas fa-trophy"></i> 🏆 Leaderboard - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>

            <!-- Podium Top 3 -->
            <?php if (count($top3) >= 3): ?>
            <div class="podium">
                <?php 
                $podiumOrder = [
                    1 => ['class' => 'gold', 'medal' => '🥇', 'rank' => 1],
                    0 => ['class' => 'silver', 'medal' => '🥈', 'rank' => 2],
                    2 => ['class' => 'bronze', 'medal' => '🥉', 'rank' => 3],
                ];
                foreach ($podiumOrder as $idx => $pd):
                    if (!isset($top3[$idx])) continue;
                    $p = $top3[$idx];
                    $pPhoto = getAgentPhotoUrl($p['agent_id'], $p['candidate_photo_path']);
                ?>
                <div class="podium-item <?php echo $pd['class']; ?>">
                    <div class="podium-rank"><?php echo $pd['medal']; ?></div>
                    <?php if (!empty($pPhoto)): ?>
                        <img src="<?php echo $pPhoto; ?>" class="podium-photo" alt="Photo">
                    <?php else: ?>
                        <div class="podium-photo-placeholder">👤</div>
                    <?php endif; ?>
                    <div class="podium-name" title="<?php echo htmlspecialchars($p['full_name']); ?>"><?php echo htmlspecialchars($p['full_name']); ?></div>
                    <div class="podium-sales"><?php echo number_format($p['total_sales']); ?></div>
                    <div class="podium-bar"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- My Rank Card -->
            <?php if ($myRank > 0): ?>
            <div class="my-rank-card">
                <div class="rank-badge">#<?php echo $myRank; ?></div>
                <div class="rank-info">
                    <h4>Your Position</h4>
                    <div class="rank-detail">
                        <?php if ($myRank === 1): ?>
                            🎉 You're leading the board!
                        <?php elseif ($myRank <= 3): ?>
                            🔥 You're in the Top 3! Keep it up!
                        <?php elseif ($myRank <= 5): ?>
                            💪 Top 5! Push harder for podium!
                        <?php else: ?>
                            Out of <?php echo $totalAgents; ?> agents | 
                            <?php 
                                $above = $leaderboard[$myRank - 2] ?? null;
                                if ($above) {
                                    $diff = $above['total_sales'] - $totalAllSales;
                                    echo "Need " . number_format($diff + 1) . " more to overtake #" . ($myRank - 1);
                                }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rank-stats">
                    <div class="rank-stat">
                        <div class="rs-val"><?php echo number_format($totalAllSales); ?></div>
                        <div class="rs-lbl">Your Sales</div>
                    </div>
                    <div class="rank-stat">
                        <div class="rs-val"><?php echo number_format($myRank <= 1 ? $totalAllSales : ($leaderboard[$myRank - 2]['total_sales'] ?? 0)); ?></div>
                        <div class="rs-lbl">Above You</div>
                    </div>
                    <div class="rank-stat">
                        <div class="rs-val"><?php echo number_format($totalCreditScore); ?></div>
                        <div class="rs-lbl">Credit Score</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Full Leaderboard Table -->
            <div class="lb-table-wrap">
                <table class="lb-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Agent</th>
                            <th>Total Sales</th>
                            <th>Credit Score</th>
                            <th>Target</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayRank = 1;
                        foreach ($leaderboard as $lb):
                            $isMe = ($lb['agent_id'] === $agentId);
                            $lbPhoto = getAgentPhotoUrl($lb['agent_id'], $lb['candidate_photo_path']);
                            $lbTarget = $lb['monthly_target'] ?? 0;
                            $lbPct = $lbTarget > 0 ? min(100, round(($lb['total_sales'] / $lbTarget) * 100)) : 0;
                        ?>
                        <tr class="<?php echo $isMe ? 'is-me' : ''; ?>">
                            <td class="lb-rank">
                                <?php if ($displayRank === 1): ?>🥇
                                <?php elseif ($displayRank === 2): ?>🥈
                                <?php elseif ($displayRank === 3): ?>🥉
                                <?php else: ?>#<?php echo $displayRank; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <?php if (!empty($lbPhoto)): ?>
                                        <img src="<?php echo $lbPhoto; ?>" class="lb-photo" alt="Photo">
                                    <?php else: ?>
                                        <span class="lb-photo-placeholder">👤</span>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($lb['full_name']); ?>
                                        <?php echo $isMe ? '<span style="color:var(--gold-500);font-weight:700;"> (You)</span>' : ''; ?>
                                    </span>
                                </div>
                            </td>
                            <td><strong><?php echo number_format($lb['total_sales']); ?></strong></td>
                            <td><?php echo number_format($lb['credit_score']); ?></td>
                            <td><?php echo number_format($lbTarget); ?></td>
                            <td>
                                <div class="lb-progress"><div class="lb-fill" style="width:<?php echo $lbPct; ?>%;background:<?php echo $lbPct>=100?'var(--success)':($lbPct>=50?'var(--gold-500)':'var(--danger)'); ?>;"></div></div>
                                <?php echo $lbPct; ?>%
                            </td>
                        </tr>
                        <?php $displayRank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>




*/
?>
        
        
        
        
        
        
        <!-- Target Progress -->
        <div class="target-card">
            <h3><i class="fas fa-trophy"></i> Monthly Target Progress</h3>
            <div class="progress-main">
                <div class="prog-bar">
                    <div class="prog-fill" style="width: <?php echo $progressPercent; ?>%;"></div>
                </div>
                <div class="prog-info">
                    <span>0</span>
                    <span><strong><?php echo number_format($totalAllSales); ?> / <?php echo number_format($monthlyTarget); ?></strong></span>
                    <span><?php echo number_format($monthlyTarget); ?></span>
                </div>
            </div>
            <div class="target-insights">
                <div class="insight-item">
                    <div class="insight-value"><?php echo number_format($dailyTarget, 1); ?></div>
                    <div class="insight-label">Daily Target Needed</div>
                </div>
                <div class="insight-item">
                    <div class="insight-value"><?php echo number_format($avgDailySales, 1); ?></div>
                    <div class="insight-label">Current Avg/Day</div>
                </div>
                <div class="insight-item">
                    <div class="insight-value"><?php echo number_format($salesNeededPerDay, 1); ?></div>
                    <div class="insight-label">Needed/Day to Hit Target</div>
                </div>
                <div class="insight-item">
                    <div class="insight-value"><?php echo number_format($projectedSales); ?></div>
                    <div class="insight-label">Projected Month-End</div>
                </div>
            </div>
            <div style="display:flex;gap:16px;margin-top:12px;flex-wrap:wrap;font-size:12px;color:var(--ink-soft);">
                <span>⭐ Credit Score: <strong><?php echo number_format($totalCreditScore); ?></strong></span>
                <span>🛡️ Credit Strong: <strong><?php echo number_format($totalCreditStrong); ?></strong></span>
                <span>🏦 KikOff Bank: <strong><?php echo number_format($totalKikoff); ?></strong></span>
                <span>📦 Other: <strong><?php echo number_format($totalOther); ?></strong></span>
                <span style="margin-left:auto;">✅ Approved: <strong><?php echo $approvedSales; ?></strong> | ⏳ Pending: <strong><?php echo $pendingSales; ?></strong></span>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Daily Sales Breakdown</h3>
                <div class="chart-wrap"><canvas id="dailySalesChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-line"></i> Cumulative Sales Trend</h3>
                <div class="chart-wrap"><canvas id="cumulativeChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie"></i> Sales Distribution</h3>
                <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-area"></i> 6 Months Performance</h3>
                <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <!-- Daily Sales Table -->
        <div class="table-card">
            <h3><i class="fas fa-table"></i> Daily Sales Details - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h3>
            <div style="max-height: 500px; overflow-y: auto;">
                <table class="sales-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Credit Score ⭐</th>
                            <th>Credit Strong 🛡️</th>
                            <th>KikOff 🏦</th>
                            <th>Other 📦</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                            $date = sprintf('%s-%02d', $selectedMonth, $day);
                            $sale = $dailySales[$date] ?? null;
                            $isToday = ($date === $today);
                            $dayOfWeek = date('N', strtotime($date));
                            $isWeekend = $dayOfWeek >= 6;
                        ?>
                        <tr class="<?php echo $isToday ? 'today' : ''; ?>">
                            <td><?php echo date('M d', strtotime($date)); ?></td>
                            <td>
                                <span class="day-badge <?php echo $isWeekend ? 'weekend' : 'weekday'; ?>">
                                    <?php echo date('D', strtotime($date)); ?>
                                </span>
                                <?php echo $isToday ? ' 📌' : ''; ?>
                            </td>
                            <td><?php echo $sale ? number_format($sale['credit_score']) : '<span class="no-data">—</span>'; ?></td>
                            <td><?php echo $sale ? number_format($sale['credit_strong']) : '<span class="no-data">—</span>'; ?></td>
                            <td><?php echo $sale ? number_format($sale['kikoff']) : '<span class="no-data">—</span>'; ?></td>
                            <td><?php echo $sale ? number_format($sale['other_sales']) : '<span class="no-data">—</span>'; ?></td>
                            <td><strong><?php echo $sale ? number_format($sale['total_sales']) : '<span class="no-data">—</span>'; ?></strong></td>
                            <td>
                                <?php if ($sale): ?>
                                    <span class="status-dot <?php echo $sale['status']; ?>"></span>
                                    <?php echo ucfirst($sale['status']); ?>
                                <?php else: ?>
                                    <span class="no-data">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // ============================================================
        // TOAST POPUP - Auto close after 3 seconds
        // ============================================================
        function closeToast() {
            const toast = document.getElementById('infoToast');
            if (toast) {
                toast.style.animation = 'fadeOut .5s ease forwards';
                setTimeout(function() { toast.remove(); }, 500);
            }
        }
        
        // Auto close after 3 seconds
        setTimeout(function() {
            closeToast();
        }, 3000);

        // ============================================================
        // CHARTS
        // ============================================================
        // Daily Sales Breakdown Chart
        new Chart(document.getElementById('dailySalesChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    { label: 'Credit Score', data: <?php echo json_encode($chartCreditScore); ?>, backgroundColor: '#c9962f', borderRadius: 4, stack: 'stack1' },
                    { label: 'Credit Strong', data: <?php echo json_encode($chartCreditStrong); ?>, backgroundColor: '#29538c', borderRadius: 4, stack: 'stack1' },
                    { label: 'KikOff', data: <?php echo json_encode($chartKikoff); ?>, backgroundColor: '#1e7a41', borderRadius: 4, stack: 'stack1' },
                    { label: 'Other', data: <?php echo json_encode($chartOther); ?>, backgroundColor: '#9a6a10', borderRadius: 4, stack: 'stack1' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 10 } } } },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 } } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } }
                }
            }
        });

        // Cumulative Sales Chart
        new Chart(document.getElementById('cumulativeChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Cumulative Sales',
                    data: <?php echo json_encode($cumulativeSales); ?>,
                    borderColor: '#c9962f', backgroundColor: 'rgba(201,150,47,.1)',
                    fill: true, tension: .3, borderWidth: 3,
                    pointRadius: 3, pointBackgroundColor: '#c9962f'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false }, ticks: { font: { size: 9 } } }
                }
            }
        });

        // Pie Chart
        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Credit Score', 'Credit Strong', 'KikOff', 'Other'],
                datasets: [{
                    data: [<?php echo $totalCreditScore; ?>, <?php echo $totalCreditStrong; ?>, <?php echo $totalKikoff; ?>, <?php echo $totalOther; ?>],
                    backgroundColor: ['#c9962f', '#29538c', '#1e7a41', '#9a6a10'],
                    borderWidth: 3, borderColor: '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: { size: 11 } } } }
            }
        });

        // 6 Month Trend
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendMonths); ?>,
                datasets: [{
                    label: 'My Sales', data: <?php echo json_encode($trendSales); ?>,
                    borderColor: '#c9962f', backgroundColor: 'rgba(201,150,47,.1)',
                    fill: true, tension: .4, borderWidth: 3,
                    pointRadius: 5, pointBackgroundColor: '#c9962f'
                }, {
                    label: 'Target', data: <?php echo json_encode($trendTargets); ?>,
                    borderColor: '#0c1a38', borderDash: [6, 4], borderWidth: 2,
                    pointRadius: 3, pointBackgroundColor: '#0c1a38'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { size: 10 } } } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });

        // ============================================================
        // REQUEST REPORT
        // ============================================================
        function requestReport() {
            const btn = document.getElementById('btnRequest');
            if (!confirm('Request your monthly sales report via email? Admin will approve it.')) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting...';
            
            fetch('/raj/api/sales-api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'request_report',
                    agent_id: '<?php echo $agentId; ?>',
                    month: '<?php echo $selectedMonth; ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Report requested successfully! Admin will review and send it to your email.');
                } else {
                    alert('⚠️ ' + (data.message || 'Error requesting report'));
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-envelope"></i> Request Email Report';
            })
            .catch(err => {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-envelope"></i> Request Email Report';
            });
        }
    </script>
</body>
</html>