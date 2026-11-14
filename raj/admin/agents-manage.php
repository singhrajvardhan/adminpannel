<?php
// /raj/admin/agents-manage.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

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

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $agentId = $_POST['agent_id'] ?? '';
        $newStatus = $_POST['status'] ?? 'active';
        executeQuery("UPDATE agents SET status = ? WHERE agent_id = ?", [$newStatus, $agentId]);
        $message = "Agent status updated!";
        $msgType = 'success';
    }
    
    if ($_POST['action'] === 'add_agent') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $shiftType = $_POST['shift_type'] ?? 'day';
        $workLocation = $_POST['work_location'] ?? 'office';
        $department = trim($_POST['department'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $joiningDate = $_POST['joining_date'] ?? date('Y-m-d');
        
        // Validate
        $errors = [];
        if (empty($fullName)) $errors[] = 'Name is required';
        if (empty($email)) $errors[] = 'Email is required';
        if (empty($mobile)) $errors[] = 'Mobile is required';
        if (empty($password)) $errors[] = 'Password is required';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
        
        // Check existing
        $existingEmail = executeQuery("SELECT agent_id FROM agents WHERE email = ?", [$email]);
        if ($existingEmail && $existingEmail->num_rows > 0) $errors[] = 'Email already exists';
        
        $existingMobile = executeQuery("SELECT agent_id FROM agents WHERE mobile = ?", [$mobile]);
        if ($existingMobile && $existingMobile->num_rows > 0) $errors[] = 'Mobile already exists';
        
        if (empty($errors)) {
            // Generate agent ID
            $agentId = 'AGT' . strtoupper(substr(md5(uniqid()), 0, 6));
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Create storage directory
            $storageDir = __DIR__ . '/../storage/agents/' . $agentId;
            if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);
            
            $result = executeQuery(
                "INSERT INTO agents (agent_id, full_name, email, mobile, password, shift_type, work_location, department, designation, joining_date, status, onboarding_completed) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1)",
                [$agentId, $fullName, $email, $mobile, $hashedPassword, $shiftType, $workLocation, $department, $designation, $joiningDate]
            );
            
            if ($result !== false) {
                // Auto-create target for current month
                executeQuery(
                    "INSERT INTO sales_targets (agent_id, month_year, monthly_target, working_days, daily_target) 
                     VALUES (?, ?, 0, 22, 0)",
                    [$agentId, $currentMonth]
                );
                
                addAuditLog($adminId, 'agent_added', "Added agent: $fullName ($agentId)");
                $message = "Agent $fullName added successfully! Agent ID: $agentId";
                $msgType = 'success';
            } else {
                $message = "Failed to add agent";
                $msgType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $msgType = 'error';
        }
    }
}

// Get all agents with stats
$agents = [];
$agentsQuery = executeQuery("
    SELECT 
        a.*,
        COALESCE(s.total_sales, 0) as month_sales,
        COALESCE(t.monthly_target, 0) as monthly_target,
        COALESCE(t.credit_score_target, 0) as credit_score_target,
        (SELECT COUNT(*) FROM attendance att 
         WHERE att.agent_id = a.agent_id 
         AND DATE_FORMAT(att.date, '%Y-%m') = '$currentMonth' 
         AND att.attendance_status = 'present') as present_days,
        (SELECT COUNT(*) FROM attendance att 
         WHERE att.agent_id = a.agent_id 
         AND DATE_FORMAT(att.date, '%Y-%m') = '$currentMonth' 
         AND att.attendance_status = 'absent') as absent_days,
        (SELECT COUNT(*) FROM attendance att 
         WHERE att.agent_id = a.agent_id 
         AND DATE_FORMAT(att.date, '%Y-%m') = '$currentMonth' 
         AND att.attendance_status = 'holiday') as holiday_days
    FROM agents a
    LEFT JOIN (
        SELECT agent_id, SUM(total_sales) as total_sales 
        FROM daily_sales 
        WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$currentMonth'
        GROUP BY agent_id
    ) s ON a.agent_id = s.agent_id
    LEFT JOIN sales_targets t ON a.agent_id = t.agent_id AND t.month_year = '$currentMonth'
    ORDER BY a.status ASC, a.full_name ASC
");

if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
    }
}

