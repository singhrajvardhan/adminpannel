<?php
// /raj/agent/daily-report.php
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
if (!$agentId) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$agent = getAgent($agentId);
if (!$agent) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($agent['onboarding_completed'] != 1) {
    header('Location: onboarding.php');
    exit;
}

$today = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $today;

// Get attendance for selected date
$attendanceResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $selectedDate]);
$attendance = [];
if ($attendanceResult && $attendanceResult->num_rows > 0) {
    $attendance = $attendanceResult->fetch_assoc();
}

// Calculate working hours
$totalSeconds = 0;
if (!empty($attendance['job_start_time'])) {
    $start = strtotime($attendance['job_start_time']);
    $end = !empty($attendance['job_end_time']) ? strtotime($attendance['job_end_time']) : time();
    $totalSeconds = $end - $start;
    
    // Subtract pause time
    $totalPause = (int)($attendance['total_pause_seconds'] ?? 0);
    if ($attendance['job_paused'] == 1 && !empty($attendance['job_pause_time'])) {
        $pauseTime = strtotime($attendance['job_pause_time']);
        $totalPause += (time() - $pauseTime);
    }
    $totalSeconds = max(0, $totalSeconds - $totalPause);
    
    // Subtract break times
    $breakSeconds = 0;
    if (!empty($attendance['lunch_start']) && !empty($attendance['lunch_end'])) {
        $breakSeconds += strtotime($attendance['lunch_end']) - strtotime($attendance['lunch_start']);
    }
    if (!empty($attendance['bio_start']) && !empty($attendance['bio_end'])) {
        $breakSeconds += strtotime($attendance['bio_end']) - strtotime($attendance['bio_start']);
    }
    if (!empty($attendance['snack_start']) && !empty($attendance['snack_end'])) {
        $breakSeconds += strtotime($attendance['snack_end']) - strtotime($attendance['snack_start']);
    }
    $totalSeconds = max(0, $totalSeconds - $breakSeconds);
}

$hours = floor($totalSeconds / 3600);
$minutes = floor(($totalSeconds % 3600) / 60);
$totalHoursDisplay = sprintf("%02d:%02d", $hours, $minutes);

// Get weekly data
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyData = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($weekStart . ' + ' . $i . ' days'));
    $dayResult = executeQuery("SELECT job_start_time, job_end_time, total_pause_seconds, lunch_start, lunch_end, bio_start, bio_end, snack_start, snack_end FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $date]);
    if ($dayResult && $dayResult->num_rows > 0) {
        $dayAtt = $dayResult->fetch_assoc();
        $secs = 0;
        if (!empty($dayAtt['job_start_time']) && !empty($dayAtt['job_end_time'])) {
            $s = strtotime($dayAtt['job_end_time']) - strtotime($dayAtt['job_start_time']);
            $pause = (int)($dayAtt['total_pause_seconds'] ?? 0);
            $breakSecs = 0;
            if (!empty($dayAtt['lunch_start']) && !empty($dayAtt['lunch_end'])) {
                $breakSecs += strtotime($dayAtt['lunch_end']) - strtotime($dayAtt['lunch_start']);
            }
            if (!empty($dayAtt['bio_start']) && !empty($dayAtt['bio_end'])) {
                $breakSecs += strtotime($dayAtt['bio_end']) - strtotime($dayAtt['bio_start']);
            }
            if (!empty($dayAtt['snack_start']) && !empty($dayAtt['snack_end'])) {
                $breakSecs += strtotime($dayAtt['snack_end']) - strtotime($dayAtt['snack_start']);
            }
            $secs = max(0, $s - $pause - $breakSecs);
        }
        $weeklyData[$date] = $secs;
    } else {
        $weeklyData[$date] = 0;
    }
}

