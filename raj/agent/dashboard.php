<?php
// /raj/agent/dashboard.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

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

$name = $agent['full_name'] ?: 'Employee';

// Get shift details
$shiftType = $agent['shift_type'] ?? 'day';
$workLocation = $agent['work_location'] ?? 'office';

// Get photo
$photoPath = $agent['candidate_photo_path'] ?? '';
$photoUrl = '';
if (!empty($photoPath)) {
    $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($photoPath);
}
$photoExists = false;
if (!empty($photoPath)) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $photoPath;
    if (file_exists($fullPath)) {
        $photoExists = true;
    }
}

// ============================================================
// TIME ZONE HELPERS
// ============================================================
function getIndiaTime() {
    return new DateTime('now', new DateTimeZone('Asia/Kolkata'));
}

function getUsaTime($zone = 'America/New_York') {
    return new DateTime('now', new DateTimeZone($zone));
}

function getUsaTimeZones() {
    return [
        'EST' => 'America/New_York',
        'CST' => 'America/Chicago',
        'MST' => 'America/Denver',
        'PST' => 'America/Los_Angeles'
    ];
}

/**
 * Get the current date according to US shift
 * This determines which "work day" we're on based on US timezone
 * Default: Eastern Time (America/New_York)
 */
function getUsaShiftDate($zone = 'America/New_York') {
    $usaTime = new DateTime('now', new DateTimeZone($zone));
    return $usaTime->format('Y-m-d');
}

function formatTime($dateTime) {
    return $dateTime->format('h:i:s A');
}

function formatDate($dateTime) {
    return $dateTime->format('d M Y');
}

// Get current times
$indiaTime = getIndiaTime();
$usaTimeZones = getUsaTimeZones();
$usaTimes = [];
foreach ($usaTimeZones as $label => $zone) {
    $usaTimes[$label] = getUsaTime($zone);
}

// ============================================================
// IMPORTANT: Calculate TODAY based on US shift date
// This ensures the work day aligns with US business hours
// ============================================================
$today = getUsaShiftDate(); // Date based on US Eastern time

// For display purposes, also get India date
$indiaDateStr = $indiaTime->format('d M Y');
$indiaTimeStr = $indiaTime->format('h:i:s A');

$attendance = [];

$attendanceResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
if ($attendanceResult && $attendanceResult->num_rows > 0) {
    $attendance = $attendanceResult->fetch_assoc();
} else {
    executeQuery("INSERT INTO attendance (agent_id, date, status) VALUES (?, ?, 'not_started')", [$agentId, $today]);
    $attendanceResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
    if ($attendanceResult) {
        $attendance = $attendanceResult->fetch_assoc();
    }
}

// ============================================================
// CALCULATE WORKING HOURS
// ============================================================
$totalSeconds = 0;
$isWorking = false;
$jobStartTime = null;
$isPaused = false;
$hasNotStarted = empty($attendance['job_start_time']);
$isLoggedOut = !empty($attendance['logout_time']);

// Target hours based on shift type
$targetSeconds = 9 * 3600; // 9 hours default

if (!empty($attendance['job_start_time']) && empty($attendance['logout_time'])) {
    $jobStartTime = strtotime($attendance['job_start_time']);
    $jobEndTime = !empty($attendance['job_end_time']) ? strtotime($attendance['job_end_time']) : time();
    $totalSeconds = $jobEndTime - $jobStartTime;
    $isWorking = true;
    
    $totalPause = (int)($attendance['total_pause_seconds'] ?? 0);
    if ($attendance['job_paused'] == 1 && !empty($attendance['job_pause_time'])) {
        $isPaused = true;
        $pauseTime = strtotime($attendance['job_pause_time']);
        $totalPause += (time() - $pauseTime);
    }
    $totalSeconds = max(0, $totalSeconds - $totalPause);
}

$isCompleted = $totalSeconds >= $targetSeconds;
$progressPercent = min(100, round(($totalSeconds / $targetSeconds) * 100));

// ============================================================
// BREAK TIMERS (All displayed in India time as before)
// ============================================================
$breakTimerActive = false;
$breakTimerType = '';
$breakTimerRemaining = 0;
$breakTimerTotal = 0;
$breakComment = '';
$breakTimerElapsed = 0;

// Lunch break
$lunchStarted = !empty($attendance['lunch_start']);
$lunchEnded = !empty($attendance['lunch_end']);
$lunchInProgress = $lunchStarted && !$lunchEnded;
$lunchRemaining = 0;
if ($lunchInProgress && !$isLoggedOut) {
    $start = strtotime($attendance['lunch_start']);
    $elapsed = time() - $start;
    $lunchRemaining = max(0, 1800 - $elapsed);
}

// Snack break
$snackStarted = !empty($attendance['snack_start']);
$snackEnded = !empty($attendance['snack_end']);
$snackInProgress = $snackStarted && !$snackEnded;
$snackRemainingTime = 0;
if ($snackInProgress && !$isLoggedOut) {
    $start = strtotime($attendance['snack_start']);
    $elapsed = time() - $start;
    $snackRemainingTime = max(0, 900 - $elapsed);
}

// Bio break
$bioStarted = !empty($attendance['bio_start']);
$bioEnded = !empty($attendance['bio_end']);
$bioInProgress = $bioStarted && !$bioEnded;
$bioElapsedTime = 0;
if ($bioInProgress && !$isLoggedOut) {
    $start = strtotime($attendance['bio_start']);
    $bioElapsedTime = time() - $start;
}


