<?php
// /raj/admin/login-logs.php
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';

// Get last 25 login records - now shows India time
$logsQuery = executeQuery("
    SELECT * FROM admin_login_logs 
    ORDER BY login_time DESC 
    LIMIT 25
");

$logs = [];
$totalLogins = 0;
$activeSessions = 0;
$totalDuration = 0;

if ($logsQuery) {
    while ($row = $logsQuery->fetch_assoc()) {
        $logs[] = $row;
        $totalLogins++;
        if ($row['status'] === 'active') $activeSessions++;
        $totalDuration += (int)($row['session_duration'] ?? 0);
    }
}

$completedSessions = max(1, $totalLogins - $activeSessions);
$avgDuration = $totalLogins > 0 ? round($totalDuration / $completedSessions) : 0;

// Helper functions with unique names
if (!function_exists('formatLogDuration')) {
    function formatLogDuration($seconds) {
        if ($seconds <= 0) return '—';
        if ($seconds < 60) return $seconds . ' sec';
        if ($seconds < 3600) return round($seconds / 60, 1) . ' min';
        if ($seconds < 86400) {
            $h = floor($seconds / 3600);
            $m = floor(($seconds % 3600) / 60);
            return $h . 'h ' . $m . 'm';
        }
        $d = floor($seconds / 86400);
        $h = floor(($seconds % 86400) / 3600);
        return $d . 'd ' . $h . 'h';
    }
}

if (!function_exists('getLogBrowser')) {
    function getLogBrowser($userAgent) {
        if (empty($userAgent)) return '🌐 Unknown';
        if (strpos($userAgent, 'Chrome') !== false && strpos($userAgent, 'Edg') === false) return '🌐 Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return '🦊 Firefox';
        if (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) return '🧭 Safari';
        if (strpos($userAgent, 'Edg') !== false) return '📧 Edge';
        if (strpos($userAgent, 'Opera') !== false) return '🎭 Opera';
        return '🌐 Browser';
    }
}

if (!function_exists('getLogDevice')) {
    function getLogDevice($userAgent) {
        if (empty($userAgent)) return '❓ Unknown';
        if (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false) return '📱 Mobile';
        if (strpos($userAgent, 'Tablet') !== false || strpos($userAgent, 'iPad') !== false) return '📟 Tablet';
        return '💻 Desktop';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Activity - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-100: #fdf1de;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
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
            box-shadow: 0 4px 20px rgba(0,0,0,.2); position: sticky; top: 0; z-index: 100;
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-500); font-weight: 600; }

        .content { max-width: 1300px; margin: 0 auto; padding: 22px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 20px; }
        .timezone-info { font-size: 11px; color: var(--info); margin-bottom: 16px; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); text-align: center;
        }
        .stat-card .stat-icon { font-size: 24px; margin-bottom: 6px; }
        .stat-card .stat-value { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--navy-950); }
        .stat-card .stat-label { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; letter-spacing: .5px; }
        .stat-card.blue { border-top: 4px solid var(--info); }
        .stat-card.green { border-top: 4px solid var(--success); }
        .stat-card.gold { border-top: 4px solid var(--gold-500); }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); margin-bottom: 20px;
        }
        .card h3 { font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .card h3 i { color: var(--gold-500); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 800px; }
        th { background: var(--navy-800); color: #fff; padding: 12px 14px; text-align: left; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
        td { padding: 12px 14px; border-bottom: 1px solid var(--line); }
        tr:hover { background: var(--cream); }
        tr.active-row { background: #e8f5e9; }

        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .status-dot.active { background: var(--success); animation: pulse 2s infinite; }
        .status-dot.logged_out { background: #aaa; }
        .status-dot.expired { background: var(--danger); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        .status-badge {
            padding: 4px 12px; border-radius: 15px; font-size: 10px; font-weight: 600;
            display: inline-flex; align-items: center; white-space: nowrap;
        }
        .status-badge.active { background: #e4f4e8; color: var(--success); }
        .status-badge.logged_out { background: #f0f0f0; color: var(--ink-soft); }
        .status-badge.expired { background: #fbe7e7; color: var(--danger); }

        .duration-badge {
            background: var(--gold-100); color: var(--gold-500);
            padding: 3px 10px; border-radius: 12px; font-weight: 600; font-size: 11px; white-space: nowrap;
        }
        .duration-badge.long { background: #e4f4e8; color: var(--success); }
        .duration-badge.medium { background: #fdf1de; color: var(--warn); }
        .duration-badge.short { background: #fbe7e7; color: var(--danger); }

        .ip-badge {
            background: #e6eefb; color: var(--info);
            padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 500; font-family: monospace;
        }

        .info-note {
            background: #e6eefb; border-radius: var(--radius-sm); padding: 14px 18px;
            font-size: 12px; color: var(--info); display: flex; align-items: flex-start; gap: 8px;
        }

        .copyright-footer { text-align: center; padding: 20px; margin-top: 30px; border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft); }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media(max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 8px 10px; font-size: 10px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">🔐 Login Activity</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="login-logs.php" class="active">Login Logs</a>
            <a href="agents-manage.php">Agents</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">🔐 Admin Login Activity</h1>
        <p class="page-subtitle">Last 25 login records (India Standard Time - IST)</p>
        <p class="timezone-info"><i class="fas fa-clock"></i> Timezone: Asia/Kolkata (IST, UTC+5:30) | Current: <?php echo date('d M Y, h:i:s A'); ?></p>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card blue">
                <div class="stat-icon">🔑</div>
                <div class="stat-value"><?php echo $totalLogins; ?></div>
                <div class="stat-label">Total Logins (Last 25)</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">🟢</div>
                <div class="stat-value"><?php echo $activeSessions; ?></div>
                <div class="stat-label">Active Sessions</div>
            </div>
            <div class="stat-card gold">
                <div class="stat-icon">⏱️</div>
                <div class="stat-value"><?php echo formatLogDuration($avgDuration); ?></div>
                <div class="stat-label">Avg Session Duration</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👤</div>
                <div class="stat-value" style="font-size:18px;"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                <div class="stat-label">Current Admin</div>
            </div>
        </div>

        <!-- Login Logs Table -->
        <div class="card">
            <h3><i class="fas fa-list"></i> Last 25 Login Records</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Admin</th>
                            <th>Login Time (IST)</th>
                            <th>Logout Time (IST)</th>
                            <th>Duration</th>
                            <th>IP Address</th>
                            <th>Device</th>
                            <th>Browser</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($logs)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:40px;color:var(--ink-soft);">
                                🔐 No login records found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach($logs as $index => $log): 
                            $duration = (int)($log['session_duration'] ?? 0);
                            $durClass = $duration >= 3600 ? 'long' : ($duration >= 600 ? 'medium' : 'short');
                            $isActive = ($log['status'] === 'active');
                        ?>
                        <tr class="<?php echo $isActive ? 'active-row' : ''; ?>">
                            <td><strong><?php echo $index + 1; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($log['admin_name'] ?? 'Admin'); ?></strong>
                                <br><small style="color:var(--ink-soft);">ID: <?php echo htmlspecialchars($log['admin_id']); ?></small>
                            </td>
                            <td style="white-space:nowrap;">
                                <i class="fas fa-sign-in-alt" style="color:var(--success);"></i>
                                <?php echo date('d M Y', strtotime($log['login_time'])); ?><br>
                                <small style="color:var(--ink-soft);"><?php echo date('h:i:s A', strtotime($log['login_time'])); ?></small>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if(!empty($log['logout_time'])): ?>
                                    <i class="fas fa-sign-out-alt" style="color:var(--danger);"></i>
                                    <?php echo date('d M Y', strtotime($log['logout_time'])); ?><br>
                                    <small style="color:var(--ink-soft);"><?php echo date('h:i:s A', strtotime($log['logout_time'])); ?></small>
                                <?php else: ?>
                                    <span style="color:#aaa;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="duration-badge <?php echo $durClass; ?>">
                                    <i class="fas fa-clock"></i> <?php echo formatLogDuration($duration); ?>
                                </span>
                            </td>
                            <td><span class="ip-badge"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></span></td>
                            <td><?php echo getLogDevice($log['user_agent'] ?? ''); ?></td>
                            <td><?php echo getLogBrowser($log['user_agent'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge <?php echo $log['status']; ?>">
                                    <span class="status-dot <?php echo $log['status']; ?>"></span>
                                    <?php echo ucfirst(str_replace('_', ' ', $log['status'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="info-note">
            <i class="fas fa-info-circle"></i>
            <div><strong>Note:</strong> All times displayed in Indian Standard Time (IST). Active sessions are highlighted in green.</div>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>
</body>
</html>