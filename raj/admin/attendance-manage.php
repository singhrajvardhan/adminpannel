<?php
// /raj/admin/attendance-manage.php
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
$selectedMonth = $_GET['month'] ?? $currentMonth;
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));

// Get all active agents
$agentsQuery = executeQuery("SELECT agent_id, full_name, candidate_photo_path, shift_type FROM agents WHERE onboarding_completed = 1 AND status = 'active' ORDER BY full_name");
$agents = [];
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
    }
}

// Get attendance data for the month
$attendanceData = [];
$attQuery = executeQuery(
    "SELECT * FROM attendance WHERE DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date ASC, agent_id ASC",
    [$selectedMonth]
);
if ($attQuery) {
    while ($row = $attQuery->fetch_assoc()) {
        $attendanceData[$row['agent_id'] . '_' . $row['date']] = $row;
    }
}

// Calculate statistics
$agentStats = [];
foreach ($agents as $agent) {
    $aid = $agent['agent_id'];
    $present = 0; $absent = 0; $halfDay = 0; $holiday = 0; $weekend = 0; $notMarked = 0;
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%s-%02d', $selectedMonth, $day);
        $key = $aid . '_' . $date;
        $dayOfWeek = date('N', strtotime($date));
        
        if (isset($attendanceData[$key])) {
            $status = $attendanceData[$key]['attendance_status'] ?? 'present';
            if ($status === 'present') $present++;
            elseif ($status === 'absent') $absent++;
            elseif ($status === 'half_day') $halfDay++;
            elseif ($status === 'holiday') $holiday++;
            elseif ($status === 'weekend') $weekend++;
        } else {
            if ($dayOfWeek >= 6) {
                $weekend++;
            } else {
                $notMarked++;
            }
        }
    }
    
    $agentStats[$aid] = [
        'present' => $present,
        'absent' => $absent,
        'half_day' => $halfDay,
        'holiday' => $holiday,
        'weekend' => $weekend,
        'not_marked' => $notMarked,
        'total_working' => $present + $halfDay + $holiday,
        'total_days' => $daysInMonth
    ];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_attendance') {
        $agentId = $_POST['agent_id'] ?? '';
        $date = $_POST['date'] ?? '';
        $status = $_POST['attendance_status'] ?? 'present';
        $note = trim($_POST['note'] ?? '');
        
        if (!empty($agentId) && !empty($date)) {
            // Check if attendance record exists
            $existing = executeQuery("SELECT id FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $date]);
            
            if ($existing && $existing->num_rows > 0) {
                $result = executeQuery(
                    "UPDATE attendance SET attendance_status = ?, marked_by = ?, marked_at = NOW(), attendance_note = ? WHERE agent_id = ? AND date = ?",
                    [$status, $adminId, $note, $agentId, $date]
                );
            } else {
                $result = executeQuery(
                    "INSERT INTO attendance (agent_id, date, status, attendance_status, marked_by, marked_at, attendance_note) 
                     VALUES (?, ?, ?, ?, ?, NOW(), ?)",
                    [$agentId, $date, $status, $status, $adminId, $note]
                );
            }
            
            $message = $result !== false ? 'Attendance marked successfully!' : 'Error marking attendance';
            $msgType = $result !== false ? 'success' : 'error';
            
            // Refresh data
            $attendanceData = [];
            $attQuery = executeQuery("SELECT * FROM attendance WHERE DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date ASC", [$selectedMonth]);
            if ($attQuery) {
                while ($row = $attQuery->fetch_assoc()) {
                    $attendanceData[$row['agent_id'] . '_' . $row['date']] = $row;
                }
            }
        }
    }
    
    if ($action === 'mark_bulk') {
        $bulkAgentId = $_POST['bulk_agent_id'] ?? '';
        $bulkStatus = $_POST['bulk_status'] ?? 'present';
        $bulkNote = trim($_POST['bulk_note'] ?? '');
        $fromDay = (int)($_POST['from_day'] ?? 1);
        $toDay = (int)($_POST['to_day'] ?? $daysInMonth);
        
        $bulkCount = 0;
        for ($day = $fromDay; $day <= $toDay; $day++) {
            $date = sprintf('%s-%02d', $selectedMonth, $day);
            
            if ($bulkAgentId === 'all') {
                foreach ($agents as $agent) {
                    $existing = executeQuery("SELECT id FROM attendance WHERE agent_id = ? AND date = ?", [$agent['agent_id'], $date]);
                    if ($existing && $existing->num_rows > 0) {
                        executeQuery("UPDATE attendance SET attendance_status = ?, marked_by = ?, marked_at = NOW(), attendance_note = ? WHERE agent_id = ? AND date = ?", [$bulkStatus, $adminId, $bulkNote, $agent['agent_id'], $date]);
                    } else {
                        executeQuery("INSERT INTO attendance (agent_id, date, status, attendance_status, marked_by, marked_at, attendance_note) VALUES (?, ?, ?, ?, ?, NOW(), ?)", [$agent['agent_id'], $date, $bulkStatus, $bulkStatus, $adminId, $bulkNote]);
                    }
                    $bulkCount++;
                }
            } else {
                $existing = executeQuery("SELECT id FROM attendance WHERE agent_id = ? AND date = ?", [$bulkAgentId, $date]);
                if ($existing && $existing->num_rows > 0) {
                    executeQuery("UPDATE attendance SET attendance_status = ?, marked_by = ?, marked_at = NOW(), attendance_note = ? WHERE agent_id = ? AND date = ?", [$bulkStatus, $adminId, $bulkNote, $bulkAgentId, $date]);
                } else {
                    executeQuery("INSERT INTO attendance (agent_id, date, status, attendance_status, marked_by, marked_at, attendance_note) VALUES (?, ?, ?, ?, ?, NOW(), ?)", [$bulkAgentId, $date, $bulkStatus, $bulkStatus, $adminId, $bulkNote]);
                }
                $bulkCount++;
            }
        }
        
        $message = "Bulk marked $bulkCount entries as " . ucfirst($bulkStatus);
        $msgType = 'success';
        
        // Refresh data
        $attendanceData = [];
        $attQuery = executeQuery("SELECT * FROM attendance WHERE DATE_FORMAT(date, '%Y-%m') = ? ORDER BY date ASC", [$selectedMonth]);
        if ($attQuery) {
            while ($row = $attQuery->fetch_assoc()) {
                $attendanceData[$row['agent_id'] . '_' . $row['date']] = $row;
            }
        }
    }
}