// Stats
$activeCount = count(array_filter($agents, fn($a) => $a['status'] === 'active'));
$inactiveCount = count(array_filter($agents, fn($a) => $a['status'] !== 'active'));
$totalMonthSales = array_sum(array_column($agents, 'month_sales'));

// Calculate working days (excluding Sat/Sun)
function getWorkingDaysInMonth($monthYear) {
    $daysInMonth = date('t', strtotime($monthYear . '-01'));
    $workingDays = 0;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%s-%02d', $monthYear, $d);
        $dayOfWeek = date('N', strtotime($date));
        if ($dayOfWeek < 6) $workingDays++; // Mon-Fri only
    }
    return $workingDays;
}

$workingDaysInMonth = getWorkingDaysInMonth($currentMonth);
$daysPassed = 0;
for ($d = 1; $d <= date('d'); $d++) {
    $date = sprintf('%s-%02d', $currentMonth, $d);
    if (date('N', strtotime($date)) < 6) $daysPassed++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Management - Admin</title>
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
            --radius: 16px; --radius-sm: 10px; --ease: cubic-bezier(.22,.61,.36,1);
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
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; color: #fff; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 7px 15px;
            border-radius: var(--radius-sm); font-size: 12px; font-weight: 500;
            transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.15); }
        .header .nav a.active { color: var(--gold-300); font-weight: 600; }

        .content { max-width: 1400px; margin: 0 auto; padding: 20px 28px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 18px; }

        .alert { padding: 14px 20px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 500; line-height: 1.5; }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        /* Stats Row */
        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px; margin-bottom: 20px;
        }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            display: flex; align-items: center; gap: 16px;
        }
        .stat-card .stat-icon {
            width: 50px; height: 50px; border-radius: 14px; display: flex;
            align-items: center; justify-content: center; font-size: 22px;
        }
        .stat-card .stat-icon.blue { background: #e6eefb; color: var(--info); }
        .stat-card .stat-icon.green { background: #e4f4e8; color: var(--success); }
        .stat-card .stat-icon.gold { background: #fdf1de; color: var(--gold-500); }
        .stat-card .stat-icon.red { background: #fbe7e7; color: var(--danger); }
        .stat-card .stat-icon.purple { background: #f3e8ff; color: #7c3aed; }
        .stat-card .stat-info .stat-value { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--navy-950); }
        .stat-card .stat-info .stat-label { font-size: 12px; color: var(--ink-soft); font-weight: 500; }

        /* Top Bar */
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .top-bar .search-box {
            padding: 10px 18px; border: 2px solid var(--line); border-radius: 50px;
            font-family: 'Inter', sans-serif; font-size: 13px; width: 280px;
            background: var(--paper); transition: border-color .3s;
        }
        .top-bar .search-box:focus { border-color: var(--gold-500); outline: none; }
        .btn-add-agent {
            padding: 12px 24px; background: var(--gold-500); color: var(--navy-950);
            border: none; border-radius: 50px; font-weight: 700; font-size: 13px;
            cursor: pointer; font-family: 'Inter', sans-serif; display: inline-flex;
            align-items: center; gap: 8px; transition: transform .2s, box-shadow .2s;
            text-decoration: none;
        }
        .btn-add-agent:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* Agent Cards Grid */
        .agent-cards-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px; margin-bottom: 20px;
        }
        .agent-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
            cursor: pointer; position: relative; overflow: hidden;
        }
        .agent-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .agent-card.inactive { opacity: 0.6; }
        .agent-card .card-top {
            display: flex; align-items: center; gap: 14px; margin-bottom: 14px;
        }
        .agent-card .agent-photo {
            width: 55px; height: 55px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--gold-500); flex-shrink: 0;
        }
        .agent-card .agent-photo-placeholder {
            width: 55px; height: 55px; border-radius: 50%; background: var(--cream);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: #aaa; border: 2px dashed var(--line); flex-shrink: 0;
        }
        .agent-card .agent-info { flex: 1; min-width: 0; }
        .agent-card .agent-info h3 {
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .agent-card .agent-info .agent-id { font-size: 11px; color: var(--ink-soft); }
        .agent-card .status-badge {
            position: absolute; top: 16px; right: 16px; padding: 4px 12px;
            border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase;
        }
        .agent-card .status-badge.active { background: #e4f4e8; color: var(--success); }
        .agent-card .status-badge.inactive { background: #fbe7e7; color: var(--danger); }

        .agent-card .card-stats {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .agent-card .mini-stat {
            background: var(--cream); padding: 10px 12px; border-radius: var(--radius-sm);
            text-align: center; border: 1px solid var(--line);
        }
        .agent-card .mini-stat .ms-value { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 700; color: var(--navy-950); }
        .agent-card .mini-stat .ms-label { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; }
        .agent-card .mini-stat.gold { border-left: 3px solid var(--gold-500); }
        .agent-card .mini-stat.blue { border-left: 3px solid var(--info); }
        .agent-card .mini-stat.green { border-left: 3px solid var(--success); }
        .agent-card .mini-stat.red { border-left: 3px solid var(--danger); }

        .agent-card .progress-mini { margin-top: 10px; }
        .agent-card .progress-mini .pm-bar {
            background: var(--cream); border-radius: 20px; height: 7px; overflow: hidden;
        }
        .agent-card .progress-mini .pm-fill {
            height: 100%; background: linear-gradient(90deg, var(--gold-300), var(--gold-500));
            border-radius: 20px; transition: width .5s ease;
        }
        .agent-card .progress-mini .pm-text {
            display: flex; justify-content: space-between; font-size: 10px;
            color: var(--ink-soft); margin-top: 4px;
        }

        .agent-card .card-actions {
            display: flex; gap: 6px; margin-top: 12px; flex-wrap: wrap;
        }
        .btn-xs {
            padding: 5px 12px; border: none; border-radius: 20px; font-size: 10px;
            font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 4px; transition: transform .2s;
        }
        .btn-xs:hover { transform: translateY(-1px); }
        .btn-view { background: var(--info); color: #fff; }
        .btn-toggle { background: var(--cream); color: var(--ink-soft); border: 1px solid var(--line); }
        .btn-danger-xs { background: var(--danger); color: #fff; }

        /* Modal */
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 28px;
            max-width: 600px; width: 94%; max-height: 85vh; overflow-y: auto;
            box-shadow: 0 20px 45px rgba(12,26,56,.14);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .form-group { margin-bottom: 12px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft);
            margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 2px solid var(--line);
            border-radius: var(--radius-sm); font-family: 'Inter', sans-serif; font-size: 13px;
            background: var(--paper); transition: border-color .3s;
        }
        .form-group input:focus, .form-group select:focus { border-color: var(--gold-500); outline: none; }
        .btn-group { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .btn-primary { padding: 12px 28px; background: var(--navy-800); color: #fff; border: none; border-radius: var(--radius-sm); font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; }
        .btn-secondary { padding: 12px 28px; background: var(--cream); color: var(--ink-soft); border: 2px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; font-family: 'Inter', sans-serif; }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 40px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--ink-soft);
        }
        .empty-state .empty-icon { font-size: 60px; margin-bottom: 12px; }
        .empty-state h3 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); }

        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .agent-cards-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .header { padding: 10px 16px; }
            .header .nav a { padding: 5px 10px; font-size: 11px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">👥 Agent Management</div>
        <div class="nav">
            <a href="db.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="sales-manage.php"><i class="fas fa-chart-line"></i> Sales</a>
            <a href="attendance-manage.php"><i class="fas fa-calendar-check"></i> Attendance</a>
            <a href="agents-manage.php" class="active"><i class="fas fa-users"></i> Agents</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">📋 Agent Management</h1>
        <p class="page-subtitle">Manage all agents, view performance, add new agents</p>

        <?php if (isset($message)): ?>
        <div class="alert alert-<?php echo $msgType ?? 'success'; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo count($agents); ?></div>
                    <div class="stat-label">Total Agents</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $activeCount; ?></div>
                    <div class="stat-label">Active Agents</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($totalMonthSales); ?></div>
                    <div class="stat-label">Total Sales (<?php echo date('M'); ?>)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $daysPassed; ?>/<?php echo $workingDaysInMonth; ?></div>
                    <div class="stat-label">Working Days Passed (Mon-Fri)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo $inactiveCount; ?></div>
                    <div class="stat-label">Inactive Agents</div>
                </div>
            </div>
        </div>

        <!-- Top Bar -->
        <div class="top-bar">
            <input type="text" class="search-box" placeholder="🔍 Search agents by name or ID..." id="searchInput" onkeyup="filterAgents()">
            <a href="javascript:void(0)" class="btn-add-agent" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Add New Agent
            </a>
        </div>

        <!-- Agent Cards -->
        <div class="agent-cards-grid" id="agentCardsGrid">
            <?php foreach ($agents as $agent): 
                $aid = $agent['agent_id'];
                $photoPath = $agent['candidate_photo_path'] ?? '';
                $photoExists = !empty($photoPath) && file_exists(__DIR__ . '/../storage/agents/' . $aid . '/' . $photoPath);
                $photoUrl = $photoExists ? '/raj/api/upload-document.php?action=serve&agent=' . $aid . '&file=' . urlencode($photoPath) : '';
                $target = $agent['monthly_target'] ?? 0;
                $sales = $agent['month_sales'] ?? 0;
                $pct = $target > 0 ? min(100, round(($sales / $target) * 100)) : 0;
                $presentDays = $agent['present_days'] ?? 0;
                $absentDays = $agent['absent_days'] ?? 0;
            ?>
            <div class="agent-card <?php echo $agent['status'] !== 'active' ? 'inactive' : ''; ?>" 
                 data-name="<?php echo strtolower(htmlspecialchars($agent['full_name'])); ?>"
                 data-id="<?php echo strtolower($aid); ?>"
                 onclick="viewAgent('<?php echo $aid; ?>')">
                <span class="status-badge <?php echo $agent['status'] === 'active' ? 'active' : 'inactive'; ?>">
                    <?php echo ucfirst($agent['status'] ?? 'active'); ?>
                </span>
                <div class="card-top">
                    <?php if ($photoExists): ?>
                        <img src="<?php echo $photoUrl; ?>" class="agent-photo" alt="Photo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="agent-photo-placeholder" style="display:none;">👤</div>
                    <?php else: ?>
                        <div class="agent-photo-placeholder">👤</div>
                    <?php endif; ?>
                    <div class="agent-info">
                        <h3><?php echo htmlspecialchars($agent['full_name']); ?></h3>

                        
                        <div class="agent-id"><?php echo $aid; ?> | <?php echo htmlspecialchars($agent['department'] ?? 'General'); ?></div>
                        
                        <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;">
                            <i class="fas <?php echo $agent['shift_type'] === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                            <?php echo ucfirst($agent['shift_type'] ?? 'day'); ?> Shift
                            | <i class="fas <?php echo ($agent['work_location'] ?? 'office') === 'office' ? 'fa-building' : 'fa-home'; ?>"></i>
                            <?php echo ucfirst($agent['work_location'] ?? 'office'); ?>
                        </div>
                    </div>
                </div>

                <div class="card-stats">
                    <div class="mini-stat gold">
                        <div class="ms-value"><?php echo number_format($sales); ?></div>
                        <div class="ms-label">Monthly Sales</div>
                    </div>
                    <div class="mini-stat blue">
                        <div class="ms-value"><?php echo number_format($target); ?></div>
                        <div class="ms-label">Target</div>
                    </div>
                    <div class="mini-stat green">
                        <div class="ms-value"><?php echo $presentDays; ?></div>
                        <div class="ms-label">Present Days</div>
                    </div>
                    <div class="mini-stat red">
                        <div class="ms-value"><?php echo $absentDays; ?></div>
                        <div class="ms-label">Absent Days</div>
                    </div>
                </div>

                <?php if ($target > 0): ?>
                <div class="progress-mini">
                    <div class="pm-bar">
                        <div class="pm-fill" style="width: <?php echo $pct; ?>%;"></div>
                    </div>
                    <div class="pm-text">
                        <span>Target Progress</span>
                        <span><strong><?php echo $pct; ?>%</strong></span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-actions" onclick="event.stopPropagation();">
                    <a href="agent-detail.php?id=<?php echo $aid; ?>" class="btn-xs btn-view">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <button class="btn-xs btn-toggle" onclick="toggleStatus('<?php echo $aid; ?>', '<?php echo $agent['status']; ?>')">
                        <i class="fas fa-power-off"></i> <?php echo $agent['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($agents)): ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <div class="empty-icon">👥</div>
                <h3>No Agents Found</h3>
                <p style="color:var(--ink-soft);">Click "Add New Agent" to get started.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Agent Modal -->
    <div id="addAgentModal" class="modal">
        <div class="modal-content">
            <h2><i class="fas fa-user-plus" style="color:var(--gold-500);"></i> Add New Agent</h2>
            <form method="POST" id="addAgentForm">
                <input type="hidden" name="action" value="add_agent">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" placeholder="John Doe" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" placeholder="john@example.com" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Mobile *</label>
                        <input type="text" name="mobile" placeholder="+91 9876543210" required>
                    </div>
                    <div class="form-group">
                        <label>Password * (min 6 chars)</label>
                        <input type="password" name="password" placeholder="••••••" required minlength="6">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Shift Type</label>
                        <select name="shift_type">
                            <option value="day">☀️ Day Shift</option>
                            <option value="night">🌙 Night Shift</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Work Location</label>
                        <select name="work_location">
                            <option value="office">🏢 Office</option>
                            <option value="home">🏠 Work From Home</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" placeholder="Sales, Support, etc.">
                    </div>
                    <div class="form-group">
                        <label>Designation</label>
                        <input type="text" name="designation" placeholder="Sales Executive">
                    </div>
                </div>
                <div class="form-group">
                    <label>Joining Date</label>
                    <input type="date" name="joining_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-primary">💾 Create Agent</button>
                    <button type="button" class="btn-secondary" onclick="closeAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span class="copyright-owner">Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.</span>
    </div>

    <script>
        // Filter agents
        function filterAgents() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.agent-card').forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const id = card.getAttribute('data-id') || '';
                card.style.display = (name.includes(search) || id.includes(search)) ? '' : 'none';
            });
        }

        // View agent detail
        function viewAgent(agentId) {
            window.location.href = 'agent-detail.php?id=' + agentId;
        }

        // Toggle status
        function toggleStatus(agentId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const action = newStatus === 'active' ? 'Activate' : 'Deactivate';
            if (!confirm(`${action} this agent?`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="agent_id" value="${agentId}">
                <input type="hidden" name="status" value="${newStatus}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Add agent modal
        function openAddModal() {
            document.getElementById('addAgentModal').classList.add('show');
        }
        function closeAddModal() {
            document.getElementById('addAgentModal').classList.remove('show');
        }
        document.getElementById('addAgentModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddModal();
        });
    </script>
</body>
</html>