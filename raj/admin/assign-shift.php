<?php
// /raj/admin/assign-shift.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$error = '';
$success = '';
$agentId = $_GET['id'] ?? '';

// Get all agents for dropdown
$allAgents = executeQuery("SELECT agent_id, full_name FROM agents ORDER BY full_name ASC");

// Get selected agent details
$agent = null;
if ($agentId) {
    $agent = getAgent($agentId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agentId = $_POST['agent_id'] ?? '';
    $shiftType = $_POST['shift_type'] ?? 'day';
    $workLocation = $_POST['work_location'] ?? 'office';
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (empty($agentId)) {
        $error = 'Please select an agent.';
    } else {
        $updateSql = "UPDATE agents SET 
            shift_type = ?,
            work_location = ?,
            shift_assigned_by = 'admin',
            shift_assigned_at = NOW(),
            shift_updated_at = NOW()
            WHERE agent_id = ?";
        $result = executeQuery($updateSql, [$shiftType, $workLocation, $agentId]);
        
        if ($result !== false) {
            addAuditLog($agentId, 'shift_assigned', "Shift: $shiftType, Location: $workLocation");
            $success = "Shift assigned successfully to agent <strong>$agentId</strong>";
            // Refresh agent data
            $agent = getAgent($agentId);
        } else {
            $error = 'Failed to assign shift. Please try again.';
        }
    }
}

$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Shift - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
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

        .admin-wrapper { display: flex; min-height: 100vh; }

        .sidebar {
            width: 264px;
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 22px 0 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 40;
            transition: transform .35s var(--ease);
            border-right: 1px solid rgba(255,255,255,.06);
        }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 10px; }
        .sidebar-brand {
            padding: 4px 22px 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            margin-bottom: 18px;
        }
        .sidebar-brand .logo-mark { display: flex; align-items: center; gap: 12px; }
        .sidebar-brand .logo-mark .logo-fallback {
            display: flex; height: 40px; width: 40px; border-radius: 10px;
            align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 18px;
            color: var(--navy-950);
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-brand .brand-text {
            font-family: 'Fraunces', serif;
            font-size: 15px;
            line-height: 1.25;
        }
        .sidebar-brand .brand-text b { color: #fff; font-weight: 600; display:block; font-size: 16px; }
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
        .sidebar-nav a.active {
            background: linear-gradient(90deg, rgba(201,150,47,.22), rgba(201,150,47,.05));
            color: #fff;
        }
        .sidebar-nav a.active::before {
            content: '';
            position: absolute; left: -12px; top: 6px; bottom: 6px; width: 3px;
            border-radius: 3px;
            background: linear-gradient(180deg, var(--gold-300), var(--gold-500));
        }
        .sidebar-nav .nav-divider { height: 1px; background: rgba(255,255,255,.08); margin: 12px 14px; }
        .sidebar-nav .logout { color: #f2a3a0; }
        .sidebar-nav .logout:hover { background: rgba(240,90,80,.12); color: #ffb3b0; }

        .main-content {
            margin-left: 264px;
            padding: 26px 32px 50px;
            flex: 1;
            min-width: 0;
        }
        .main-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; flex-wrap: wrap; gap: 16px;
        }
        .main-header .heading-group { display: flex; align-items: center; gap: 14px; }
        .menu-toggle {
            display: none; width: 38px; height: 38px; border-radius: 9px;
            border: 1px solid var(--line); background: var(--paper);
            align-items: center; justify-content: center; cursor: pointer;
            font-size: 18px; color: var(--ink);
        }
        .main-header h1 {
            font-family: 'Fraunces', serif; font-weight: 600;
            color: var(--navy-950); font-size: 26px; letter-spacing: -.2px;
        }
        .main-header .subtitle { color: var(--ink-soft); font-size: 13.5px; margin-top: 2px; }

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

        .form-container {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 30px 32px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            max-width: 700px;
        }
        .form-container .form-header {
            margin-bottom: 24px;
        }
        .form-container .form-header h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 22px;
        }
        .form-container .form-header p {
            color: var(--ink-soft);
            font-size: 14px;
            margin-top: 4px;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; font-weight: 600; font-size: 13px;
            color: var(--ink); margin-bottom: 6px;
        }
        .form-group label .required { color: var(--danger-ink); margin-left: 2px; }
        .form-group select, .form-group input {
            width: 100%; padding: 11px 15px;
            border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-size: 14px; font-family: 'Inter', sans-serif;
            background: var(--cream); color: var(--ink);
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease);
        }
        .form-group select:focus, .form-group input:focus {
            border-color: var(--gold-500); outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }

        .btn {
            padding: 11px 28px; border: none; border-radius: var(--radius-sm);
            font-size: 14px; font-weight: 600; cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s, box-shadow .2s, background .2s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            box-shadow: 0 2px 8px rgba(201,150,47,.3);
        }
        .btn-gold:hover { box-shadow: 0 6px 20px rgba(201,150,47,.35); }
        .btn-secondary {
            background: var(--cream); color: var(--ink-soft);
            border: 2px solid var(--line);
        }
        .btn-secondary:hover { background: var(--line); }

        .agent-info-box {
            background: var(--cream);
            border-radius: var(--radius-sm);
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid var(--line);
        }
        .agent-info-box .row {
            display: flex; padding: 4px 0;
            font-size: 14px;
        }
        .agent-info-box .row .label {
            width: 120px; color: var(--ink-soft); font-weight: 500;
        }
        .agent-info-box .row .value { font-weight: 600; color: var(--ink); }

        .alert {
            padding: 14px 18px; border-radius: var(--radius-sm);
            margin-bottom: 16px; display: flex; align-items: flex-start;
            gap: 10px; font-size: 14px;
        }
        .alert i { font-size: 18px; margin-top: 1px; flex-shrink: 0; }
        .alert-success {
            background: var(--success-bg); color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }
        .alert-error {
            background: var(--danger-bg); color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }

        .btn-group { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
        }

        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .form-container { padding: 20px; }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="sidebar" id="sidebar">
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
                <a href="dashboard.php">
                    <span class="icon"><i class="fas fa-th-large"></i></span>
                    <span>Dashboard</span>
                </a>
                <a href="create-agent.php">
                    <span class="icon"><i class="fas fa-user-plus"></i></span>
                    <span>Create Agent</span>
                </a>
                <a href="agents.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                </a>
                <div class="nav-divider"></div>
                <a href="assign-shift.php" class="active">
                    <span class="icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Assign Shift</span>
                </a>
                <a href="agent-daily-report.php">
                    <span class="icon"><i class="fas fa-file-alt"></i></span>
                    <span>Daily Report</span>
                </a>
                <div class="nav-divider"></div>
                <a href="logout.php" class="logout">
                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </nav>
        </div>

        <div class="main-content">
            <div class="main-header">
                <div class="heading-group">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>Assign Shift</h1>
                        <div class="subtitle">Assign day/night shift and work location to agents</div>
                    </div>
                </div>
                <div class="admin-chip">
                    <div class="avatar">AD</div>
                    <div>
                        <div class="name">Admin</div>
                        <div class="role">Administrator</div>
                    </div>
                </div>
            </div>

            <div class="form-container">
                <div class="form-header">
                    <h2><i class="fas fa-calendar-alt" style="color:var(--gold-500);margin-right:10px;"></i>Shift Assignment</h2>
                    <p>Select an agent and assign shift type and work location</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrfField(); ?>

                    <div class="form-group">
                        <label for="agent_id">Select Agent <span class="required">*</span></label>
                        <select name="agent_id" id="agent_id" onchange="this.form.submit()" required>
                            <option value="">-- Select Agent --</option>
                            <?php while ($row = $allAgents->fetch_assoc()): ?>
                                <option value="<?php echo $row['agent_id']; ?>" <?php echo $agentId == $row['agent_id'] ? 'selected' : ''; ?>>
                                    <?php echo $row['agent_id']; ?> - <?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <?php if ($agent && $agentId): ?>
                    <div class="agent-info-box">
                        <div class="row">
                            <span class="label">Agent ID:</span>
                            <span class="value"><?php echo htmlspecialchars($agent['agent_id']); ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Full Name:</span>
                            <span class="value"><?php echo htmlspecialchars($agent['full_name'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Email:</span>
                            <span class="value"><?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Current Shift:</span>
                            <span class="value">
                                <span class="badge <?php echo ($agent['shift_type'] ?? 'day') === 'day' ? 'badge-info' : 'badge-warning'; ?>">
                                    <?php echo ucfirst($agent['shift_type'] ?? 'Day'); ?>
                                </span>
                            </span>
                        </div>
                        <div class="row">
                            <span class="label">Current Location:</span>
                            <span class="value">
                                <span class="badge <?php echo ($agent['work_location'] ?? 'office') === 'office' ? 'badge-info' : 'badge-success'; ?>">
                                    <?php echo ucfirst($agent['work_location'] ?? 'Office'); ?>
                                </span>
                            </span>
                        </div>
                        <?php if (!empty($agent['shift_assigned_at'])): ?>
                        <div class="row">
                            <span class="label">Last Assigned:</span>
                            <span class="value" style="font-size:13px;color:var(--ink-soft);">
                                <?php echo date('d M Y h:i A', strtotime($agent['shift_assigned_at'])); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="shift_type">Shift Type <span class="required">*</span></label>
                        <select name="shift_type" id="shift_type">
                            <option value="day" <?php echo ($agent['shift_type'] ?? 'day') === 'day' ? 'selected' : ''; ?>>🌅 Day Shift</option>
                            <option value="night" <?php echo ($agent['shift_type'] ?? 'day') === 'night' ? 'selected' : ''; ?>>🌙 Night Shift</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="work_location">Work Location <span class="required">*</span></label>
                        <select name="work_location" id="work_location">
                            <option value="office" <?php echo ($agent['work_location'] ?? 'office') === 'office' ? 'selected' : ''; ?>>🏢 Work from Office</option>
                            <option value="home" <?php echo ($agent['work_location'] ?? 'office') === 'home' ? 'selected' : ''; ?>>🏠 Work from Home</option>
                        </select>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-gold">
                            <i class="fas fa-save"></i> Assign Shift
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
        }

        menuToggle?.addEventListener('click', toggleSidebar);
    </script>
</body>
</html>