// Get all attendance records for this month
$monthStart = date('Y-m-01');
$monthResult = executeQuery("SELECT date, job_start_time, job_end_time FROM attendance WHERE agent_id = ? AND date >= ? ORDER BY date DESC", [$agentId, $monthStart]);
$monthRecords = [];
if ($monthResult) {
    while ($row = $monthResult->fetch_assoc()) {
        $monthRecords[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Report - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
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
        .header {
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 14px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-md);
        }
        .header .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header .brand .logo-mark {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Fraunces', serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--navy-950);
        }
        .header .brand .brand-text {
            font-family: 'Fraunces', serif;
            font-size: 16px;
            font-weight: 600;
            color: #fff;
        }
        .header .brand .brand-text span {
            color: var(--gold-300);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            display: block;
            font-weight: 400;
        }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
        }
        .header .logout {
            color: #f2a3a0;
            text-decoration: none;
            padding: 6px 16px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,.08);
            transition: background .25s var(--ease), transform .25s var(--ease);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 13px;
        }
        .header .logout:hover {
            background: rgba(240,90,80,.15);
            transform: translateY(-1px);
        }
        .content { max-width: 1200px; margin: 0 auto; padding: 24px 30px 40px; }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--navy-800);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: var(--paper);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm);
            transition: transform .2s;
        }
        .back-btn:hover { transform: translateX(-4px); }
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
        .date-selector {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .date-selector input[type="date"] {
            padding: 8px 14px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: var(--cream);
            color: var(--ink);
        }
        .date-selector input[type="date"]:focus {
            border-color: var(--gold-500);
            outline: none;
        }
        .date-selector .btn {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s;
        }
        .date-selector .btn:hover { transform: translateY(-2px); }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-item {
            background: var(--cream);
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--line);
        }
        .stat-item .number {
            font-family: 'Fraunces', serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--navy-950);
        }
        .stat-item .label {
            font-size: 11px;
            color: var(--ink-soft);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 500;
        }
        .stat-item .number.gold { color: var(--gold-500); }
        .stat-item .number.green { color: var(--success-ink); }
        .stat-item .number.blue { color: var(--info-ink); }
        .break-details { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .break-item {
            background: var(--cream);
            padding: 12px;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--line);
        }
        .break-item .label { font-size: 11px; color: var(--ink-soft); font-weight: 500; }
        .break-item .value { font-size: 14px; font-weight: 600; color: var(--navy-950); }
        .break-item .value.done { color: var(--success-ink); }
        .break-item .value.pending { color: var(--warn-ink); }
        .week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .week-day {
            text-align: center;
            padding: 8px 4px;
            border-radius: var(--radius-sm);
            background: var(--cream);
            border: 1px solid var(--line);
        }
        .week-day .day-name { font-size: 10px; color: var(--ink-soft); font-weight: 600; text-transform: uppercase; }
        .week-day .day-date { font-size: 13px; font-weight: 700; color: var(--navy-950); }
        .week-day .day-hours { font-size: 11px; color: var(--gold-500); font-weight: 600; }
        .week-day.today { background: var(--gold-300); border-color: var(--gold-500); }
        .week-day.today .day-name { color: var(--navy-950); }
        .week-day.today .day-date { color: var(--navy-950); }
        .week-day.weekend { opacity: 0.5; }
        .month-list { max-height: 200px; overflow-y: auto; }
        .month-list::-webkit-scrollbar { width: 4px; }
        .month-list::-webkit-scrollbar-thumb { background: var(--gold-300); border-radius: 10px; }
        .month-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid var(--cream);
            font-size: 13px;
        }
        .month-item:last-child { border-bottom: none; }
        .month-item .date { color: var(--ink-soft); }
        .month-item .hours { font-weight: 600; color: var(--navy-950); }
        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: stretch; text-align: center; padding: 14px 18px; }
            .header .brand { justify-content: center; }
            .header .user-info { justify-content: center; flex-wrap: wrap; }
            .content { padding: 16px; }
            .break-details { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: 1fr 1fr; }
            .week-grid { gap: 4px; }
            .week-day { padding: 4px 2px; }
            .week-day .day-date { font-size: 11px; }
            .week-day .day-hours { font-size: 9px; }
        }
        @media (max-width: 400px) {
            .stat-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            <div class="logo-mark">BG</div>
            <div class="brand-text">
                BetterGrowth
                <span>Solutions</span>
            </div>
        </div>
        <div class="user-info">
            <span class="name"><?php echo htmlspecialchars($agent['full_name'] ?: 'Employee'); ?></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <!-- Date Selector -->
        <div class="report-card">
            <h3><i class="fas fa-calendar-day"></i> Daily Report</h3>
            <div class="date-selector">
                <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <input type="date" name="date" value="<?php echo $selectedDate; ?>">
                    <button type="submit" class="btn"><i class="fas fa-search"></i> View Report</button>
                    <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn" style="background:var(--cream);color:var(--ink-soft);border:2px solid var(--line);">Today</a>
                </form>
            </div>
        </div>

        <?php if (!empty($attendance)): ?>
        <!-- Stats -->
        <div class="report-card">
            <h3><i class="fas fa-chart-bar"></i> Summary for <?php echo date('d M Y', strtotime($selectedDate)); ?></h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <div class="number gold"><?php echo $totalHoursDisplay; ?></div>
                    <div class="label">Total Hours</div>
                </div>
                <div class="stat-item">
                    <div class="number blue"><?php echo !empty($attendance['login_time']) ? date('h:i A', strtotime($attendance['login_time'])) : '--:--'; ?></div>
                    <div class="label">Login Time</div>
                </div>
                <div class="stat-item">
                    <div class="number <?php echo !empty($attendance['logout_time']) ? 'green' : 'gold'; ?>">
                        <?php echo !empty($attendance['logout_time']) ? date('h:i A', strtotime($attendance['logout_time'])) : 'Active'; ?>
                    </div>
                    <div class="label">Logout Time</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?php echo !empty($attendance['job_start_time']) ? date('h:i A', strtotime($attendance['job_start_time'])) : '--:--'; ?></div>
                    <div class="label">Job Start</div>
                </div>
            </div>
        </div>

        <!-- Break Details -->
        <div class="report-card">
            <h3><i class="fas fa-coffee"></i> Break Details</h3>
            <div class="break-details">
                <div class="break-item">
                    <div class="label">Lunch Break (30m)</div>
                    <div class="value <?php echo !empty($attendance['lunch_start']) && !empty($attendance['lunch_end']) ? 'done' : 'pending'; ?>">
                        <?php
                            if (!empty($attendance['lunch_start']) && !empty($attendance['lunch_end'])) {
                                echo '✅ ' . date('h:i A', strtotime($attendance['lunch_start'])) . ' - ' . date('h:i A', strtotime($attendance['lunch_end']));
                            } elseif (!empty($attendance['lunch_start'])) {
                                echo '⏳ Started at ' . date('h:i A', strtotime($attendance['lunch_start']));
                            } else {
                                echo '⏳ Not Taken';
                            }
                        ?>
                    </div>
                </div>
                <div class="break-item">
                    <div class="label">Snack Break (15m)</div>
                    <div class="value <?php echo !empty($attendance['snack_start']) && !empty($attendance['snack_end']) ? 'done' : 'pending'; ?>">
                        <?php
                            if (!empty($attendance['snack_start']) && !empty($attendance['snack_end'])) {
                                echo '✅ ' . date('h:i A', strtotime($attendance['snack_start'])) . ' - ' . date('h:i A', strtotime($attendance['snack_end']));
                            } elseif (!empty($attendance['snack_start'])) {
                                echo '⏳ Started at ' . date('h:i A', strtotime($attendance['snack_start']));
                            } else {
                                echo '⏳ Not Taken';
                            }
                        ?>
                        <span class="snack-counter" style="display:inline-block;background:var(--warn-bg);padding:0 8px;border-radius:10px;font-size:10px;color:var(--warn-ink);">
                            <?php echo (int)($attendance['snack_count'] ?? 0); ?>/2
                        </span>
                    </div>
                </div>
                <div class="break-item">
                    <div class="label">Bio Break (No Limit)</div>
                    <div class="value <?php echo !empty($attendance['bio_start']) && !empty($attendance['bio_end']) ? 'done' : 'pending'; ?>">
                        <?php
                            if (!empty($attendance['bio_start']) && !empty($attendance['bio_end'])) {
                                echo '✅ ' . date('h:i A', strtotime($attendance['bio_start'])) . ' - ' . date('h:i A', strtotime($attendance['bio_end']));
                            } elseif (!empty($attendance['bio_start'])) {
                                echo '⏳ Started at ' . date('h:i A', strtotime($attendance['bio_start']));
                            } else {
                                echo '⏳ Not Taken';
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="report-card">
            <div style="text-align:center;padding:30px;color:var(--ink-soft);">
                <i class="fas fa-calendar" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                <p>No attendance data found for <?php echo date('d M Y', strtotime($selectedDate)); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Weekly Overview -->
        <div class="report-card">
            <h3><i class="fas fa-calendar-week"></i> This Week</h3>
            <div class="week-grid">
                <?php
                $weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                for ($i = 0; $i < 7; $i++):
                    $date = date('Y-m-d', strtotime($weekStart . ' + ' . $i . ' days'));
                    $dayName = $weekDays[$i];
                    $dayNum = date('d', strtotime($date));
                    $isToday = ($date == $today);
                    $isWeekend = ($i >= 5);
                    $hours = $weeklyData[$date] ?? 0;
                    $h = floor($hours / 3600);
                    $m = floor(($hours % 3600) / 60);
                    $display = ($hours > 0) ? sprintf("%02d:%02d", $h, $m) : '--';
                ?>
                <div class="week-day <?php echo $isToday ? 'today' : ''; ?> <?php echo $isWeekend ? 'weekend' : ''; ?>">
                    <div class="day-name"><?php echo $dayName; ?></div>
                    <div class="day-date"><?php echo $dayNum; ?></div>
                    <div class="day-hours"><?php echo $display; ?></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Monthly Records -->
        <div class="report-card">
            <h3><i class="fas fa-calendar-alt"></i> This Month</h3>
            <div class="month-list">
                <?php if (!empty($monthRecords)): ?>
                    <?php foreach ($monthRecords as $record): ?>
                        <?php
                            $secs = 0;
                            if (!empty($record['job_start_time']) && !empty($record['job_end_time'])) {
                                $s = strtotime($record['job_end_time']) - strtotime($record['job_start_time']);
                                $secs = max(0, $s);
                            }
                            $h = floor($secs / 3600);
                            $m = floor(($secs % 3600) / 60);
                        ?>
                        <div class="month-item">
                            <span class="date"><?php echo date('d M Y', strtotime($record['date'])); ?></span>
                            <span class="hours"><?php echo sprintf("%02d:%02d", $h, $m); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:var(--ink-soft);text-align:center;padding:20px;">No records found this month</p>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
            <a href="identity-card.php" class="btn" style="padding:10px 24px;border:none;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--gold-400),var(--gold-500));color:var(--navy-950);font-weight:600;cursor:pointer;text-decoration:none;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;transition:transform .2s;">
                <i class="fas fa-id-card"></i> View Identity Card
            </a>
            <button onclick="window.print()" class="btn" style="padding:10px 24px;border:2px solid var(--line);border-radius:var(--radius-sm);background:var(--cream);color:var(--ink-soft);font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;transition:transform .2s;">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>
</body>
</html>