// Navigation months
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --cream: #f8f7f3; --paper: #ffffff;
            --ink: #1c2536; --ink-soft: #667085; --line: #ecebe6;
            --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 14px; --radius-sm: 8px; --shadow: 0 1px 3px rgba(12,26,56,.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--cream); color: var(--ink); }

        .header {
            background: linear-gradient(190deg, #6b1d1d, #8b2525);
            color: #fce8e8; padding: 12px 24px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600; color: #fff; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px;
            border-radius: var(--radius-sm); font-size: 12px; font-weight: 500;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.15); }
        .header .nav a.active { color: var(--gold-400); }

        .content { max-width: 1300px; margin: 0 auto; padding: 20px 24px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 22px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 16px; }

        .alert { padding: 12px 18px; border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 13px; font-weight: 500; }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        .month-nav {
            display: flex; gap: 8px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;
        }
        .month-nav select {
            padding: 8px 16px; border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper); cursor: pointer;
        }
        .month-nav .nav-btn {
            padding: 8px 16px; background: var(--paper); border: 1px solid var(--line);
            border-radius: var(--radius-sm); cursor: pointer; text-decoration: none;
            color: var(--ink); font-size: 13px; font-weight: 500;
        }
        .month-nav .nav-btn:hover { background: var(--cream); }

        .tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
        .tabs .tab {
            padding: 10px 20px; border: none; background: var(--paper); cursor: pointer;
            border-radius: var(--radius-sm); font-family: 'Inter', sans-serif; font-size: 13px;
            font-weight: 500; color: var(--ink-soft); border: 1px solid var(--line);
        }
        .tabs .tab.active { background: var(--navy-800); color: #fff; border-color: var(--navy-800); }
        .tabs .tab:hover:not(.active) { background: var(--cream); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            margin-bottom: 16px; box-shadow: var(--shadow); border: 1px solid var(--line);
        }
        .card h3 {
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }

        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px; margin-bottom: 16px;
        }
        .stat-mini {
            background: var(--paper); border-radius: var(--radius-sm); padding: 12px;
            text-align: center; box-shadow: var(--shadow); border: 1px solid var(--line);
        }
        .stat-mini .value { font-family: 'Fraunces', serif; font-size: 24px; font-weight: 700; }
        .stat-mini .label { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }
        .stat-mini.present .value { color: var(--success); }
        .stat-mini.absent .value { color: var(--danger); }
        .stat-mini.holiday .value { color: var(--info); }
        .stat-mini.half .value { color: var(--warn); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        table th {
            background: var(--navy-800); color: #fff; padding: 8px 10px; text-align: left;
            font-weight: 500; font-size: 11px; position: sticky; top: 0; z-index: 2;
        }
        table th.center { text-align: center; }
        table td { padding: 8px 10px; border-bottom: 1px solid var(--line); }
        table tr:hover { background: var(--cream); }
        table .agent-name { font-weight: 600; color: var(--navy-950); }

        .status-cell {
            padding: 4px 10px; border-radius: 12px; font-size: 10px; font-weight: 600;
            display: inline-block; cursor: pointer; transition: transform .2s;
            text-align: center; min-width: 55px;
        }
        .status-cell:hover { transform: scale(1.05); }
        .status-present { background: #e4f4e8; color: var(--success); }
        .status-absent { background: #fbe7e7; color: var(--danger); }
        .status-half_day { background: #fdf1de; color: var(--warn); }
        .status-holiday { background: #e6eefb; color: var(--info); }
        .status-weekend { background: #f0f0f0; color: #999; }
        .status-not_marked { background: #fff; color: #ccc; border: 1px dashed #ddd; }

        .legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; font-size: 11px; }
        .legend-item { display: flex; align-items: center; gap: 4px; }
        .legend-dot { width: 12px; height: 12px; border-radius: 3px; }
        .legend-dot.present { background: #e4f4e8; border: 1px solid var(--success); }
        .legend-dot.absent { background: #fbe7e7; border: 1px solid var(--danger); }
        .legend-dot.half_day { background: #fdf1de; border: 1px solid var(--warn); }
        .legend-dot.holiday { background: #e6eefb; border: 1px solid var(--info); }
        .legend-dot.weekend { background: #f0f0f0; border: 1px solid #ddd; }

        .btn-sm {
            padding: 6px 14px; border: none; border-radius: var(--radius-sm); font-size: 11px;
            font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-save { background: var(--navy-800); color: #fff; }
        .btn-save:hover { background: var(--navy-950); }
        .btn-bulk { background: var(--gold-500); color: var(--navy-950); }
        .btn-bulk:hover { background: var(--gold-400); }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            max-width: 450px; width: 92%; box-shadow: 0 20px 45px rgba(12,26,56,.14);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); margin-bottom: 12px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft); margin-bottom: 4px; text-transform: uppercase; }
        .form-group select, .form-group input, .form-group textarea {
            width: 100%; padding: 8px 12px; border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-size: 13px;
        }
        .form-group select:focus, .form-group input:focus, .form-group textarea:focus {
            border-color: var(--gold-500); outline: none;
        }
        .form-group textarea { min-height: 60px; resize: vertical; }
        .btn-group { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .btn-confirm { padding: 10px 24px; background: var(--navy-800); color: #fff; border: none; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; font-family: 'Inter', sans-serif; }
        .btn-cancel { padding: 10px 24px; background: var(--cream); color: var(--ink-soft); border: 2px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; font-family: 'Inter', sans-serif; }

        .copyright-footer {
            text-align: center; padding: 15px 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            table { font-size: 10px; }
            table th, table td { padding: 6px 8px; }
            .status-cell { min-width: 40px; font-size: 9px; padding: 3px 8px; }
            .header { padding: 10px 16px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📋 Attendance Management</div>
        <div class="nav">
            <a href="db.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="attendance-manage.php" class="active"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📅 Attendance Management</h1>
        <p class="page-subtitle">Mark attendance for <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></p>

        <?php if (isset($message)): ?>
        <div class="alert alert-<?php echo $msgType ?? 'success'; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Month Navigation -->
        <div class="month-nav">
            <a href="?month=<?php echo $prevMonth; ?>" class="nav-btn">← Prev</a>
            <select onchange="window.location.href='?month=' + this.value">
                <?php for ($m = 1; $m <= 12; $m++): 
                    $val = sprintf('%04d-%02d', date('Y'), $m);
                ?>
                    <option value="<?php echo $val; ?>" <?php echo $val === $selectedMonth ? 'selected' : ''; ?>>
                        <?php echo date('F Y', strtotime($val . '-01')); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <a href="?month=<?php echo $nextMonth; ?>" class="nav-btn">Next →</a>
            <button class="btn-sm btn-bulk" onclick="openBulkModal()" style="margin-left:auto;">
                <i class="fas fa-layer-group"></i> Bulk Mark
            </button>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('grid')">📊 Grid View</button>
            <button class="tab" onclick="switchTab('summary')">📈 Summary</button>
        </div>

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item"><div class="legend-dot present"></div> Present</div>
            <div class="legend-item"><div class="legend-dot absent"></div> Absent</div>
            <div class="legend-item"><div class="legend-dot half_day"></div> Half Day</div>
            <div class="legend-item"><div class="legend-dot holiday"></div> Holiday</div>
            <div class="legend-item"><div class="legend-dot weekend"></div> Weekend</div>
        </div>

        <!-- Tab: Grid View -->
        <div class="tab-content active" id="tab-grid">
            <div class="card">
                <h3><i class="fas fa-table"></i> Daily Attendance Grid</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="min-width:140px;">Agent</th>
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                                    $date = sprintf('%s-%02d', $selectedMonth, $day);
                                    $dayName = date('D', strtotime($date));
                                    $isWeekend = date('N', strtotime($date)) >= 6;
                                ?>
                                <th class="center" style="background:<?php echo $isWeekend ? '#5a5a5a' : ''; ?>">
                                    <?php echo $dayName; ?><br><?php echo $day; ?>
                                </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $agent): 
                                $aid = $agent['agent_id'];
                            ?>
                            <tr>
                                <td class="agent-name">
                                    <?php echo htmlspecialchars($agent['full_name']); ?>
                                    <br><small style="color:var(--ink-soft);"><?php echo $aid; ?></small>
                                </td>
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                                    $date = sprintf('%s-%02d', $selectedMonth, $day);
                                    $key = $aid . '_' . $date;
                                    $dayOfWeek = date('N', strtotime($date));
                                    $att = $attendanceData[$key] ?? null;
                                    
                                    if ($att) {
                                        $status = $att['attendance_status'] ?? 'present';
                                    } else {
                                        $status = ($dayOfWeek >= 6) ? 'weekend' : 'not_marked';
                                    }
                                    
                                    $note = $att['attendance_note'] ?? '';
                                    $noteAttr = !empty($note) ? " title='" . htmlspecialchars($note) . "'" : '';
                                ?>
                                <td style="text-align:center;padding:4px;">
                                    <span class="status-cell status-<?php echo $status; ?>"
                                          onclick="openMarkModal('<?php echo $aid; ?>', '<?php echo $date; ?>', '<?php echo $status; ?>', '<?php echo htmlspecialchars(addslashes($note)); ?>')"
                                          <?php echo $noteAttr; ?>>
                                        <?php 
                                            switch ($status) {
                                                case 'present': echo '✓ P'; break;
                                                case 'absent': echo '✗ A'; break;
                                                case 'half_day': echo '½ HD'; break;
                                                case 'holiday': echo '☀ H'; break;
                                                case 'weekend': echo '—'; break;
                                                default: echo '?';
                                            }
                                        ?>
                                    </span>
                                </td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Summary -->
        <div class="tab-content" id="tab-summary">
            <div class="card">
                <h3><i class="fas fa-chart-pie"></i> Monthly Attendance Summary</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th class="center">Present</th>
                                <th class="center">Absent</th>
                                <th class="center">Half Day</th>
                                <th class="center">Holiday</th>
                                <th class="center">Weekend</th>
                                <th class="center">Not Marked</th>
                                <th class="center">Total Working</th>
                                <th class="center">Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $agent): 
                                $aid = $agent['agent_id'];
                                $stats = $agentStats[$aid];
                                $totalWorkingDays = $stats['total_days'] - $stats['weekend'];
                                $attendancePercent = $totalWorkingDays > 0 ? round(($stats['present'] / $totalWorkingDays) * 100) : 0;
                            ?>
                            <tr>
                                <td class="agent-name"><?php echo htmlspecialchars($agent['full_name']); ?></td>
                                <td class="center" style="color:var(--success);font-weight:600;"><?php echo $stats['present']; ?></td>
                                <td class="center" style="color:var(--danger);font-weight:600;"><?php echo $stats['absent']; ?></td>
                                <td class="center" style="color:var(--warn);font-weight:600;"><?php echo $stats['half_day']; ?></td>
                                <td class="center" style="color:var(--info);font-weight:600;"><?php echo $stats['holiday']; ?></td>
                                <td class="center" style="color:#999;"><?php echo $stats['weekend']; ?></td>
                                <td class="center" style="color:#ccc;"><?php echo $stats['not_marked']; ?></td>
                                <td class="center"><strong><?php echo $stats['total_working']; ?></strong></td>
                                <td class="center">
                                    <div style="display:flex;align-items:center;gap:6px;justify-content:center;">
                                        <div style="background:var(--cream);border-radius:10px;height:6px;width:60px;overflow:hidden;">
                                            <div style="height:100%;width:<?php echo $attendancePercent; ?>%;background:<?php echo $attendancePercent >= 80 ? 'var(--success)' : ($attendancePercent >= 60 ? 'var(--warn)' : 'var(--danger)'); ?>;border-radius:10px;"></div>
                                        </div>
                                        <span style="font-size:11px;font-weight:600;"><?php echo $attendancePercent; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark Attendance Modal -->
    <div id="markModal" class="modal">
        <div class="modal-content">
            <h2>📝 Mark Attendance</h2>
            <form method="POST" id="markForm">
                <input type="hidden" name="action" value="mark_attendance">
                <input type="hidden" name="agent_id" id="markAgentId">
                <input type="hidden" name="date" id="markDate">
                <div class="form-group">
                    <label>Agent</label>
                    <input type="text" id="markAgentName" readonly style="background:var(--cream);">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="text" id="markDateDisplay" readonly style="background:var(--cream);">
                </div>
                <div class="form-group">
                    <label>Attendance Status</label>
                    <select name="attendance_status" id="markStatus">
                        <option value="present">✓ Present</option>
                        <option value="absent">✗ Absent</option>
                        <option value="half_day">½ Half Day</option>
                        <option value="holiday">☀ Holiday</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Note (Optional)</label>
                    <textarea name="note" id="markNote" placeholder="Reason for absence, holiday name, etc."></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-confirm">💾 Save</button>
                    <button type="button" class="btn-cancel" onclick="closeMarkModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Mark Modal -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <h2>📦 Bulk Mark Attendance</h2>
            <form method="POST">
                <input type="hidden" name="action" value="mark_bulk">
                <div class="form-group">
                    <label>Agent</label>
                    <select name="bulk_agent_id">
                        <option value="all">All Agents</option>
                        <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo $agent['agent_id']; ?>"><?php echo htmlspecialchars($agent['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="bulk_status">
                        <option value="present">✓ Present</option>
                        <option value="absent">✗ Absent</option>
                        <option value="half_day">½ Half Day</option>
                        <option value="holiday">☀ Holiday</option>
                    </select>
                </div>
                <div style="display:flex;gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>From Day</label>
                        <input type="number" name="from_day" value="1" min="1" max="<?php echo $daysInMonth; ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>To Day</label>
                        <input type="number" name="to_day" value="<?php echo $daysInMonth; ?>" min="1" max="<?php echo $daysInMonth; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Note (Optional)</label>
                    <textarea name="bulk_note" placeholder="e.g., Diwali Holiday, Sick Leave"></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-confirm">💾 Apply to All</button>
                    <button type="button" class="btn-cancel" onclick="closeBulkModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector(`.tab[onclick*="${tab}"]`).classList.add('active');
            document.getElementById('tab-' + tab).classList.add('active');
        }

        // Mark Modal
        function openMarkModal(agentId, date, currentStatus, note) {
            document.getElementById('markAgentId').value = agentId;
            document.getElementById('markDate').value = date;
            document.getElementById('markAgentName').value = agentId;
            document.getElementById('markDateDisplay').value = new Date(date).toDateString();
            document.getElementById('markStatus').value = currentStatus === 'not_marked' ? 'present' : currentStatus;
            document.getElementById('markNote').value = note || '';
            document.getElementById('markModal').classList.add('show');
        }
        function closeMarkModal() {
            document.getElementById('markModal').classList.remove('show');
        }

        // Bulk Modal
        function openBulkModal() {
            document.getElementById('bulkModal').classList.add('show');
        }
        function closeBulkModal() {
            document.getElementById('bulkModal').classList.remove('show');
        }

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('show');
            });
        });
    </script>
</body>
</html>