// Log the login
executeQuery("INSERT INTO agent_login_logs (agent_id, ip_address, user_agent) VALUES (?, ?, ?)", 
    [$agentId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);



// Active break timer
if (!empty($attendance['break_timer_start']) && empty($attendance['break_timer_end']) && !$isLoggedOut) {
    $breakTimerActive = true;
    $breakTimerType = $attendance['break_timer_type'] ?? 'lunch';
    $startTime = strtotime($attendance['break_timer_start']);
    $breakTimerElapsed = time() - $startTime;
    $breakComment = $attendance['break_comment'] ?? '';
    
    $breakDurations = ['lunch' => 30*60, 'snack' => 15*60, 'bio' => 0];
    $breakTimerTotal = $breakDurations[$breakTimerType] ?? 0;
    
    if ($breakTimerType === 'bio') {
        $breakTimerRemaining = 0;
    } else {
        $breakTimerRemaining = max(0, $breakTimerTotal - $breakTimerElapsed);
    }
}

$snackCount = (int)($attendance['snack_count'] ?? 0);
$maxSnacks = 2;
$snackRemaining = max(0, $maxSnacks - $snackCount);
$canTakeSnack = $snackRemaining > 0 && (!$snackStarted || ($snackStarted && $snackEnded));

// Status
$breakStatus = 'Not Started';
if ($isLoggedOut) {
    $breakStatus = 'Logged Out';
} elseif ($hasNotStarted) {
    $breakStatus = 'Not Started';
} elseif ($breakTimerActive) {
    $breakStatus = 'On Break';
} elseif ($lunchStarted && $lunchEnded) {
    $breakStatus = 'Lunch Done';
} elseif ($bioStarted && $bioEnded) {
    $breakStatus = 'Bio Done';
} elseif ($snackStarted && $snackEnded) {
    $breakStatus = 'Snack Done';
} elseif ($isPaused) {
    $breakStatus = 'Paused';
} elseif ($isWorking) {
    $breakStatus = 'Working';
}

// For display in the timezone bar - show which date the system is using
$usaDateForDisplay = (new DateTime('now', new DateTimeZone('America/New_York')))->format('d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>

    
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

        .header {
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 12px 30px;
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
            font-size: 13px;
        }
        .header .user-info .name { font-weight: 500; }
        .header .logout {
            color: #f2a3a0;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,.08);
            transition: background .25s var(--ease), transform .25s var(--ease);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 12px;
        }
        .header .logout:hover {
            background: rgba(240,90,80,.15);
            transform: translateY(-1px);
        }

        .content { max-width: 1200px; margin: 0 auto; padding: 20px 30px 40px; }

        /* ============ TIME ZONE BAR ============ */
        .timezone-bar {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 12px 20px;
            margin-bottom: 20px;
            border: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
        }
        .timezone-bar .tz-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .timezone-bar .tz-row .tz-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .timezone-bar .tz-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
        }
        .timezone-bar .tz-item .flag { font-size: 16px; }
        .timezone-bar .tz-item .time {
            font-weight: 700;
            color: var(--navy-950);
            font-variant-numeric: tabular-nums;
            min-width: 65px;
        }
        .timezone-bar .tz-item .label { color: var(--ink-soft); font-weight: 500; }
        .timezone-bar .tz-item .shift-badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .timezone-bar .tz-item .shift-badge.day {
            background: rgba(255,193,7,.15);
            color: #ffc107;
        }
        .timezone-bar .tz-item .shift-badge.night {
            background: rgba(23,162,184,.15);
            color: #17a2b8;
        }
        .timezone-bar .quick-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .timezone-bar .quick-actions a {
            padding: 4px 12px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: transform .2s var(--ease);
        }
        .timezone-bar .quick-actions a:hover { transform: translateY(-2px); }
        .timezone-bar .quick-actions .report-link {
            background: var(--cream);
            color: var(--navy-800);
            border: 1px solid var(--line);
        }
        .timezone-bar .quick-actions .id-link {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
        }
        /* Day indicator badge */
        .day-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(40,167,69,.15);
            color: #28a745;
        }

        /* ============ PROFILE CARD ============ */
        .profile-card {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
        }
        .profile-card .photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gold-500);
            flex-shrink: 0;
        }
        .profile-card .photo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #aaa;
            border: 2px dashed var(--line);
            flex-shrink: 0;
        }
        .profile-card .info h2 {
            font-family: 'Fraunces', serif;
            font-size: 22px;
            color: var(--navy-950);
        }
        .profile-card .info .details {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 4px;
            color: var(--ink-soft);
            font-size: 13px;
        }
        .profile-card .info .details span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .profile-card .info .details i { color: var(--gold-500); font-size: 12px; }
        .profile-card .shift-info {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .profile-card .shift-info .shift-badge {
            padding: 3px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .profile-card .shift-info .shift-badge.day {
            background: rgba(255,193,7,.15);
            color: #ffc107;
        }
        .profile-card .shift-info .shift-badge.night {
            background: rgba(23,162,184,.15);
            color: #17a2b8;
        }
        .profile-card .shift-info .shift-badge.office {
            background: rgba(40,167,69,.15);
            color: #28a745;
        }
        .profile-card .shift-info .shift-badge.home {
            background: rgba(201,150,47,.15);
            color: #c9962f;
        }
        .profile-card .status-badge {
            margin-left: auto;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .profile-card .status-badge.working { background: var(--success-bg); color: var(--success-ink); }
        .profile-card .status-badge.break { background: var(--warn-bg); color: var(--warn-ink); animation: pulse 2s infinite; }
        .profile-card .status-badge.logged-out { background: var(--danger-bg); color: var(--danger-ink); }
        .profile-card .status-badge.not-started { background: var(--cream); color: var(--ink-soft); }
        .profile-card .status-badge.paused { background: var(--info-bg); color: var(--info-ink); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

        /* ============ START JOB ============ */
        .start-job-card {
            background: linear-gradient(135deg, var(--success-bg), var(--success-ink));
            border-radius: var(--radius);
            padding: 25px;
            margin-bottom: 20px;
            text-align: center;
            border: 2px solid var(--success-ink);
        }
        .start-job-card h3 {
            font-family: 'Fraunces', serif;
            color: var(--success-ink);
            font-size: 20px;
            margin-bottom: 4px;
        }
        .start-job-card p {
            color: var(--success-ink);
            font-size: 13px;
            opacity: 0.8;
            margin-bottom: 14px;
        }
        .btn-start-job {
            padding: 12px 35px;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--success-ink);
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-start-job:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(30, 122, 65, .3);
        }
        .btn-start-job:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ============ HOURS CARD ============ */
        .hours-card {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            text-align: center;
        }
        .hours-card .big-time {
            font-family: 'Fraunces', serif;
            font-size: 48px;
            font-weight: 700;
            color: var(--navy-950);
            letter-spacing: 2px;
        }
        .hours-card .big-time .seconds {
            font-size: 28px;
            color: var(--gold-500);
        }
        .hours-card .progress-container {
            margin-top: 12px;
            background: var(--cream);
            border-radius: 50px;
            height: 10px;
            overflow: hidden;
            border: 1px solid var(--line);
        }
        .hours-card .progress-container .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--gold-300), var(--gold-500));
            border-radius: 50px;
            transition: width 0.5s ease;
        }
        .hours-card .progress-label {
            display: flex;
            justify-content: space-between;
            margin-top: 6px;
            font-size: 12px;
            color: var(--ink-soft);
        }
        .hours-card .progress-label .completed {
            color: var(--success-ink);
            font-weight: 600;
        }

        /* ============ ATTENDANCE GRID ============ */
        .attendance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .attendance-card {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
        }
        .attendance-card .card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            color: var(--ink-soft);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .attendance-card .card-header i { color: var(--gold-500); font-size: 14px; }
        .attendance-card .time-display {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }
        .attendance-card .time-item {
            background: var(--cream);
            padding: 8px 10px;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--line);
        }
        .attendance-card .time-item .label {
            font-size: 9px;
            color: var(--ink-soft);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .attendance-card .time-item .value {
            font-size: 14px;
            font-weight: 700;
            color: var(--navy-950);
            margin-top: 2px;
            font-family: 'Fraunces', serif;
        }
        .attendance-card .time-item .value.empty { color: #ccc; font-weight: 400; }
        .attendance-card .time-item .value.break-active { color: var(--warn-ink); }
        .break-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .break-timer-display {
            text-align: center;
            padding: 12px;
            background: var(--warn-bg);
            border-radius: var(--radius-sm);
            margin-top: 10px;
            border: 2px solid var(--warn-ink);
        }
        .break-timer-display .timer-text {
            font-size: 28px;
            font-weight: 700;
            font-family: 'Fraunces', serif;
            color: var(--warn-ink);
        }
        .break-timer-display .timer-label {
            font-size: 12px;
            color: var(--warn-ink);
            font-weight: 500;
            margin-bottom: 3px;
        }
        .break-timer-display .timer-sub {
            font-size: 11px;
            color: var(--ink-soft);
            margin-top: 3px;
        }

        /* ============ BUTTONS ============ */
        .btn-sm {
            padding: 5px 12px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-sm:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .btn-success-sm { background: var(--success-ink); color: white; }
        .btn-success-sm:hover { background: #156b34; }
        .btn-warning-sm { background: var(--warn-ink); color: white; }
        .btn-warning-sm:hover { background: #7a550d; }
        .btn-danger-sm { background: var(--danger-ink); color: white; }
        .btn-danger-sm:hover { background: #992b26; }
        .btn-secondary-sm { background: var(--cream); color: var(--ink-soft); border: 1px solid var(--line); }
        .btn-secondary-sm:hover { background: var(--line); }
        .btn-info-sm { background: var(--info-ink); color: white; }
        .btn-info-sm:hover { background: #1a6b7a; }
        .btn-pause-sm { background: var(--warn-ink); color: white; }
        .btn-pause-sm:hover { background: #7a550d; }
        .btn-resume-sm { background: var(--info-ink); color: white; }
        .btn-resume-sm:hover { background: #1a6b7a; }

        /* ============ QUICK LINKS ============ */
        .quick-links {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 20px;
        }
        .quick-link {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            text-decoration: none;
            color: var(--ink);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
        }
        .quick-link:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .quick-link .icon {
            font-size: 24px;
            color: var(--gold-500);
            display: block;
            margin-bottom: 6px;
        }
        .quick-link .label {
            font-size: 12px;
            font-weight: 600;
            display: block;
        }
        .quick-link .sub {
            font-size: 10px;
            color: var(--ink-soft);
        }

        /* ============ SALES CARD ============ */
        .sales-card {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            border-radius: var(--radius);
            padding: 18px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-md);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
            text-decoration: none;
            color: var(--navy-950);
        }
        .sales-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        .sales-card .sales-info h3 {
            font-family: 'Fraunces', serif;
            font-size: 18px;
            font-weight: 600;
        }
        .sales-card .sales-info p {
            font-size: 13px;
            opacity: 0.8;
            margin-top: 2px;
        }
        .sales-card .sales-arrow {
            font-size: 22px;
            transition: transform .3s var(--ease);
        }
        .sales-card:hover .sales-arrow { transform: translateX(6px); }

        /* ============ CALENDAR ============ */
        .calendar-section {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            margin-bottom: 20px;
        }
        .calendar-section h3 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .calendar-section h3 i { color: var(--gold-500); }
        .week-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .week-day {
            text-align: center;
            padding: 8px 4px;
            border-radius: var(--radius-sm);
            background: var(--cream);
            border: 1px solid var(--line);
        }
        .week-day .day-name {
            font-size: 10px;
            font-weight: 600;
            color: var(--ink-soft);
            text-transform: uppercase;
        }
        .week-day .day-date {
            font-size: 14px;
            font-weight: 700;
            color: var(--navy-950);
            font-family: 'Fraunces', serif;
        }
        .week-day .day-hours {
            font-size: 10px;
            color: var(--ink-soft);
            margin-top: 2px;
        }
        .week-day.today {
            background: var(--gold-300);
            border-color: var(--gold-500);
        }
        .week-day.today .day-name { color: var(--navy-950); }
        .week-day.today .day-date { color: var(--navy-950); }
        .week-day.weekend { opacity: 0.5; }

        /* ============ SNACK COUNTER ============ */
        .snack-counter {
            display: inline-block;
            background: var(--warn-bg);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 600;
            color: var(--warn-ink);
            margin-left: 4px;
        }
        .snack-counter.used { background: var(--danger-bg); color: var(--danger-ink); }

        /* ============ MODAL ============ */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7);
            z-index: 9999;
            align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 24px;
            max-width: 420px;
            width: 92%;
            box-shadow: var(--shadow-lg);
        }
        .modal-content h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 18px;
            margin-bottom: 4px;
        }
        .modal-content .subtitle {
            color: var(--ink-soft);
            font-size: 13px;
            margin-bottom: 14px;
        }
        .modal-content textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            resize: vertical;
            min-height: 70px;
            background: var(--cream);
        }
        .modal-content textarea:focus {
            border-color: var(--gold-500);
            outline: none;
        }
        .modal-content .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .modal-content .btn-group .btn {
            padding: 8px 22px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s, box-shadow .2s;
        }
        .modal-content .btn-group .btn:hover { transform: translateY(-2px); }
        .btn-primary-modal {
            background: var(--navy-800);
            color: white;
        }
        .btn-secondary-modal {
            background: var(--cream);
            color: var(--ink-soft);
            border: 2px solid var(--line);
        }

        /* ============ POPUP ============ */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7);
            z-index: 10000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .popup-overlay.show { display: flex; }
        .popup-content {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 30px;
            max-width: 420px;
            width: 92%;
            text-align: center;
            box-shadow: var(--shadow-lg);
            animation: popIn 0.5s var(--ease);
        }
        @keyframes popIn {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .popup-content .popup-icon { font-size: 56px; margin-bottom: 10px; }
        .popup-content .popup-icon.warning { color: var(--warn-ink); }
        .popup-content .popup-icon.success { color: var(--success-ink); }
        .popup-content h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 20px;
            margin-bottom: 4px;
        }
        .popup-content p {
            color: var(--ink-soft);
            font-size: 14px;
            margin-bottom: 16px;
        }
        .popup-content .btn-popup {
            padding: 8px 24px;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--gold-500);
            color: var(--navy-950);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s var(--ease);
        }
        .popup-content .btn-popup:hover { transform: scale(1.05); }
        
        .quick-links {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
        
        
        @media (max-width: 900px) {
    .quick-links { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 600px) {
    .quick-links { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 400px) {
    .quick-links { grid-template-columns: 1fr; }
}
        
        
        
        
        
        
        
        
        
        
        
        
        
        

        @media (max-width: 900px) {
            .attendance-grid { grid-template-columns: 1fr; }
            .profile-card .status-badge { margin-left: 0; }
            .profile-card { flex-direction: column; text-align: center; }
            .profile-card .info .details { justify-content: center; }
            .profile-card .shift-info { justify-content: center; }
            .hours-card .big-time { font-size: 36px; }
            .quick-links { grid-template-columns: 1fr 1fr; }
            .timezone-bar .tz-row { flex-direction: column; align-items: stretch; }
            .timezone-bar .tz-group { justify-content: center; }
            .timezone-bar .quick-actions { justify-content: center; }
        }

        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: stretch; text-align: center; padding: 12px 16px; }
            .header .brand { justify-content: center; }
            .header .user-info { justify-content: center; flex-wrap: wrap; }
            .content { padding: 12px 14px 30px; }
            .attendance-card .time-display { grid-template-columns: 1fr 1fr; }
            .break-buttons { justify-content: center; }
            .week-grid { grid-template-columns: repeat(7, 1fr); gap: 4px; }
            .week-day { padding: 4px 2px; }
            .week-day .day-date { font-size: 12px; }
            .week-day .day-hours { font-size: 9px; }
            .sales-card { flex-direction: column; text-align: center; }
            .sales-card .sales-arrow { display: none; }
            .hours-card .big-time { font-size: 28px; }
            .hours-card .big-time .seconds { font-size: 18px; }
            .popup-content { padding: 20px; }
            .break-timer-display .timer-text { font-size: 22px; }
            .quick-links { grid-template-columns: 1fr; }
            .timezone-bar .tz-item { font-size: 10px; }
            .timezone-bar .tz-item .time { min-width: 55px; }
        }

        @media (max-width: 400px) {
            .attendance-card .time-display { grid-template-columns: 1fr 1fr; }
            .profile-card .photo { width: 65px; height: 65px; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }
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
            <span class="name">Welcome, <?php echo htmlspecialchars($name); ?></span>
            <a href="logout.php" class="logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </header>

    <div class="content">
        <!-- ===== TIME ZONE BAR ===== -->
        <div class="timezone-bar">
            <div class="tz-row">
                <div class="tz-group">
                    <div class="tz-item">
                        <span class="flag">🇮🇳</span>
                        <span class="label">India (IST):</span>
                        <span class="time" id="indiaTime"><?php echo $indiaTimeStr; ?></span>
                        <span style="font-size:10px;color:var(--ink-soft);"><?php echo $indiaDateStr; ?></span>
                    </div>
                    <div style="width:1px;height:20px;background:var(--line);"></div>
                    <?php foreach ($usaTimes as $label => $time): ?>
                    <div class="tz-item">
                        <span class="flag">🇺🇸</span>
                        <span class="label"><?php echo $label; ?>:</span>
                        <span class="time" id="<?php echo strtolower($label); ?>Time"><?php echo formatTime($time); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="width:1px;height:20px;background:var(--line);"></div>
                    <div class="tz-item">
                        <span class="shift-badge <?php echo $shiftType; ?>">
                            <i class="fas <?php echo $shiftType === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                            <?php echo ucfirst($shiftType); ?> Shift
                        </span>
                        <span class="shift-badge <?php echo $workLocation; ?>">
                            <i class="fas <?php echo $workLocation === 'office' ? 'fa-building' : 'fa-home'; ?>"></i>
                            <?php echo ucfirst($workLocation); ?>
                        </span>
                    </div>
                    <div style="width:1px;height:20px;background:var(--line);"></div>
                    <div class="tz-item">
                        <span class="day-indicator">
                            <i class="fas fa-calendar-day"></i> US Day: <?php echo $usaDateForDisplay; ?>
                        </span>
                    </div>
                </div>
                <div class="quick-actions">
                    <a href="daily-report.php" class="report-link">
                        <i class="fas fa-file-alt"></i> Report
                    </a>
<a href="joining-letter.php"><i class="fas fa-file-signature"></i> Joining-Letter</a>
                    <a href="identity-card.php" class="id-link">
                        <i class="fas fa-id-card"></i> ID Card
                    </a>
                </div>
            </div>
        </div>

        <!-- ===== PROFILE CARD ===== -->
        <div class="profile-card">
            <?php if ($photoExists && !empty($photoUrl)): ?>
                <img src="<?php echo $photoUrl; ?>" alt="Photo" class="photo"
                     onerror="this.style.display='none'; this.parentElement.querySelector('.photo-placeholder').style.display='flex';">
                <div class="photo-placeholder" style="display:none;">👤</div>
            <?php else: ?>
                <div class="photo-placeholder">👤</div>
            <?php endif; ?>

            <div class="info">
                <h2><?php echo htmlspecialchars($name); ?></h2>
                <div class="details">
                    <span><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($agentId); ?></span>
                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></span>
                </div>
                <div class="shift-info">
                    <span class="shift-badge <?php echo $shiftType; ?>">
                        <i class="fas <?php echo $shiftType === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                        <?php echo ucfirst($shiftType); ?> Shift
                    </span>
                    <span class="shift-badge <?php echo $workLocation; ?>">
                        <i class="fas <?php echo $workLocation === 'office' ? 'fa-building' : 'fa-home'; ?>"></i>
                        <?php echo ucfirst($workLocation); ?>
                    </span>
                </div>
            </div>

            <span class="status-badge <?php
                if ($isLoggedOut) echo 'logged-out';
                elseif ($hasNotStarted) echo 'not-started';
                elseif ($breakTimerActive) echo 'break';
                elseif ($isPaused) echo 'paused';
                else echo 'working';
            ?>">
                <i class="fas fa-circle" style="font-size:8px;"></i>
                <?php echo $breakStatus; ?>
            </span>
        </div>

        <?php if ($hasNotStarted && !$isLoggedOut): ?>
        <!-- ===== START JOB ===== -->
        <div class="start-job-card">
            <h3><i class="fas fa-play-circle"></i> Start Your Day</h3>
            <p>Click the button below to start your work day. Your login time will be recorded in India time (IST) but the day is tracked per US date (<?php echo $usaDateForDisplay; ?>).</p>
            <button class="btn-start-job" onclick="startJob()">
                <i class="fas fa-play"></i> Start Job
            </button>
        </div>
        <?php endif; ?>

        <?php if (!$hasNotStarted && !$isLoggedOut): ?>
        <!-- ===== WORKING HOURS ===== -->
        <div class="hours-card">
            <div class="big-time" id="workingTimer">
                <?php 
                    $h = (int)floor($totalSeconds / 3600);
                    $m = (int)floor(($totalSeconds % 3600) / 60);
                    $s = (int)($totalSeconds % 60);
                ?>
                <?php echo sprintf("%02d", $h); ?>:<span class="seconds" id="timerMinutes"><?php echo sprintf("%02d", $m); ?></span>:<span class="seconds" id="timerSeconds"><?php echo sprintf("%02d", $s); ?></span>
            </div>
            <div style="font-size:13px; color:var(--ink-soft); margin-top:4px;">
                <?php if ($isPaused): ?>
                    <strong style="color:var(--info-ink);">⏸️ Paused</strong>
                <?php elseif ($isCompleted): ?>
                    🎉 <strong style="color:var(--success-ink);">9 Hours Completed!</strong>
                <?php else: ?>
                    Target: 9 Hours (<?php echo $progressPercent; ?>% completed)
                <?php endif; ?>
            </div>
            <div class="progress-container">
                <div class="progress-bar" id="progressBar" style="width: <?php echo $progressPercent; ?>%;"></div>
            </div>
            <div class="progress-label">
                <span>0h</span>
                <span class="<?php echo $isCompleted ? 'completed' : ''; ?>">
                    <?php echo $isCompleted ? '✅ Completed' : $progressPercent . '%'; ?>
                </span>
                <span>9h</span>
            </div>
            <?php if ($isCompleted): ?>
            <div style="margin-top:8px; padding:6px 14px; background:var(--success-bg); border-radius:var(--radius-sm); display:inline-block;">
                <span style="color:var(--success-ink); font-weight:600; font-size:13px;">
                    <i class="fas fa-check-circle"></i> You have completed your 9-hour target!
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===== ATTENDANCE ===== -->
        <div class="attendance-grid">
            <div class="attendance-card">
                <div class="card-header">
                    <i class="fas fa-clock"></i> Today's Hours (IST)
                </div>
                <div class="time-display">
                    <div class="time-item">
                        <div class="label">Start</div>
                        <div class="value">
                            <?php echo !empty($attendance['job_start_time']) ? date('h:i A', strtotime($attendance['job_start_time'])) : '--:--'; ?>
                        </div>
                    </div>
                    <div class="time-item">
                        <div class="label">End</div>
                        <div class="value <?php echo empty($attendance['job_end_time']) ? 'empty' : ''; ?>">
                            <?php echo !empty($attendance['job_end_time']) ? date('h:i A', strtotime($attendance['job_end_time'])) : '--:--'; ?>
                        </div>
                    </div>
                    <div class="time-item">
                        <div class="label">Total</div>
                        <div class="value" id="totalHoursDisplay">
                            <?php 
                                $h = (int)floor($totalSeconds / 3600);
                                $m = (int)floor(($totalSeconds % 3600) / 60);
                                echo sprintf("%02d:%02d", $h, $m);
                            ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px; display:flex; gap:6px; flex-wrap:wrap;">
                    <?php if ($isPaused): ?>
                        <button class="btn-sm btn-resume-sm" onclick="resumeJob()">
                            <i class="fas fa-play"></i> Resume Job
                        </button>
                    <?php else: ?>
                        <button class="btn-sm btn-pause-sm" onclick="pauseJob()">
                            <i class="fas fa-pause"></i> Pause Job
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="attendance-card">
                <div class="card-header">
                    <i class="fas fa-coffee"></i> Breaks (IST)
                </div>

                <?php if ($breakTimerActive): ?>
                <div class="break-timer-display">
                    <div class="timer-label">
                        <?php echo ucfirst($breakTimerType); ?> Break in progress
                        <?php if ($breakTimerType === 'bio'): ?>
                            <span style="color:var(--info-ink); font-size:11px;">(No limit)</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($breakTimerType === 'bio'): ?>
                        <div class="timer-text" id="breakTimerDisplay">
                            <?php 
                                $mins = (int)floor($breakTimerElapsed / 60);
                                $secs = (int)($breakTimerElapsed % 60);
                                echo sprintf("%02d:%02d", $mins, $secs);
                            ?>
                        </div>
                        <div class="timer-sub">⏱️ Elapsed since break started</div>
                    <?php else: ?>
                        <div class="timer-text" id="breakTimerDisplay">
                            <?php 
                                $mins = (int)floor($breakTimerRemaining / 60);
                                $secs = (int)($breakTimerRemaining % 60);
                                echo sprintf("%02d:%02d", $mins, $secs);
                            ?>
                        </div>
                        <div class="timer-sub">⏳ Remaining time</div>
                    <?php endif; ?>
                    <?php if (!empty($breakComment)): ?>
                    <div style="font-size:11px; color:var(--ink-soft); margin-top:4px;">
                        <i class="fas fa-comment"></i> <?php echo htmlspecialchars($breakComment); ?>
                    </div>
                    <?php endif; ?>
                    <button class="btn-sm btn-success-sm" style="margin-top:8px;" onclick="endBreak('<?php echo $breakTimerType; ?>')">
                        <i class="fas fa-check"></i> End Break
                    </button>
                </div>
                <?php endif; ?>

                <!-- Break Status Display -->
                <div class="time-display" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div class="time-item <?php echo $lunchInProgress ? 'break-active' : ''; ?>">
                        <div class="label">Lunch (30m)</div>
                        <div class="value <?php echo !$lunchStarted ? 'empty' : ''; ?>" id="lunchTimerDisplay">
                            <?php
                                if ($lunchInProgress) {
                                    $mins = (int)floor($lunchRemaining / 60);
                                    $secs = (int)($lunchRemaining % 60);
                                    echo '⏳ ' . sprintf("%02d:%02d", $mins, $secs);
                                } elseif ($lunchStarted && $lunchEnded) {
                                    echo '✅ Done';
                                } else {
                                    echo '--:--';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="time-item <?php echo $snackInProgress ? 'break-active' : ''; ?>">
                        <div class="label">Snack (15m)</div>
                        <div class="value <?php echo !$snackStarted ? 'empty' : ''; ?>" id="snackTimerDisplay">
                            <?php
                                if ($snackInProgress) {
                                    $mins = (int)floor($snackRemainingTime / 60);
                                    $secs = (int)($snackRemainingTime % 60);
                                    echo '⏳ ' . sprintf("%02d:%02d", $mins, $secs);
                                } elseif ($snackStarted && $snackEnded) {
                                    echo '✅ Done';
                                } else {
                                    echo '--:--';
                                }
                            ?>
                            <span class="snack-counter <?php echo $snackCount >= $maxSnacks ? 'used' : ''; ?>">
                                <?php echo $snackCount; ?>/<?php echo $maxSnacks; ?>
                            </span>
                        </div>
                    </div>
                    <div class="time-item <?php echo $bioInProgress ? 'break-active' : ''; ?>">
                        <div class="label">Bio (No Limit)</div>
                        <div class="value <?php echo !$bioStarted ? 'empty' : ''; ?>" id="bioTimerDisplay">
                            <?php
                                if ($bioInProgress) {
                                    $mins = (int)floor($bioElapsedTime / 60);
                                    $secs = (int)($bioElapsedTime % 60);
                                    echo '⏳ ' . sprintf("%02d:%02d", $mins, $secs);
                                } elseif ($bioStarted && $bioEnded) {
                                    echo '✅ Done';
                                } else {
                                    echo '--:--';
                                }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Break Buttons -->
                <?php if (!$isLoggedOut && !$breakTimerActive): ?>
                <div class="break-buttons">
                    <?php if (!$lunchStarted || ($lunchStarted && $lunchEnded)): ?>
                        <button class="btn-sm btn-warning-sm" onclick="openBreakModal('lunch')">
                            <i class="fas fa-utensils"></i> Lunch
                        </button>
                    <?php elseif ($lunchInProgress): ?>
                        <button class="btn-sm btn-success-sm" onclick="endBreak('lunch')">
                            <i class="fas fa-check"></i> End Lunch
                        </button>
                    <?php endif; ?>

                    <?php if ($canTakeSnack && !$snackInProgress): ?>
                        <button class="btn-sm btn-info-sm" onclick="openBreakModal('snack')">
                            <i class="fas fa-cookie"></i> Snack
                            <span class="snack-counter"><?php echo $snackRemaining; ?> left</span>
                        </button>
                    <?php elseif ($snackInProgress): ?>
                        <button class="btn-sm btn-success-sm" onclick="endBreak('snack')">
                            <i class="fas fa-check"></i> End Snack
                        </button>
                    <?php elseif ($snackRemaining <= 0): ?>
                        <button class="btn-sm btn-secondary-sm" disabled>
                            <i class="fas fa-cookie"></i> Snack Used All
                        </button>
                    <?php endif; ?>

                    <?php if (!$bioStarted || ($bioStarted && $bioEnded)): ?>
                        <button class="btn-sm btn-secondary-sm" onclick="openBreakModal('bio')">
                            <i class="fas fa-restroom"></i> Bio
                        </button>
                    <?php elseif ($bioInProgress): ?>
                        <button class="btn-sm btn-success-sm" onclick="endBreak('bio')">
                            <i class="fas fa-check"></i> End Bio
                        </button>
                    <?php endif; ?>

                    <button class="btn-sm btn-danger-sm" onclick="logoutDay()">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
                <?php elseif ($breakTimerActive): ?>
                <div class="break-buttons">
                    <span style="color:var(--warn-ink); font-size:12px;">⏳ Break in progress...</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($isLoggedOut): ?>
        <!-- ===== LOGGED OUT - WITH RESUME OPTION ===== -->
        <div class="hours-card">
            <div style="padding:16px;">
                <i class="fas fa-check-circle" style="font-size:40px; color:var(--success-ink);"></i>
                <h3 style="font-family:'Fraunces',serif; color:var(--navy-950); margin-top:8px;">Logged Out for Today</h3>
                <p style="color:var(--ink-soft); font-size:14px;">You have completed your work for today (US Date: <?php echo $usaDateForDisplay; ?>).</p>
                <p style="color:var(--ink-soft); font-size:13px; margin-top:6px;">
                    Total hours worked: <strong><?php 
                        $h = (int)floor($totalSeconds / 3600);
                        $m = (int)floor(($totalSeconds % 3600) / 60);
                        echo sprintf("%02d:%02d", $h, $m);
                    ?></strong>
                </p>
                
                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                    <button class="btn-sm btn-resume-sm" onclick="resumeWork()" style="padding:8px 20px; font-size:13px;">
                        <i class="fas fa-play"></i> Resume Work
                    </button>
                    <button class="btn-sm btn-warning-sm" onclick="restartDay()" style="padding:8px 20px; font-size:13px;">
                        <i class="fas fa-undo"></i> Restart Day
                    </button>
                </div>
                <p style="color:var(--ink-soft); font-size:12px; margin-top:10px;">
                    <i class="fas fa-info-circle"></i> Click "Resume Work" to continue working today, or "Restart Day" to start fresh.
                </p>
            </div>
        </div>
        <?php endif; ?>

     <!-- ===== QUICK LINKS ===== -->
<div class="quick-links">
    <a href="https://bettergrow.callhub.ai/agc/vicidial.php" target="_blank" class="quick-link">
        <span class="icon"><i class="fas fa-phone"></i></span>
        <span class="label">📞 Dialer</span>
        <span class="sub">Click to call</span>
    </a>
    <a href="http://bettergrowthsolutions.com/" target="_blank" class="quick-link">
        <span class="icon"><i class="fas fa-globe"></i></span>
        <span class="label">🌐 Website</span>
        <span class="sub">bettergrowthsolutions.in</span>
    </a>
    <a href="https://meet.google.com/wkh-hipk-ott" target="_blank" class="quick-link">
        <span class="icon"><i class="fas fa-video"></i></span>
        <span class="label">📹 Meeting</span>
        <span class="sub">Google Meet</span>
    </a>
    <a href="https://docs.google.com/spreadsheets/d/1P7xLvosLH2BiaOcZ_kkTnk4muW-LthLyIMj77Kjs0EU/edit?pli=1&gid=0#gid=0" target="_blank" class="quick-link">
        <span class="icon"><i class="fas fa-file-excel"></i></span>
        <span class="label">📊 Offer Sheet</span>
        <span class="sub">Google Sheets</span>
    </a>
    <a href="https://docs.google.com/spreadsheets/d/1P7xLvosLH2BiaOcZ_kkTnk4muW-LthLyIMj77Kjs0EU/edit?pli=1&gid=0#gid=0" target="_blank" class="quick-link">
        <span class="icon"><i class="fas fa-dollar-sign"></i></span>
        <span class="label">💰 Sale Sheet</span>
        <span class="sub">Google Sheets</span>
    </a>
</div>
        
        <!-- ===== SALES CARD ===== -->
        <a href="/raj/agent/sales.php" class="sales-card" >
            <div class="sales-info">
                <h3><i class="fas fa-chart-line"></i> Sales Dashboard</h3>
                <p>View your sales performance, leads, and revenue analytics</p>
            </div>
            <div class="sales-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>
        </a>

        <!-- ===== CALENDAR (Shows dates as stored, which are US shift dates) ===== -->
        <div class="calendar-section">
            <h3><i class="fas fa-calendar-alt"></i> Weekly Overview</h3>
            <div class="week-grid">
                <?php
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                for ($i = 0; $i < 7; $i++):
                    $date = date('Y-m-d', strtotime($weekStart . ' + ' . $i . ' days'));
                    $dayName = date('D', strtotime($date));
                    $dayNum = date('d', strtotime($date));
                    $isToday = ($date == $today);
                    $isWeekend = (date('N', strtotime($date)) >= 6);
                    
                    $dayResult = executeQuery("SELECT job_start_time, job_end_time FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $date]);
                    $dayHours = '--';
                    if ($dayResult && $dayResult->num_rows > 0) {
                        $dayAtt = $dayResult->fetch_assoc();
                        if (!empty($dayAtt['job_start_time']) && !empty($dayAtt['job_end_time'])) {
                            $login = strtotime($dayAtt['job_start_time']);
                            $logout = strtotime($dayAtt['job_end_time']);
                            $diff = $logout - $login;
                            $h = (int)floor($diff / 3600);
                            $m = (int)floor(($diff % 3600) / 60);
                            $dayHours = sprintf("%02d:%02d", $h, $m);
                        } elseif (!empty($dayAtt['job_start_time'])) {
                            $dayHours = 'Active';
                        }
                    }
                ?>
                <div class="week-day <?php echo $isToday ? 'today' : ''; ?> <?php echo $isWeekend ? 'weekend' : ''; ?>">
                    <div class="day-name"><?php echo $dayName; ?></div>
                    <div class="day-date"><?php echo $dayNum; ?></div>
                    <div class="day-hours"><?php echo $dayHours; ?></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    
    <!-- ===== COPYRIGHT FOOTER ===== -->
<div class="copyright-footer" style="text-align:center; padding:15px 20px; margin-top:20px; border-top:1px solid #ecebe6; font-size:12px; color:#667085;">
    <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in" style="color:#c9962f; text-decoration:none; font-weight:600;">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
</div>

    <!-- ===== BREAK COMMENT MODAL ===== -->
    <div id="breakCommentModal" class="modal">
        <div class="modal-content">
            <h2><i class="fas fa-comment" style="color:var(--gold-500);"></i> Break Comment</h2>
            <p class="subtitle">Add a comment for your break (optional)</p>
            <textarea id="breakCommentInput" placeholder="Why are you taking a break? (optional)"></textarea>
            <div class="btn-group">
                <button onclick="submitBreakWithComment()" class="btn btn-primary-modal">
                    <i class="fas fa-check"></i> Start Break
                </button>
                <button onclick="closeBreakModal()" class="btn btn-secondary-modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- ===== POPUP ALERT ===== -->
    <div id="popupAlert" class="popup-overlay">
        <div class="popup-content">
            <div class="popup-icon" id="popupIcon">⏰</div>
            <h2 id="popupTitle">Break Time's Up!</h2>
            <p id="popupMessage">Your break has ended. Please resume your work.</p>
            <button class="btn-popup" onclick="closePopup()">OK, I'm Back</button>
        </div>
    </div>

    <script>
        // ============================================================
        // TIME ZONES - LIVE UPDATE (INDIA + USA)
        // ============================================================
        function updateAllTimeZones() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60 * 1000);
            
            // India (IST) - UTC +5:30
            const indiaOffset = 5.5 * 60 * 60 * 1000;
            const indiaTime = new Date(utc + indiaOffset);
            document.getElementById('indiaTime').textContent = formatTime(indiaTime);
            
            // USA Time Zones
            const usaOffsets = {
                est: -5 * 60 * 60 * 1000,
                cst: -6 * 60 * 60 * 1000,
                mst: -7 * 60 * 60 * 1000,
                pst: -8 * 60 * 60 * 1000
            };
            
            for (const [key, offset] of Object.entries(usaOffsets)) {
                const time = new Date(utc + offset);
                document.getElementById(key + 'Time').textContent = formatTime(time);
            }
        }

        function formatTime(date) {
            return date.toLocaleTimeString('en-US', { 
                hour12: true, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
        }

        setInterval(updateAllTimeZones, 1000);
        updateAllTimeZones();

        // ============================================================
        // API CALL FUNCTIONS
        // ============================================================
        function callApi(action, data = {}, callback) {
            const params = new URLSearchParams({ action: action, agent_id: '<?php echo $agentId; ?>', ...data });
            fetch('/raj/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (callback) callback(data);
                if (data.success) {
                    if (action !== 'check_break_ended' && action !== 'get_live_status' && action !== 'mark_resume_alert_shown' && action !== 'clear_break_ended_alert') {
                        location.reload();
                    }
                } else {
                    if (action !== 'check_break_ended' && action !== 'get_live_status') {
                        alert('Error: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (action !== 'check_break_ended' && action !== 'get_live_status') {
                    alert('Network error. Please try again.');
                }
            });
        }

        // ============================================================
        // START JOB WITH LOCATION CAPTURE
        // ============================================================
        function startJob() {
            if (!confirm('Start your work day now?')) return;
            
            const btn = document.querySelector('.btn-start-job');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting...';
            }

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        sendStartJobRequest(position.coords.latitude, position.coords.longitude, position.coords.accuracy, btn);
                    },
                    function(error) {
                        console.warn('Location not available:', error.message);
                        sendStartJobRequest('', '', '', btn);
                    },
                    { enableHighAccuracy: true, timeout: 5000 }
                );
            } else {
                sendStartJobRequest('', '', '', btn);
            }
        }

        function sendStartJobRequest(lat, lng, accuracy, btn) {
            const params = new URLSearchParams({
                action: 'start_job',
                agent_id: '<?php echo $agentId; ?>',
                latitude: lat || '',
                longitude: lng || '',
                accuracy: accuracy || ''
            });

            fetch('/raj/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (data.location_captured) {
                        console.log('📍 Location captured:', data.lat, data.lng);
                    }
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-play"></i> Start Job';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error. Please try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play"></i> Start Job';
                }
            });
        }

        // ============================================================
        // RESUME WORK AFTER LOGOUT
        // ============================================================
        function resumeWork() {
            if (!confirm('Resume your work for today?')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resuming...'; }
            
            fetch('/raj/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'resume_work',
                    agent_id: '<?php echo $agentId; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Resume Work'; }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error. Please try again.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Resume Work'; }
            });
        }

        // ============================================================
        // RESTART DAY
        // ============================================================
        function restartDay() {
            if (!confirm('This will clear all your work data for today. Are you sure?')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restarting...'; }
            
            fetch('/raj/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'restart_day',
                    agent_id: '<?php echo $agentId; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restart Day'; }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error. Please try again.');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-undo"></i> Restart Day'; }
            });
        }

        // ============================================================
        // PAUSE / RESUME JOB
        // ============================================================
        function pauseJob() {
            if (!confirm('Pause your job? Timer will stop.')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Pausing...'; }
            callApi('pause_job', {}, function(data) {
                if (data.success) location.reload();
                else if (btn) { btn.disabled = false; btn.textContent = 'Pause Job'; }
            });
        }

        function resumeJob() {
            if (!confirm('Resume your job?')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Resuming...'; }
            callApi('resume_job', {}, function(data) {
                if (data.success) location.reload();
                else if (btn) { btn.disabled = false; btn.textContent = 'Resume Job'; }
            });
        }

        // ============================================================
        // BREAK FUNCTIONS
        // ============================================================
        let pendingBreakType = '';

        function openBreakModal(type) {
            pendingBreakType = type;
            document.getElementById('breakCommentModal').classList.add('show');
            document.getElementById('breakCommentInput').value = '';
            setTimeout(function() { document.getElementById('breakCommentInput').focus(); }, 100);
        }

        function closeBreakModal() {
            document.getElementById('breakCommentModal').classList.remove('show');
        }

        function submitBreakWithComment() {
            const comment = document.getElementById('breakCommentInput').value.trim();
            const type = pendingBreakType;
            const labels = { 'lunch': 'Lunch Break (30 min)', 'bio': 'Bio Break (No Limit)', 'snack': 'Snack Break (15 min)' };
            if (!confirm('Start ' + labels[type] + '?')) return;
            const btn = document.querySelector('.modal .btn-primary-modal');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Starting...'; }
            callApi('start_break', { type: type, comment: comment }, function(data) {
                closeBreakModal();
                if (data.success) location.reload();
                else if (btn) { btn.disabled = false; btn.textContent = 'Start Break'; }
            });
        }

        function endBreak(type) {
            if (!confirm('End this break?')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Ending...'; }
            callApi('end_break', { type: type }, function(data) {
                if (data.success) {
                    showBreakEndPopup(type);
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else if (btn) { btn.disabled = false; btn.textContent = 'End Break'; }
            });
        }

        function logoutDay() {
            if (!confirm('Are you sure you want to logout for today?')) return;
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Logging out...'; }
            callApi('logout', {}, function(data) {
                if (data.success) location.reload();
                else if (btn) { btn.disabled = false; btn.textContent = 'Logout'; }
            });
        }

        // ============================================================
        // BREAK END POPUP & RESUME ALERT
        // ============================================================
        let breakEndPopupShown = false;
        let resumePopupShown = false;

        function showBreakEndPopup(type) {
            if (breakEndPopupShown) return;
            breakEndPopupShown = true;
            
            const popup = document.getElementById('popupAlert');
            const icon = document.getElementById('popupIcon');
            const title = document.getElementById('popupTitle');
            const message = document.getElementById('popupMessage');
            const btn = document.querySelector('.popup-content .btn-popup');
            
            icon.className = 'popup-icon warning';
            icon.textContent = '⏰';
            title.textContent = 'Break Ended!';
            message.textContent = 'Your ' + type + ' break has ended. Please resume your work.';
            btn.textContent = '🔄 Resume Work';
            btn.onclick = function() {
                closePopup();
                setTimeout(function() {
                    showResumePopup();
                }, 1000);
            };
            
            popup.classList.add('show');
            
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRlwAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoAAACBhYqNlZuVfWJnYmJhYGBgYWBhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');
                audio.volume = 0.5;
                audio.play().catch(function() {});
            } catch(e) {}
            
            setTimeout(function() {
                if (document.getElementById('popupAlert').classList.contains('show')) {
                    closePopup();
                    showResumePopup();
                }
            }, 8000);
        }

        function showResumePopup() {
            if (resumePopupShown) return;
            resumePopupShown = true;
            
            const popup = document.getElementById('popupAlert');
            const icon = document.getElementById('popupIcon');
            const title = document.getElementById('popupTitle');
            const message = document.getElementById('popupMessage');
            const btn = document.querySelector('.popup-content .btn-popup');
            
            icon.className = 'popup-icon warning';
            icon.textContent = '⏰';
            title.textContent = '⏰ Please Resume Your Job!';
            message.textContent = 'Your break has ended. Please click "Resume Work" to continue.';
            btn.textContent = '✅ Resume Work Now';
            btn.onclick = function() {
                closePopup();
                resumeJobDirectly();
            };
            
            callApi('mark_resume_alert_shown', {}, function() {});
            popup.classList.add('show');
            
            setTimeout(function() {
                if (document.getElementById('popupAlert').classList.contains('show')) {
                    closePopup();
                    resumeJobDirectly();
                }
            }, 5000);
        }

        function resumeJobDirectly() {
            const btn = document.querySelector('.btn-resume-sm');
            if (btn) {
                btn.click();
            } else {
                callApi('resume_job', {}, function(data) {
                    if (data.success) location.reload();
                });
            }
        }

        function closePopup() {
            document.getElementById('popupAlert').classList.remove('show');
        }

        // ============================================================
        // CHECK FOR BREAK ENDED ALERT (Runs every 3 seconds)
        // ============================================================
        <?php if (!$hasNotStarted && !$isLoggedOut): ?>
        let checkCount = 0;
        let breakEndCheckInterval = setInterval(function() {
            checkCount++;
            fetch('/raj/api/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'check_break_ended',
                    agent_id: '<?php echo $agentId; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.break_ended) {
                    const type = '<?php echo $breakTimerType; ?>';
                    showBreakEndPopup(type);
                    fetch('/raj/api/attendance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'clear_break_ended_alert',
                            agent_id: '<?php echo $agentId; ?>'
                        })
                    }).catch(err => console.error('Error clearing alert:', err));
                    clearInterval(breakEndCheckInterval);
                }
            })
            .catch(err => console.error('Check break ended error:', err));
        }, 3000);
        <?php endif; ?>

        // ============================================================
        // BREAK TIMER COUNTDOWN WITH POPUP
        // ============================================================

        <?php if ($breakTimerActive && $breakTimerType !== 'bio'): ?>
        let breakRemaining = <?php echo (int)$breakTimerRemaining; ?>;
        let breakType = '<?php echo $breakTimerType; ?>';
        let alertShown = <?php echo (int)($attendance['break_alert_shown'] ?? 0); ?>;
        const breakDisplay = document.getElementById('breakTimerDisplay');

        if (breakDisplay) {
            const timerInterval = setInterval(function() {
                if (breakRemaining > 0) {
                    breakRemaining--;
                    const mins = Math.floor(breakRemaining / 60);
                    const secs = breakRemaining % 60;
                    breakDisplay.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                }
                if (breakRemaining <= 0 && alertShown === 0) {
                    clearInterval(timerInterval);
                    alertShown = 1;
                    callApi('mark_alert_shown', {}, function() {});
                    showBreakEndPopup(breakType);
                }
            }, 1000);
        }
        <?php endif; ?>

        // ============================================================
        // POPUP CLOSE
        // ============================================================

        document.getElementById('breakCommentModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeBreakModal();
            }
        });

        document.getElementById('breakCommentInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitBreakWithComment();
            }
        });

        // ============================================================
        // REAL-TIME WORKING TIMER
        // ============================================================

        <?php if (!$hasNotStarted && !$isLoggedOut): ?>
        let totalSecs = <?php echo $totalSeconds; ?>;
        let startTimestamp = <?php echo time(); ?>;
        let isPaused = <?php echo $isPaused ? 'true' : 'false'; ?>;

        function updateWorkingTimer() {
            if (!isPaused) {
                const elapsed = Math.floor((Date.now() / 1000) - startTimestamp);
                const currentTotal = totalSecs + elapsed;
                const hours = Math.floor(currentTotal / 3600);
                const minutes = Math.floor((currentTotal % 3600) / 60);
                const seconds = Math.floor(currentTotal % 60);
                
                const timerMinutes = document.getElementById('timerMinutes');
                const timerSeconds = document.getElementById('timerSeconds');
                const totalDisplay = document.getElementById('totalHoursDisplay');
                const progressBar = document.getElementById('progressBar');
                
                if (timerMinutes) timerMinutes.textContent = String(minutes).padStart(2, '0');
                if (timerSeconds) timerSeconds.textContent = String(seconds).padStart(2, '0');
                if (totalDisplay) totalDisplay.textContent = String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
                
                const targetSecs = 32400;
                const pct = Math.min(100, Math.round((currentTotal / targetSecs) * 100));
                if (progressBar) progressBar.style.width = pct + '%';
            }
        }
        setInterval(updateWorkingTimer, 1000);
        <?php endif; ?>

        // ============================================================
        // BREAK LIVE TIMERS
        // ============================================================

        <?php if ($lunchInProgress): ?>
        let lunchRemaining = <?php echo (int)$lunchRemaining; ?>;
        const lunchDisplay = document.getElementById('lunchTimerDisplay');
        if (lunchDisplay) {
            setInterval(function() {
                if (lunchRemaining > 0) {
                    lunchRemaining--;
                    const mins = Math.floor(lunchRemaining / 60);
                    const secs = lunchRemaining % 60;
                    lunchDisplay.textContent = '⏳ ' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                }
            }, 1000);
        }
        <?php endif; ?>

        <?php if ($snackInProgress): ?>
        let snackRemaining = <?php echo (int)$snackRemainingTime; ?>;
        const snackDisplay = document.getElementById('snackTimerDisplay');
        if (snackDisplay) {
            setInterval(function() {
                if (snackRemaining > 0) {
                    snackRemaining--;
                    const mins = Math.floor(snackRemaining / 60);
                    const secs = snackRemaining % 60;
                    snackDisplay.textContent = '⏳ ' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                }
            }, 1000);
        }
        <?php endif; ?>

        <?php if ($bioInProgress): ?>
        let bioElapsed = <?php echo (int)$bioElapsedTime; ?>;
        const bioDisplay = document.getElementById('bioTimerDisplay');
        if (bioDisplay) {
            setInterval(function() {
                bioElapsed++;
                const mins = Math.floor(bioElapsed / 60);
                const secs = bioElapsed % 60;
                bioDisplay.textContent = '⏳ ' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
            }, 1000);
        }
        <?php endif; ?>

        // ============================================================
        // AUTO-REFRESH STATUS EVERY 30 SECONDS
        // ============================================================

        setInterval(function() {
            callApi('get_live_status', {}, function(data) {
                if (data.success && data.data) {
                    const serverStatus = data.data.status;
                    const localStatus = '<?php echo $attendance['status'] ?? 'not_started'; ?>';
                    if (serverStatus !== localStatus) {
                        location.reload();
                    }
                }
            });
        }, 30000);
    </script>
</body>
</html>