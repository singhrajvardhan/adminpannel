<?php
// /raj/admin/dashboard.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

// ============================================================
// STATISTICS
// ============================================================
$totalAgents = 0;
$completedOnboarding = 0;
$pendingOnboarding = 0;
$verifiedAgents = 0;
$pendingVerification = 0;
$dayShiftCount = 0;
$nightShiftCount = 0;
$officeCount = 0;
$homeCount = 0;

$result = executeQuery("SELECT COUNT(*) as count FROM agents");
if ($result) { $row = $result->fetch_assoc(); $totalAgents = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE onboarding_completed = 1");
if ($result) { $row = $result->fetch_assoc(); $completedOnboarding = $row['count'] ?? 0; }
$pendingOnboarding = $totalAgents - $completedOnboarding;

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE verification_status = 'verified'");
if ($result) { $row = $result->fetch_assoc(); $verifiedAgents = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE verification_status = 'pending' AND onboarding_completed = 1");
if ($result) { $row = $result->fetch_assoc(); $pendingVerification = $row['count'] ?? 0; }

// Shift & Location Stats
$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE shift_type = 'day'");
if ($result) { $row = $result->fetch_assoc(); $dayShiftCount = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE shift_type = 'night'");
if ($result) { $row = $result->fetch_assoc(); $nightShiftCount = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE work_location = 'office'");
if ($result) { $row = $result->fetch_assoc(); $officeCount = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE work_location = 'home'");
if ($result) { $row = $result->fetch_assoc(); $homeCount = $row['count'] ?? 0; }

// ============================================================
// TODAY'S LOGIN STATS
// ============================================================
$today = date('Y-m-d');
$todayLoginCount = 0;
$todayNotLoginCount = 0;
$todayActiveCount = 0;
$todayOnBreakCount = 0;

$result = executeQuery("SELECT COUNT(DISTINCT agent_id) as count FROM attendance WHERE date = ? AND login_time IS NOT NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $todayLoginCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(DISTINCT agent_id) as count FROM attendance WHERE date = ? AND logout_time IS NULL AND login_time IS NOT NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $todayActiveCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(DISTINCT agent_id) as count FROM attendance WHERE date = ? AND status = 'on_break' AND logout_time IS NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $todayOnBreakCount = $row['count'] ?? 0; }

$todayNotLoginCount = $totalAgents - $todayLoginCount;

// ============================================================
// BREAK STATS FOR TODAY
// ============================================================
$totalLunchBreaks = 0;
$totalSnackBreaks = 0;
$totalBioBreaks = 0;

$result = executeQuery("SELECT COUNT(*) as count FROM attendance WHERE date = ? AND lunch_start IS NOT NULL AND lunch_end IS NOT NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $totalLunchBreaks = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM attendance WHERE date = ? AND snack_start IS NOT NULL AND snack_end IS NOT NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $totalSnackBreaks = $row['count'] ?? 0; }
$result = executeQuery("SELECT COUNT(*) as count FROM attendance WHERE date = ? AND bio_start IS NOT NULL AND bio_end IS NOT NULL", [$today]);
if ($result) { $row = $result->fetch_assoc(); $totalBioBreaks = $row['count'] ?? 0; }

// ============================================================
// TERMINATION / RESIGNATION STATS
// ============================================================
$activeEmployeesCount = 0;
$resignedCount = 0;
$terminatedCount = 0;
$abscondedCount = 0;
$onHoldCount = 0;

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE COALESCE(employment_status, 'active') = 'active'");
if ($result) { $row = $result->fetch_assoc(); $activeEmployeesCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE employment_status = 'resigned'");
if ($result) { $row = $result->fetch_assoc(); $resignedCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE employment_status = 'terminated'");
if ($result) { $row = $result->fetch_assoc(); $terminatedCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE employment_status = 'absconded'");
if ($result) { $row = $result->fetch_assoc(); $abscondedCount = $row['count'] ?? 0; }

$result = executeQuery("SELECT COUNT(*) as count FROM agents WHERE employment_status = 'on_hold'");
if ($result) { $row = $result->fetch_assoc(); $onHoldCount = $row['count'] ?? 0; }

$totalLeftCount = $resignedCount + $terminatedCount + $abscondedCount;

// ============================================================
// GET RECENT AGENTS WITH TODAY'S DATA
// ============================================================
$recentAgents = executeQuery("
    SELECT a.agent_id, a.full_name, a.mobile, a.email, a.created_at, 
           a.onboarding_completed, a.verification_status, a.shift_type, a.work_location,
           att.login_time, att.logout_time, att.job_start_time, att.job_end_time,
           att.lunch_start, att.lunch_end, att.snack_start, att.snack_end,
           att.bio_start, att.bio_end, att.bio_count, att.status
    FROM agents a
    LEFT JOIN attendance att ON a.agent_id = att.agent_id AND att.date = CURDATE()
    ORDER BY a.created_at DESC
    LIMIT 15
");

// Get today's active agents
$activeAgents = executeQuery("
    SELECT a.agent_id, a.full_name, a.shift_type, a.work_location,
           att.job_start_time, att.login_time, att.lunch_start, att.lunch_end,
           att.snack_start, att.snack_end, att.bio_start, att.bio_end,
           att.bio_count, att.status
    FROM agents a
    JOIN attendance att ON a.agent_id = att.agent_id 
    WHERE att.date = CURDATE() 
      AND att.job_start_time IS NOT NULL 
      AND att.logout_time IS NULL
    ORDER BY att.job_start_time DESC
");

// Get agents not logged in today
$notLoggedInAgents = executeQuery("
    SELECT a.agent_id, a.full_name, a.email, a.mobile, a.shift_type, a.work_location
    FROM agents a
    LEFT JOIN attendance att ON a.agent_id = att.agent_id AND att.date = CURDATE()
    WHERE att.agent_id IS NULL OR att.login_time IS NULL
    ORDER BY a.full_name ASC
    LIMIT 10
");

// ============================================================
// RECENT TERMINATION HISTORY
// ============================================================
$recentTerminations = executeQuery("
    SELECT * FROM agent_termination_history 
    ORDER BY action_at DESC 
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-900: #10254a; --navy-800: #163063;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-300: #e9c877;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success-bg: #e4f4e8; --success-ink: #1e7a41;
            --danger-bg: #fbe7e7; --danger-ink: #b3352f; --warn-bg: #fdf1de;
            --warn-ink: #9a6a10; --info-bg: #e6eefb; --info-ink: #29538c;
            --shadow-sm: 0 1px 2px rgba(12,26,56,.05), 0 1px 3px rgba(12,26,56,.06);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08); --shadow-lg: 0 20px 45px rgba(12,26,56,.14);
            --ease: cubic-bezier(.22,.61,.36,1); --radius: 16px; --radius-sm: 10px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; background: var(--cream); color: var(--ink); -webkit-font-smoothing: antialiased; }

        .admin-wrapper { display: flex; min-height: 100vh; }

        .sidebar {
            width: 264px;
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa; padding: 22px 0 20px; position: fixed; height: 100vh;
            overflow-y: auto; z-index: 40; transition: transform .35s var(--ease);
            border-right: 1px solid rgba(255,255,255,.06);
        }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 10px; }
        .sidebar-brand { padding: 4px 22px 20px; border-bottom: 1px solid rgba(255,255,255,.08); margin-bottom: 18px; }
        .sidebar-brand .logo-mark { display: flex; align-items: center; gap: 12px; }
        .sidebar-brand .logo-mark .logo-fallback {
            display: flex; height: 40px; width: 40px; border-radius: 10px;
            align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 18px;
            color: var(--navy-950); background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-brand .brand-text { font-family: 'Fraunces', serif; font-size: 15px; line-height: 1.25; }
        .sidebar-brand .brand-text b { color: #fff; font-weight: 600; display: block; font-size: 16px; }
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
        .sidebar-nav a.active { background: linear-gradient(90deg, rgba(201,150,47,.22), rgba(201,150,47,.05)); color: #fff; }
        .sidebar-nav a.active::before {
            content: ''; position: absolute; left: -12px; top: 6px; bottom: 6px; width: 3px;
            border-radius: 3px; background: linear-gradient(180deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-nav .nav-divider { height: 1px; background: rgba(255,255,255,.08); margin: 12px 14px; }
        .sidebar-nav .logout { color: #f2a3a0; }
        .sidebar-nav .logout:hover { background: rgba(240,90,80,.12); color: #ffb3b0; }
        .sidebar-nav .badge-count {
            margin-left: auto; background: rgba(255,255,255,.12);
            padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .sidebar-nav .badge-count.red { background: rgba(220, 53, 69, 0.25); color: #ffb3b0; }

        .main-content { margin-left: 264px; padding: 26px 32px 50px; flex: 1; min-width: 0; }

        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
        .main-header .heading-group { display: flex; align-items: center; gap: 14px; }
        .menu-toggle {
            display: none; width: 38px; height: 38px; border-radius: 9px;
            border: 1px solid var(--line); background: var(--paper);
            align-items: center; justify-content: center; cursor: pointer;
            font-size: 18px; color: var(--ink);
        }
        .main-header h1 { font-family: 'Fraunces', serif; font-weight: 600; color: var(--navy-950); font-size: 26px; letter-spacing: -.2px; }
        .main-header .subtitle { color: var(--ink-soft); font-size: 13.5px; margin-top: 2px; }

        .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

        .time-display {
            background: var(--paper); border-radius: var(--radius-sm); padding: 8px 16px;
            border: 1px solid var(--line); display: flex; align-items: center; gap: 10px;
            font-size: 14px; font-weight: 600; color: var(--navy-950); box-shadow: var(--shadow-sm);
        }
        .time-display i { color: var(--gold-500); font-size: 16px; }
        .time-display .time { font-family: 'Fraunces', serif; font-size: 18px; }

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

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card {
            background: var(--paper); padding: 20px 22px; border-radius: var(--radius);
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card .number { font-family: 'Fraunces', serif; font-size: 32px; font-weight: 700; color: var(--navy-950); }
        .stat-card .label { font-size: 13px; color: var(--ink-soft); font-weight: 500; margin-top: 2px; }
        .stat-card .icon { font-size: 20px; float: right; color: var(--gold-500); }

        .shift-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .shift-stat {
            background: var(--paper); padding: 14px 16px; border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); text-align: center;
        }
        .shift-stat .number { font-family: 'Fraunces', serif; font-size: 24px; font-weight: 700; }
        .shift-stat .number.day { color: #ffc107; }
        .shift-stat .number.night { color: #17a2b8; }
        .shift-stat .number.office { color: #28a745; }
        .shift-stat .number.home { color: #c9962f; }
        .shift-stat .label { font-size: 11px; color: var(--ink-soft); font-weight: 500; margin-top: 2px; }

        .today-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 24px; }
        .today-stat {
            background: var(--paper); padding: 12px 14px; border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); text-align: center;
        }
        .today-stat .number { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 700; }
        .today-stat .number.green { color: var(--success-ink); }
        .today-stat .number.orange { color: var(--warn-ink); }
        .today-stat .number.blue { color: var(--info-ink); }
        .today-stat .number.gold { color: var(--gold-500); }
        .today-stat .number.purple { color: #6f42c1; }
        .today-stat .label { font-size: 10px; color: var(--ink-soft); font-weight: 500; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.3px; }

        /* ===== SALES CARD ===== */
        .sales-card {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            border-radius: var(--radius); padding: 16px 24px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px; box-shadow: var(--shadow-md);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
            text-decoration: none; color: var(--navy-950);
        }
        .sales-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .sales-card .sales-info h3 { font-family: 'Fraunces', serif; font-size: 18px; font-weight: 600; }
        .sales-card .sales-info p { font-size: 13px; opacity: 0.8; margin-top: 2px; }
        .sales-card .sales-arrow { font-size: 22px; transition: transform .3s var(--ease); }
        .sales-card:hover .sales-arrow { transform: translateX(6px); }

        /* ===== TERMINATION CARD ===== */
        .termination-card {
            background: linear-gradient(135deg, #4a1520, #6b1d1d);
            border-radius: var(--radius); padding: 18px 24px; margin-bottom: 24px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
            box-shadow: 0 6px 20px rgba(107, 29, 29, 0.25);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
            text-decoration: none; color: #fff;
        }
        .termination-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(107, 29, 29, 0.35); }
        .termination-card .term-info h3 {
            font-family: 'Fraunces', serif; font-size: 18px; font-weight: 600;
            display: flex; align-items: center; gap: 10px;
        }
        .termination-card .term-info p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .termination-card .term-stats { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .termination-card .term-stat { text-align: center; }
        .termination-card .term-stat .stat-num {
            font-family: 'Fraunces', serif; font-size: 24px; font-weight: 700;
            color: #fff; line-height: 1;
        }
        .termination-card .term-stat .stat-lbl {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
            color: rgba(255,255,255,0.7); margin-top: 2px;
        }
        .termination-card .term-arrow { font-size: 22px; opacity: 0.8; transition: transform .3s var(--ease); }
        .termination-card:hover .term-arrow { transform: translateX(6px); }

        /* ===== TABLE CONTAINER ===== */
        .table-container {
            background: var(--paper); border-radius: var(--radius); padding: 20px 22px 8px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            overflow-x: auto; margin-bottom: 20px;
        }
        .table-container .table-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px; flex-wrap: wrap; gap: 8px;
        }
        .table-container h2 { font-family: 'Fraunces', serif; color: var(--navy-950); font-size: 17px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; min-width: 780px; }
        th {
            text-align: left; padding: 8px 10px; background: transparent;
            color: var(--ink-soft); font-weight: 600; font-size: 10px;
            text-transform: uppercase; letter-spacing: .5px;
            border-bottom: 1px solid var(--line); white-space: nowrap;
        }
        td { padding: 10px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 12px; }
        tbody tr:hover { background: #faf9f6; }

        .agent-cell { display: flex; align-items: center; gap: 8px; }
        .agent-cell .avatar-sm {
            width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
            background: var(--navy-900); color: var(--gold-300);
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .agent-cell .agent-name { font-weight: 600; color: var(--ink); font-size: 13px; }
        .agent-cell .agent-id { font-size: 10px; color: var(--ink-soft); }

        .badge {
            padding: 2px 10px; border-radius: 999px; font-size: 10px;
            font-weight: 600; display: inline-flex; align-items: center;
            gap: 3px; white-space: nowrap;
        }
        .badge-success { background: var(--success-bg); color: var(--success-ink); }
        .badge-warning { background: var(--warn-bg); color: var(--warn-ink); }
        .badge-danger { background: var(--danger-bg); color: var(--danger-ink); }
        .badge-info { background: var(--info-bg); color: var(--info-ink); }
        .badge-secondary { background: #eef0f2; color: #55606e; }
        .badge-day { background: rgba(255,193,7,.15); color: #ffc107; }
        .badge-night { background: rgba(23,162,184,.15); color: #17a2b8; }
        .badge-office { background: rgba(40,167,69,.15); color: #28a745; }
        .badge-home { background: rgba(201,150,47,.15); color: #c9962f; }

        .btn {
            padding: 4px 10px; border: none; border-radius: 6px;
            font-size: 10px; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center;
            gap: 3px; transition: transform .2s var(--ease), box-shadow .2s var(--ease);
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--navy-800); color: white; }
        .btn-primary:hover { background: var(--navy-700); transform: translateY(-1px); }
        .btn-sm { padding: 2px 8px; font-size: 9px; }

        /* ===== TERMINATION HISTORY LIST ===== */
        .termination-history-list { display: flex; flex-direction: column; gap: 8px; }
        .termination-history-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: var(--radius-sm);
            background: var(--cream); border-left: 4px solid var(--line);
            font-size: 12px;
        }
        .termination-history-item.terminated { border-left-color: var(--danger-ink); background: #fff5f5; }
        .termination-history-item.resigned { border-left-color: var(--warn-ink); background: #fffdf5; }
        .termination-history-item.absconded { border-left-color: #7c3aed; background: #f8f5ff; }
        .termination-history-item.reinstated,
        .termination-history-item.reinstate { border-left-color: var(--success-ink); background: #f0f9f3; }
        .termination-history-item .th-icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .termination-history-item.terminated .th-icon { background: #fbe7e7; color: var(--danger-ink); }
        .termination-history-item.resigned .th-icon { background: #fdf1de; color: var(--warn-ink); }
        .termination-history-item.absconded .th-icon { background: #ede4ff; color: #7c3aed; }
        .termination-history-item.reinstated .th-icon,
        .termination-history-item.reinstate .th-icon { background: #e4f4e8; color: var(--success-ink); }
        .termination-history-item .th-info { flex: 1; min-width: 0; }
        .termination-history-item .th-name { font-weight: 600; color: var(--navy-950); }
        .termination-history-item .th-reason { color: var(--ink-soft); font-size: 11px; margin-top: 2px; }
        .termination-history-item .th-time { color: #999; font-size: 10px; white-space: nowrap; text-align: right; }

        .empty-state { text-align: center; padding: 30px 20px; color: var(--ink-soft); }
        .empty-state i { font-size: 32px; opacity: 0.3; display: block; margin-bottom: 8px; }

        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(12,26,56,.45); z-index: 30; }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
            .main-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; justify-content: flex-start; }
        }

        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .sidebar-nav .badge-count { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .shift-stats { grid-template-columns: 1fr 1fr; }
            .today-stats { grid-template-columns: 1fr 1fr; }
            .time-display { font-size: 12px; }
            .time-display .time { font-size: 15px; }
            .header-actions { flex-direction: column; }
            .time-display { width: 100%; justify-content: center; }
            .termination-card { flex-direction: column; text-align: center; }
            .termination-card .term-stats { justify-content: center; width: 100%; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; }
            .shift-stats { grid-template-columns: 1fr; }
            .today-stats { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) { * { transition-duration: 0.001ms !important; } }
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
                <a href="dashboard.php" class="active">
                    <span class="icon"><i class="fas fa-th-large"></i></span>
                    <span>Dashboard</span>
                    <span class="badge-count"><?php echo $totalAgents; ?></span>
                </a>
                <a href="db2.php">
                    <span class="icon"><i class="fas fa-file-contract"></i></span>
                    <span>Letter Dashboard 2</span>
                </a>
                <a href="db.php">
                    <span class="icon"><i class="fas fa-file-contract"></i></span>
                    <span>Sale Dashboard 3</span>
                </a>
                <a href="profile-setup.php">
                    <span class="icon"><i class="fas fa-user-cog"></i></span>
                    <span>Profile</span>
                </a>
                <a href="login-logs.php">
                    <span class="icon"><i class="fas fa-key"></i></span>
                    <span>Admin Login Logs</span>
                </a>
                <a href="create-agent.php">
                    <span class="icon"><i class="fas fa-user-plus"></i></span>
                    <span>Create Agent</span>
                </a>
                <a href="edit-agent.php">
                    <span class="icon"><i class="fas fa-user-edit"></i></span>
                    <span>Edit Agents</span>
                </a>
                <a href="agents.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                </a>
                <div class="nav-divider"></div>
                <a href="assign-shift.php">
                    <span class="icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Assign Shift</span>
                </a>
                <a href="agent-daily-report.php">
                    <span class="icon"><i class="fas fa-file-alt"></i></span>
                    <span>Daily Report</span>
                </a>
                <a href="location-tracking.php">
                    <span class="icon"><i class="fas fa-map-marker-alt"></i></span>
                    <span>Location Tracking</span>
                </a>
                <a href="agent-termination.php">
                    <span class="icon"><i class="fas fa-user-slash"></i></span>
                    <span>Termination</span>
                    <?php if($totalLeftCount > 0): ?>
                    <span class="badge-count red"><?php echo $totalLeftCount; ?></span>
                    <?php endif; ?>
                </a>
                <div class="nav-divider"></div>
                <a href="admin-email.php">
                    <span class="icon"><i class="fas fa-envelope"></i></span>
                    <span>Email Reports</span>
                </a>
                <div class="nav-divider"></div>
                <a href="agents.php?status=pending">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Pending Verification</span>
                    <span class="badge-count"><?php echo $pendingVerification; ?></span>
                </a>
                <a href="agents.php?status=verified">
                    <span class="icon"><i class="fas fa-check-circle"></i></span>
                    <span>Verified Agents</span>
                    <span class="badge-count"><?php echo $verifiedAgents; ?></span>
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
                        <h1>Admin Dashboard</h1>
                        <div class="subtitle">Overview of agents, shifts, onboarding & verification</div>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="time-display">
                        <i class="fas fa-clock"></i>
                        <span>🇮🇳 India:</span>
                        <span class="time" id="indiaTime"><?php echo date('h:i:s A'); ?></span>
                    </div>
                    <div class="admin-chip">
                        <div class="avatar">AD</div>
                        <div>
                            <div class="name">Admin</div>
                            <div class="role">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <div class="number"><?php echo $totalAgents; ?></div>
                    <div class="label">Total Agents</div>
                </div>
                <div class="stat-card">
                    <span class="icon"><i class="fas fa-check-circle"></i></span>
                    <div class="number"><?php echo $completedOnboarding; ?></div>
                    <div class="label">Onboarding Completed</div>
                </div>
                <div class="stat-card">
                    <span class="icon"><i class="fas fa-hourglass-half"></i></span>
                    <div class="number"><?php echo $pendingOnboarding; ?></div>
                    <div class="label">Pending Onboarding</div>
                </div>
                <div class="stat-card">
                    <span class="icon"><i class="fas fa-shield-alt"></i></span>
                    <div class="number"><?php echo $verifiedAgents; ?></div>
                    <div class="label">Verified Agents</div>
                </div>
            </div>

            <!-- Shift & Location Stats -->
            <div class="shift-stats">
                <div class="shift-stat">
                    <div class="number day"><?php echo $dayShiftCount; ?></div>
                    <div class="label">🌅 Day Shift</div>
                </div>
                <div class="shift-stat">
                    <div class="number night"><?php echo $nightShiftCount; ?></div>
                    <div class="label">🌙 Night Shift</div>
                </div>
                <div class="shift-stat">
                    <div class="number office"><?php echo $officeCount; ?></div>
                    <div class="label">🏢 Work from Office</div>
                </div>
                <div class="shift-stat">
                    <div class="number home"><?php echo $homeCount; ?></div>
                    <div class="label">🏠 Work from Home</div>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="today-stats">
                <div class="today-stat">
                    <div class="number green"><?php echo $todayLoginCount; ?></div>
                    <div class="label">✅ Logged In</div>
                </div>
                <div class="today-stat">
                    <div class="number orange"><?php echo $todayNotLoginCount; ?></div>
                    <div class="label">❌ Not Logged In</div>
                </div>
                <div class="today-stat">
                    <div class="number blue"><?php echo $todayActiveCount; ?></div>
                    <div class="label">🟢 Active Now</div>
                </div>
                <div class="today-stat">
                    <div class="number purple"><?php echo $todayOnBreakCount; ?></div>
                    <div class="label">☕ On Break</div>
                </div>
                <div class="today-stat">
                    <div class="number gold"><?php echo $totalLunchBreaks; ?></div>
                    <div class="label">🍽️ Lunch</div>
                </div>
                <div class="today-stat">
                    <div class="number" style="color:#6f42c1;"><?php echo $totalSnackBreaks; ?></div>
                    <div class="label">🍪 Snack</div>
                </div>
                <div class="today-stat">
                    <div class="number" style="color:#28a745;"><?php echo $totalBioBreaks; ?></div>
                    <div class="label">🚽 Bio</div>
                </div>
            </div>

            <!-- Sales Card -->
            <a href="/raj/admin/db.php" class="sales-card">
                <div class="sales-info">
                    <h3><i class="fas fa-chart-line"></i> Sales Dashboard</h3>
                    <p>View sales performance, revenue, and team analytics</p>
                </div>
                <div class="sales-arrow"><i class="fas fa-arrow-right"></i></div>
            </a>

            <!-- Termination & Resignation Card -->
            <a href="agent-termination.php" class="termination-card">
                <div class="term-info">
                    <h3><i class="fas fa-user-slash"></i> Termination & Resignation</h3>
                    <p>Manage agents who have resigned, terminated, or absconded — data is always preserved</p>
                </div>
                <div class="term-stats">
                    <div class="term-stat">
                        <div class="stat-num"><?php echo $resignedCount; ?></div>
                        <div class="stat-lbl">Resigned</div>
                    </div>
                    <div class="term-stat">
                        <div class="stat-num"><?php echo $terminatedCount; ?></div>
                        <div class="stat-lbl">Terminated</div>
                    </div>
                    <div class="term-stat">
                        <div class="stat-num"><?php echo $abscondedCount; ?></div>
                        <div class="stat-lbl">Absconded</div>
                    </div>
                    <div class="term-stat">
                        <div class="stat-num"><?php echo $onHoldCount; ?></div>
                        <div class="stat-lbl">On Hold</div>
                    </div>
                </div>
                <div class="term-arrow"><i class="fas fa-arrow-right"></i></div>
            </a>

            <!-- Active Agents -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-circle" style="color:var(--success-ink);font-size:10px;margin-right:6px;"></i> Active Agents (Working Now)</h2>
                    <span style="color:var(--ink-soft);font-size:12px;">
                        <?php 
                            $activeCount = ($activeAgents && $activeAgents->num_rows > 0) ? $activeAgents->num_rows : 0;
                            echo $activeCount . ' agent' . ($activeCount !== 1 ? 's' : '') . ' active';
                        ?>
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Shift</th>
                            <th>Location</th>
                            <th>Start Time</th>
                            <th>Lunch</th>
                            <th>Snack</th>
                            <th>Bio</th>
                            <th>Bio Count</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($activeAgents && $activeAgents->num_rows > 0): ?>
                            <?php while ($row = $activeAgents->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="agent-cell">
                                        <div class="avatar-sm">
                                            <?php 
                                                $parts = explode(' ', $row['full_name']);
                                                $initials = strtoupper(substr($parts[0], 0, 1));
                                                if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
                                                echo $initials ?: '?';
                                            ?>
                                        </div>
                                        <div>
                                            <div class="agent-name"><?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?></div>
                                            <div class="agent-id">#<?php echo htmlspecialchars($row['agent_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['shift_type'] ?? 'day') === 'day' ? 'badge-day' : 'badge-night'; ?>">
                                        <?php echo ucfirst($row['shift_type'] ?? 'Day'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['work_location'] ?? 'office') === 'office' ? 'badge-office' : 'badge-home'; ?>">
                                        <?php echo ucfirst($row['work_location'] ?? 'Office'); ?>
                                    </span>
                                </td>
                                <td><?php echo !empty($row['job_start_time']) ? date('h:i A', strtotime($row['job_start_time'])) : '--'; ?></td>
                                <td>
                                    <?php if (!empty($row['lunch_start']) && !empty($row['lunch_end'])): ?>
                                        <span class="badge badge-success">✅ Done</span>
                                    <?php elseif (!empty($row['lunch_start'])): ?>
                                        <span class="badge badge-warning">⏳ In Progress</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['snack_start']) && !empty($row['snack_end'])): ?>
                                        <span class="badge badge-success">✅ Done</span>
                                    <?php elseif (!empty($row['snack_start'])): ?>
                                        <span class="badge badge-warning">⏳ In Progress</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['bio_start']) && !empty($row['bio_end'])): ?>
                                        <span class="badge badge-success">✅ Done</span>
                                    <?php elseif (!empty($row['bio_start'])): ?>
                                        <span class="badge badge-warning">⏳ In Progress</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">--</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-info"><?php echo (int)($row['bio_count'] ?? 0); ?></span></td>
                                <td>
                                    <?php if ($row['status'] === 'on_break'): ?>
                                        <span class="badge badge-warning">On Break</span>
                                    <?php elseif ($row['status'] === 'paused'): ?>
                                        <span class="badge badge-info">Paused</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Working</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No agents currently active.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Not Logged In Agents -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-clock" style="color:var(--danger-ink);font-size:10px;margin-right:6px;"></i> Not Logged In Today</h2>
                    <span style="color:var(--ink-soft);font-size:12px;">
                        <?php 
                            $notLoggedCount = ($notLoggedInAgents && $notLoggedInAgents->num_rows > 0) ? $notLoggedInAgents->num_rows : 0;
                            echo $notLoggedCount . ' agent' . ($notLoggedCount !== 1 ? 's' : '');
                        ?>
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Shift</th>
                            <th>Location</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($notLoggedInAgents && $notLoggedInAgents->num_rows > 0): ?>
                            <?php while ($row = $notLoggedInAgents->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="agent-cell">
                                        <div class="avatar-sm">
                                            <?php 
                                                $parts = explode(' ', $row['full_name']);
                                                $initials = strtoupper(substr($parts[0], 0, 1));
                                                if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
                                                echo $initials ?: '?';
                                            ?>
                                        </div>
                                        <div>
                                            <div class="agent-name" style="color:var(--danger-ink);"><?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?></div>
                                            <div class="agent-id">#<?php echo htmlspecialchars($row['agent_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['shift_type'] ?? 'day') === 'day' ? 'badge-day' : 'badge-night'; ?>">
                                        <?php echo ucfirst($row['shift_type'] ?? 'Day'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['work_location'] ?? 'office') === 'office' ? 'badge-office' : 'badge-home'; ?>">
                                        <?php echo ucfirst($row['work_location'] ?? 'Office'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['mobile'] ?: 'N/A'); ?></td>
                                <td>
                                    <a href="view-agent.php?id=<?php echo $row['agent_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state" style="color:var(--success-ink);">
                                        <i class="fas fa-check-circle" style="color:var(--success-ink);"></i>
                                        <p>All agents have logged in today!</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Termination Activity -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-history" style="color:var(--danger-ink);margin-right:6px;"></i> Recent Termination Activity</h2>
                    <a href="agent-termination.php" class="btn btn-primary btn-sm" style="background:var(--danger-ink);">
                        <i class="fas fa-external-link-alt"></i> View All
                    </a>
                </div>
                
                <?php if ($recentTerminations && $recentTerminations->num_rows > 0): ?>
                <div class="termination-history-list" style="padding-bottom:12px;">
                    <?php while ($term = $recentTerminations->fetch_assoc()): 
                        $actionType = $term['action_type'] ?? 'terminated';
                        $actionIcon = '❌';
                        if ($actionType === 'resigned') $actionIcon = '📤';
                        elseif ($actionType === 'absconded') $actionIcon = '🚫';
                        elseif ($actionType === 'reinstate' || $actionType === 'reinstated') $actionIcon = '♻️';
                    ?>
                    <div class="termination-history-item <?php echo $actionType; ?>">
                        <div class="th-icon"><?php echo $actionIcon; ?></div>
                        <div class="th-info">
                            <div class="th-name"><?php echo htmlspecialchars($term['agent_name'] ?? $term['agent_id']); ?>
                                <span style="color:var(--ink-soft);font-weight:400;"> — <?php echo ucfirst($actionType); ?></span>
                            </div>
                            <?php if (!empty($term['reason'])): ?>
                            <div class="th-reason">
                                <i class="fas fa-quote-left" style="color:var(--gold-500);"></i> 
                                <?php echo htmlspecialchars(substr($term['reason'], 0, 80)); ?><?php echo strlen($term['reason']) > 80 ? '...' : ''; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="th-time">
                            <?php echo date('d M Y', strtotime($term['action_at'])); ?><br>
                            <?php echo date('h:i A', strtotime($term['action_at'])); ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color:var(--success-ink);"></i>
                    <p>No terminations or resignations recorded yet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Agents -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-clock-rotate-left" style="color:var(--gold-500);margin-right:6px;"></i> Recent Agents</h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="agents.php" class="btn btn-primary btn-sm">View All</a>
                        <a href="create-agent.php" class="btn btn-primary btn-sm" style="background:var(--gold-500);color:var(--navy-950);">
                            <i class="fas fa-plus"></i> Add
                        </a>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Shift</th>
                            <th>Location</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>Created</th>
                            <th>Onboarding</th>
                            <th>Verification</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentAgents && $recentAgents->num_rows > 0): ?>
                            <?php while ($row = $recentAgents->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="agent-cell">
                                        <div class="avatar-sm">
                                            <?php 
                                                $parts = explode(' ', $row['full_name']);
                                                $initials = strtoupper(substr($parts[0], 0, 1));
                                                if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
                                                echo $initials ?: '?';
                                            ?>
                                        </div>
                                        <div>
                                            <div class="agent-name"><?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?></div>
                                            <div class="agent-id">#<?php echo htmlspecialchars($row['agent_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['shift_type'] ?? 'day') === 'day' ? 'badge-day' : 'badge-night'; ?>">
                                        <?php echo ucfirst($row['shift_type'] ?? 'Day'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo ($row['work_location'] ?? 'office') === 'office' ? 'badge-office' : 'badge-home'; ?>">
                                        <?php echo ucfirst($row['work_location'] ?? 'Office'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['mobile'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?></td>
                                <td><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <span class="badge <?php echo $row['onboarding_completed'] ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $row['onboarding_completed'] ? '✅ Completed' : '⏳ Pending'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php 
                                        echo $row['verification_status'] === 'verified' ? 'badge-success' : 
                                            ($row['verification_status'] === 'rejected' ? 'badge-danger' : 
                                            ($row['verification_status'] === 'correction_requested' ? 'badge-warning' : 'badge-info')); 
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['verification_status'] ?: 'Pending')); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view-agent.php?id=<?php echo $row['agent_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No agents found. <a href="create-agent.php" style="color:var(--gold-500);font-weight:600;">Create your first agent</a></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

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

        // ============================================================
        // INDIA TIME - LIVE UPDATE
        // ============================================================
        function updateIndiaTime() {
            const now = new Date();
            const indiaOffset = 5.5 * 60 * 60 * 1000;
            const utc = now.getTime() + (now.getTimezoneOffset() * 60 * 1000);
            const indiaTime = new Date(utc + indiaOffset);
            
            const timeStr = indiaTime.toLocaleTimeString('en-US', { 
                hour12: true, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            
            document.getElementById('indiaTime').textContent = timeStr;
        }

        setInterval(updateIndiaTime, 1000);
        updateIndiaTime();

        // Auto refresh dashboard every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>