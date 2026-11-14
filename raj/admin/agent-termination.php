<?php
// /raj/admin/agent-termination.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$message = '';
$msgType = 'success';

// Filter
$filterStatus = $_GET['status'] ?? 'active';
$searchQuery = trim($_GET['search'] ?? '');

// ============================================================
// SAFE AUDIT LOG FUNCTION (prevents FK errors)
// ============================================================
function safeAuditLog($agentId, $action, $details = '') {
    // Check if agent exists first
    $check = executeQuery("SELECT agent_id FROM agents WHERE agent_id = ?", [$agentId]);
    if (!$check || $check->num_rows === 0) {
        // Agent doesn't exist in agents table, try with NULL
        try {
            executeQuery(
                "INSERT INTO audit_log (agent_id, action, details, ip_address, user_agent) 
                 VALUES (NULL, ?, ?, ?, ?)",
                [$action . " ($agentId)", $details, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']
            );
        } catch (Exception $e) {
            error_log("Audit log skip: " . $e->getMessage());
        }
        return false;
    }
    
    try {
        executeQuery(
            "INSERT INTO audit_log (agent_id, action, details, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?)",
            [$agentId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
        return true;
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Terminate/Resign Agent
    if ($action === 'terminate_agent') {
        $agentId = $_POST['agent_id'] ?? '';
        $empStatus = $_POST['employment_status'] ?? 'terminated';
        $termType = $_POST['termination_type'] ?? 'other';
        $termDate = $_POST['termination_date'] ?? date('Y-m-d');
        $lastWorkingDate = $_POST['last_working_date'] ?? date('Y-m-d');
        $reason = trim($_POST['termination_reason'] ?? '');
        $notes = trim($_POST['termination_notes'] ?? '');
        $noticePeriod = isset($_POST['notice_period_served']) ? 1 : 0;
        
        if (empty($agentId)) {
            $message = "Agent ID is required.";
            $msgType = 'error';
        } elseif (empty($reason)) {
            $message = "Reason is required.";
            $msgType = 'error';
        } else {
            $agent = getAgent($agentId);
            
            if (!$agent) {
                $message = "❌ Agent not found in database. Cannot update.";
                $msgType = 'error';
            } else {
                // Update agents table
                $updateResult = executeQuery(
                    "UPDATE agents SET 
                        employment_status = ?,
                        termination_date = ?,
                        termination_type = ?,
                        termination_reason = ?,
                        termination_notes = ?,
                        terminated_by = ?,
                        last_working_date = ?,
                        notice_period_served = ?
                     WHERE agent_id = ?",
                    [$empStatus, $termDate, $termType, $reason, $notes, $adminName, $lastWorkingDate, $noticePeriod, $agentId]
                );
                
                if ($updateResult !== false) {
                    // Insert into history
                    executeQuery(
                        "INSERT INTO agent_termination_history (agent_id, agent_name, action_type, termination_type, termination_date, last_working_date, reason, notes, action_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$agentId, $agent['full_name'] ?? '', $empStatus, $termType, $termDate, $lastWorkingDate, $reason, $notes, $adminName]
                    );
                    
                    // Safe audit log
                    safeAuditLog($agentId, 'agent_' . $empStatus, "Agent marked as $empStatus. Reason: $reason");
                    
                    $message = "✅ Agent marked as <strong>" . ucfirst($empStatus) . "</strong> successfully!";
                    $msgType = 'success';
                } else {
                    $message = "❌ Failed to update agent status. Please try again.";
                    $msgType = 'error';
                }
            }
        }
    }
    
    // Reinstate Agent
    if ($action === 'reinstate_agent') {
        $agentId = $_POST['agent_id'] ?? '';
        $notes = trim($_POST['reinstate_notes'] ?? '');
        
        if (!empty($agentId)) {
            $agent = getAgent($agentId);
            
            if (!$agent) {
                $message = "❌ Agent not found.";
                $msgType = 'error';
            } else {
                executeQuery(
                    "UPDATE agents SET 
                        employment_status = 'active',
                        termination_date = NULL,
                        termination_type = NULL,
                        termination_reason = NULL,
                        termination_notes = NULL,
                        terminated_by = NULL,
                        last_working_date = NULL,
                        notice_period_served = 0
                     WHERE agent_id = ?",
                    [$agentId]
                );
                
                executeQuery(
                    "INSERT INTO agent_termination_history (agent_id, agent_name, action_type, reason, notes, action_by) 
                     VALUES (?, ?, 'reinstated', 'Agent reinstated', ?, ?)",
                    [$agentId, $agent['full_name'] ?? '', $notes, $adminName]
                );
                
                safeAuditLog($agentId, 'agent_reinstated', "Agent reinstated");
                $message = "✅ Agent reinstated successfully!";
                $msgType = 'success';
            }
        }
    }
}

// ============================================================
// GET COUNTS - using COALESCE to handle NULL employment_status
// ============================================================
$countsQuery = executeQuery("
    SELECT 
        SUM(CASE WHEN COALESCE(employment_status, 'active') = 'active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN employment_status = 'resigned' THEN 1 ELSE 0 END) as resigned_count,
        SUM(CASE WHEN employment_status = 'terminated' THEN 1 ELSE 0 END) as terminated_count,
        SUM(CASE WHEN employment_status = 'absconded' THEN 1 ELSE 0 END) as absconded_count,
        SUM(CASE WHEN employment_status = 'on_hold' THEN 1 ELSE 0 END) as on_hold_count,
        COUNT(*) as total_count
    FROM agents WHERE onboarding_completed = 1
");
$counts = ['active_count'=>0,'resigned_count'=>0,'terminated_count'=>0,'absconded_count'=>0,'on_hold_count'=>0,'total_count'=>0];
if ($countsQuery && $countsQuery->num_rows > 0) {
    $row = $countsQuery->fetch_assoc();
    $counts = array_map(function($v) { return (int)($v ?? 0); }, $row);
}

// ============================================================
// BUILD FILTER QUERY
// ============================================================
$whereConditions = ["a.onboarding_completed = 1"];
$params = [];

if ($filterStatus === 'active') {
    $whereConditions[] = "COALESCE(a.employment_status, 'active') = 'active'";
} elseif (in_array($filterStatus, ['resigned', 'terminated', 'absconded', 'on_hold'])) {
    $whereConditions[] = "a.employment_status = ?";
    $params[] = $filterStatus;
}

if (!empty($searchQuery)) {
    $whereConditions[] = "(a.full_name LIKE ? OR a.agent_id LIKE ? OR a.email LIKE ? OR a.mobile LIKE ?)";
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSQL = implode(' AND ', $whereConditions);

$agentsQuery = executeQuery("
    SELECT 
        a.agent_id, a.full_name, a.email, a.mobile, a.candidate_photo_path,
        a.designation, a.department, a.joining_date,
        a.employment_status, a.termination_date, a.termination_type,
        a.termination_reason, a.termination_notes, a.terminated_by,
        a.last_working_date, a.notice_period_served,
        a.salary
    FROM agents a
    WHERE $whereSQL
    ORDER BY 
        CASE COALESCE(a.employment_status, 'active')
            WHEN 'active' THEN 1
            WHEN 'on_hold' THEN 2
            WHEN 'resigned' THEN 3
            WHEN 'absconded' THEN 4
            WHEN 'terminated' THEN 5
            ELSE 6
        END,
        a.termination_date DESC,
        a.full_name ASC
", $params);

$agents = [];
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
    }
}

// ============================================================
// GET TERMINATION HISTORY
// ============================================================
$historyQuery = executeQuery("
    SELECT * FROM agent_termination_history 
    ORDER BY action_at DESC 
    LIMIT 30
");
$history = [];
if ($historyQuery) {
    while ($row = $historyQuery->fetch_assoc()) {
        $history[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Termination - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --navy-700: #1c407a;
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

        .content { max-width: 1400px; margin: 0 auto; padding: 22px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 20px; }

        .alert { padding: 14px 20px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 500; }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 22px; }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 18px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            text-align: center; cursor: pointer; transition: all .3s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card.active-tab { border: 2px solid var(--gold-500); background: #fffdf8; }
        .stat-card .stat-icon { font-size: 22px; margin-bottom: 4px; }
        .stat-card .stat-value { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 700; color: var(--navy-950); }
        .stat-card .stat-label { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; letter-spacing: .5px; }
        .stat-card.green { border-top: 4px solid var(--success); }
        .stat-card.blue { border-top: 4px solid var(--info); }
        .stat-card.red { border-top: 4px solid var(--danger); }
        .stat-card.warn { border-top: 4px solid var(--warn); }
        .stat-card.gray { border-top: 4px solid #999; }

        .search-bar { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .search-bar input {
            flex: 1; min-width: 220px; padding: 12px 18px; border: 2px solid var(--line);
            border-radius: 25px; font-family: 'Inter', sans-serif; font-size: 13px;
            background: var(--paper); transition: border-color .3s;
        }
        .search-bar input:focus { border-color: var(--gold-500); outline: none; }
        .search-bar button {
            padding: 12px 24px; background: var(--navy-800); color: #fff;
            border: none; border-radius: 25px; font-weight: 600; cursor: pointer;
            font-family: 'Inter', sans-serif; font-size: 13px;
        }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); margin-bottom: 20px;
        }
        .card h3 { font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .card h3 i { color: var(--gold-500); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 900px; }
        th { background: var(--navy-800); color: #fff; padding: 12px 14px; text-align: left; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
        td { padding: 12px 14px; border-bottom: 1px solid var(--line); }
        tr:hover { background: var(--cream); }
        tr.terminated-row { background: #fff5f5; }
        tr.resigned-row { background: #fffdf5; }
        tr.absconded-row { background: #fff0f0; }
        tr.onhold-row { background: #f0f4f8; }

        .mini-photo { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--gold-500); }
        .mini-photo-placeholder { width: 34px; height: 34px; border-radius: 50%; background: var(--cream); display: inline-flex; align-items: center; justify-content: center; font-size: 15px; border: 2px solid var(--line); }

        .status-badge {
            padding: 4px 12px; border-radius: 15px; font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;
        }
        .status-badge.active { background: #e4f4e8; color: var(--success); }
        .status-badge.resigned { background: #fdf1de; color: var(--warn); }
        .status-badge.terminated { background: #fbe7e7; color: var(--danger); }
        .status-badge.absconded { background: #f0e6ff; color: #7c3aed; }
        .status-badge.on_hold { background: #e6eefb; color: var(--info); }

        .btn {
            padding: 8px 16px; border: none; border-radius: 20px; font-weight: 600;
            font-size: 11px; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 4px; transition: all .2s;
            text-decoration: none;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-outline { background: var(--paper); color: var(--ink-soft); border: 2px solid var(--line); }
        .btn-sm { padding: 5px 12px; font-size: 10px; }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.75); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 28px;
            max-width: 550px; width: 92%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 50px rgba(12,26,56,.2);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .modal-content h2 i { color: var(--danger); }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 2px solid var(--line); border-radius: 8px;
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--gold-500); outline: none; background: #fffdf8;
        }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .checkbox-group {
            display: flex; align-items: center; gap: 8px; padding: 10px 14px;
            background: var(--cream); border-radius: 8px; cursor: pointer;
            font-size: 12px; font-weight: 500;
        }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--gold-500); }

        .btn-group { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
        .btn-group .btn { padding: 12px 24px; font-size: 13px; }

        .history-list { max-height: 400px; overflow-y: auto; }
        .history-item {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 12px 14px; border-bottom: 1px solid var(--line);
            font-size: 12px; gap: 10px;
        }
        .history-item:hover { background: var(--cream); }
        .history-item .history-icon {
            width: 32px; height: 32px; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;
        }
        .history-icon.terminated { background: #fbe7e7; color: var(--danger); }
        .history-icon.resigned { background: #fdf1de; color: var(--warn); }
        .history-icon.absconded { background: #f0e6ff; color: #7c3aed; }
        .history-icon.reinstated { background: #e4f4e8; color: var(--success); }
        .history-icon.on_hold { background: #e6eefb; color: var(--info); }
        .history-item .history-info { flex: 1; }
        .history-item .history-title { font-weight: 600; color: var(--navy-950); margin-bottom: 2px; }
        .history-item .history-desc { color: var(--ink-soft); font-size: 11px; }
        .history-item .history-time { color: #aaa; font-size: 10px; white-space: nowrap; }

        .empty-state { text-align: center; padding: 50px 20px; color: var(--ink-soft); }
        .empty-state i { font-size: 50px; display: block; margin-bottom: 10px; color: #ddd; }
        .empty-state h3 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); margin-bottom: 6px; }

        .copyright-footer { text-align: center; padding: 20px; margin-top: 30px; border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft); }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media(max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .form-row { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 8px 10px; font-size: 10px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">👤 Agent Termination Management</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="agents-manage.php">Agents</a>
            <a href="agent-termination.php" class="active">Termination</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">👤 Agent Termination & Resignation</h1>
        <p class="page-subtitle">Manage agent employment status without deleting their data</p>

        <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Filter Stats -->
        <div class="stats-row">
            <div class="stat-card green <?php echo $filterStatus === 'active' ? 'active-tab' : ''; ?>" onclick="location.href='?status=active'">
                <div class="stat-icon">✅</div>
                <div class="stat-value"><?php echo $counts['active_count']; ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card warn <?php echo $filterStatus === 'resigned' ? 'active-tab' : ''; ?>" onclick="location.href='?status=resigned'">
                <div class="stat-icon">📤</div>
                <div class="stat-value"><?php echo $counts['resigned_count']; ?></div>
                <div class="stat-label">Resigned</div>
            </div>
            <div class="stat-card red <?php echo $filterStatus === 'terminated' ? 'active-tab' : ''; ?>" onclick="location.href='?status=terminated'">
                <div class="stat-icon">❌</div>
                <div class="stat-value"><?php echo $counts['terminated_count']; ?></div>
                <div class="stat-label">Terminated</div>
            </div>
            <div class="stat-card gray <?php echo $filterStatus === 'absconded' ? 'active-tab' : ''; ?>" onclick="location.href='?status=absconded'">
                <div class="stat-icon">🚫</div>
                <div class="stat-value"><?php echo $counts['absconded_count']; ?></div>
                <div class="stat-label">Absconded</div>
            </div>
            <div class="stat-card blue <?php echo $filterStatus === 'on_hold' ? 'active-tab' : ''; ?>" onclick="location.href='?status=on_hold'">
                <div class="stat-icon">⏸️</div>
                <div class="stat-value"><?php echo $counts['on_hold_count']; ?></div>
                <div class="stat-label">On Hold</div>
            </div>
            <div class="stat-card <?php echo $filterStatus === 'all' ? 'active-tab' : ''; ?>" onclick="location.href='?status=all'">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $counts['total_count']; ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>

        <!-- Search -->
        <form class="search-bar" method="GET">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
            <input type="text" name="search" placeholder="🔍 Search by name, ID, email, or phone..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            <button type="submit">🔍 Search</button>
            <?php if(!empty($searchQuery)): ?>
            <a href="?status=<?php echo $filterStatus; ?>" class="btn btn-outline" style="padding:12px 24px;border-radius:25px;font-size:13px;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Agents Table -->
        <div class="card">
            <h3>
                <i class="fas fa-list"></i> 
                <?php 
                    if ($filterStatus === 'active') echo 'Active Employees';
                    elseif ($filterStatus === 'resigned') echo 'Resigned Employees';
                    elseif ($filterStatus === 'terminated') echo 'Terminated Employees';
                    elseif ($filterStatus === 'absconded') echo 'Absconded Employees';
                    elseif ($filterStatus === 'on_hold') echo 'On Hold Employees';
                    else echo 'All Employees';
                ?>
                (<?php echo count($agents); ?>)
            </h3>

            <?php if(empty($agents)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No Employees Found</h3>
                <p>No employees match the current filter.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Contact</th>
                            <th>Joining Date</th>
                            <th>Status</th>
                            <th>Termination Date</th>
                            <th>Reason</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($agents as $ag): 
                            $empStatus = $ag['employment_status'] ?? 'active';
                            if (empty($empStatus)) $empStatus = 'active';
                            
                            $photoPath = $ag['candidate_photo_path'] ?? '';
                            $photoUrl = '';
                            if(!empty($photoPath)) {
                                $fullPath = __DIR__ . '/../storage/agents/' . $ag['agent_id'] . '/' . $photoPath;
                                if(file_exists($fullPath)) {
                                    $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $ag['agent_id'] . '&file=' . urlencode($photoPath);
                                }
                            }
                            $rowClass = '';
                            if ($empStatus === 'terminated') $rowClass = 'terminated-row';
                            elseif ($empStatus === 'resigned') $rowClass = 'resigned-row';
                            elseif ($empStatus === 'absconded') $rowClass = 'absconded-row';
                            elseif ($empStatus === 'on_hold') $rowClass = 'onhold-row';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <?php if(!empty($photoUrl)): ?>
                                        <img src="<?php echo $photoUrl; ?>" class="mini-photo" alt="Photo">
                                    <?php else: ?>
                                        <span class="mini-photo-placeholder">👤</span>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo htmlspecialchars($ag['full_name']); ?></strong>
                                        <br><small style="color:var(--ink-soft);"><?php echo $ag['agent_id']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($ag['email'] ?? 'N/A'); ?>
                                <br><small><?php echo htmlspecialchars($ag['mobile'] ?? 'N/A'); ?></small>
                            </td>
                            <td><?php echo !empty($ag['joining_date']) ? date('d M Y', strtotime($ag['joining_date'])) : 'N/A'; ?></td>
                            <td>
                                <span class="status-badge <?php echo $empStatus; ?>">
                                    <?php 
                                        if($empStatus === 'active') echo '✅ Active';
                                        elseif($empStatus === 'resigned') echo '📤 Resigned';
                                        elseif($empStatus === 'terminated') echo '❌ Terminated';
                                        elseif($empStatus === 'absconded') echo '🚫 Absconded';
                                        elseif($empStatus === 'on_hold') echo '⏸️ On Hold';
                                        else echo ucfirst($empStatus);
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php echo !empty($ag['termination_date']) ? date('d M Y', strtotime($ag['termination_date'])) : '—'; ?>
                            </td>
                            <td style="max-width:200px;">
                                <?php echo !empty($ag['termination_reason']) ? htmlspecialchars(substr($ag['termination_reason'], 0, 60)) . (strlen($ag['termination_reason']) > 60 ? '...' : '') : '—'; ?>
                            </td>
                            <td>
                                <?php if($empStatus === 'active'): ?>
                                    <button class="btn btn-danger btn-sm" onclick="openTerminateModal('<?php echo $ag['agent_id']; ?>', '<?php echo htmlspecialchars(addslashes($ag['full_name'])); ?>')">
                                        ⛔ Terminate
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-success btn-sm" onclick="openReinstateModal('<?php echo $ag['agent_id']; ?>', '<?php echo htmlspecialchars(addslashes($ag['full_name'])); ?>')">
                                        ♻️ Reinstate
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Termination History -->
        <div class="card">
            <h3><i class="fas fa-history"></i> Recent Activity (Last 30 Records)</h3>
            <?php if(empty($history)): ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h3>No History Yet</h3>
                <p>Termination and resignation activity will appear here.</p>
            </div>
            <?php else: ?>
            <div class="history-list">
                <?php foreach($history as $h): 
                    $actionIcon = '📋';
                    $actionClass = $h['action_type'] ?? 'other';
                    if($actionClass === 'terminated') $actionIcon = '❌';
                    elseif($actionClass === 'resigned') $actionIcon = '📤';
                    elseif($actionClass === 'absconded') $actionIcon = '🚫';
                    elseif($actionClass === 'reinstated') $actionIcon = '♻️';
                    elseif($actionClass === 'on_hold') $actionIcon = '⏸️';
                ?>
                <div class="history-item">
                    <div class="history-icon <?php echo $actionClass; ?>"><?php echo $actionIcon; ?></div>
                    <div class="history-info">
                        <div class="history-title">
                            <strong><?php echo htmlspecialchars($h['agent_name'] ?? $h['agent_id']); ?></strong>
                            <span style="color:var(--ink-soft);font-weight:400;"> — <?php echo ucfirst($h['action_type']); ?></span>
                        </div>
                        <div class="history-desc">
                            <?php if(!empty($h['reason'])): ?>
                                <i class="fas fa-quote-left"></i> <?php echo htmlspecialchars(substr($h['reason'], 0, 100)); ?>
                            <?php endif; ?>
                            <br><small>By: <?php echo htmlspecialchars($h['action_by'] ?? 'Admin'); ?></small>
                        </div>
                    </div>
                    <div class="history-time">
                        <?php echo !empty($h['action_at']) ? date('d M Y', strtotime($h['action_at'])) : ''; ?><br>
                        <?php echo !empty($h['action_at']) ? date('h:i A', strtotime($h['action_at'])) : ''; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Terminate Modal -->
    <div id="terminateModal" class="modal">
        <div class="modal-content">
            <h2><i class="fas fa-user-slash"></i> Terminate / Mark Employee</h2>
            <form method="POST" id="terminateForm">
                <input type="hidden" name="action" value="terminate_agent">
                <input type="hidden" name="agent_id" id="terminateAgentId">

                <div class="form-group">
                    <label>Employee</label>
                    <input type="text" id="terminateAgentName" readonly style="background:var(--cream);font-weight:600;">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Employment Status *</label>
                        <select name="employment_status" id="empStatus" required>
                            <option value="resigned">📤 Resigned</option>
                            <option value="terminated">❌ Terminated</option>
                            <option value="absconded">🚫 Absconded</option>
                            <option value="on_hold">⏸️ On Hold</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Termination Type *</label>
                        <select name="termination_type" required>
                            <option value="resignation">Resignation (Voluntary)</option>
                            <option value="termination">Termination (Company Decision)</option>
                            <option value="absconding">Absconding</option>
                            <option value="contract_end">Contract End</option>
                            <option value="retirement">Retirement</option>
                            <option value="performance">Poor Performance</option>
                            <option value="misconduct">Misconduct</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Termination Date *</label>
                        <input type="date" name="termination_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Working Date *</label>
                        <input type="date" name="last_working_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Reason *</label>
                    <textarea name="termination_reason" placeholder="Enter the reason for termination/resignation..." required></textarea>
                </div>

                <div class="form-group">
                    <label>Additional Notes</label>
                    <textarea name="termination_notes" placeholder="Any additional notes (optional)"></textarea>
                </div>

                <label class="checkbox-group">
                    <input type="checkbox" name="notice_period_served" value="1">
                    <span>Notice period was served (30 days)</span>
                </label>

                <div class="btn-group">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-user-slash"></i> Confirm Termination</button>
                    <button type="button" class="btn btn-outline" onclick="closeTerminateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reinstate Modal -->
    <div id="reinstateModal" class="modal">
        <div class="modal-content">
            <h2 style="color:var(--success);"><i class="fas fa-redo"></i> Reinstate Employee</h2>
            <form method="POST">
                <input type="hidden" name="action" value="reinstate_agent">
                <input type="hidden" name="agent_id" id="reinstateAgentId">

                <div class="form-group">
                    <label>Employee</label>
                    <input type="text" id="reinstateAgentName" readonly style="background:var(--cream);font-weight:600;">
                </div>

                <div class="alert alert-success" style="margin-bottom:14px;">
                    <strong>✅ Note:</strong> All previous data (attendance, sales, documents) will be restored. The employee will be marked as <strong>Active</strong> again.
                </div>

                <div class="form-group">
                    <label>Reinstatement Notes</label>
                    <textarea name="reinstate_notes" placeholder="Reason for reinstatement (optional)"></textarea>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-success"><i class="fas fa-redo"></i> Reinstate Employee</button>
                    <button type="button" class="btn btn-outline" onclick="closeReinstateModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        function openTerminateModal(agentId, name) {
            document.getElementById('terminateAgentId').value = agentId;
            document.getElementById('terminateAgentName').value = name + ' (' + agentId + ')';
            document.getElementById('terminateModal').classList.add('show');
        }
        function closeTerminateModal() {
            document.getElementById('terminateModal').classList.remove('show');
        }

        function openReinstateModal(agentId, name) {
            document.getElementById('reinstateAgentId').value = agentId;
            document.getElementById('reinstateAgentName').value = name + ' (' + agentId + ')';
            document.getElementById('reinstateModal').classList.add('show');
        }
        function closeReinstateModal() {
            document.getElementById('reinstateModal').classList.remove('show');
        }

        document.getElementById('terminateForm')?.addEventListener('submit', function(e) {
            const status = document.getElementById('empStatus').value;
            if (!confirm('⚠️ Are you sure you want to mark this employee as ' + status.toUpperCase() + '?\n\nThis will NOT delete any data. All records will be preserved.\n\nThe employee will no longer appear in active lists.')) {
                e.preventDefault();
            }
        });

        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('show'); });
        });
    </script>
</body>
</html>