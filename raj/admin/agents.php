<?php
// /raj/admin/agents.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$sql = "SELECT * FROM agents WHERE 1=1";
$params = [];
$types = '';

if (!empty($search)) {
    $sql .= " AND (agent_id LIKE ? OR full_name LIKE ? OR email LIKE ? OR mobile LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= 'ssss';
}

if ($statusFilter === 'pending') {
    $sql .= " AND onboarding_completed = 0";
} elseif ($statusFilter === 'completed') {
    $sql .= " AND onboarding_completed = 1";
} elseif ($statusFilter === 'verified') {
    $sql .= " AND verification_status = 'verified'";
} elseif ($statusFilter === 'rejected') {
    $sql .= " AND verification_status = 'rejected'";
} elseif ($statusFilter === 'correction') {
    $sql .= " AND verification_status = 'correction_requested'";
}

$sql .= " ORDER BY created_at DESC";

$agents = executeQuery($sql, $params, $types);

// Helper: initials for avatar chips
function bgsInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }
    return $initials;
}

// Get counts for badges
$totalCount = 0;
$pendingCount = 0;
$verifiedCount = 0;
$rejectedCount = 0;

$countResult = executeQuery("SELECT COUNT(*) as count FROM agents");
if ($countResult !== false && $countResult->num_rows > 0) {
    $row = $countResult->fetch_assoc();
    $totalCount = $row['count'] ?? 0;
}

$countResult = executeQuery("SELECT COUNT(*) as count FROM agents WHERE onboarding_completed = 0");
if ($countResult !== false && $countResult->num_rows > 0) {
    $row = $countResult->fetch_assoc();
    $pendingCount = $row['count'] ?? 0;
}

$countResult = executeQuery("SELECT COUNT(*) as count FROM agents WHERE verification_status = 'verified'");
if ($countResult !== false && $countResult->num_rows > 0) {
    $row = $countResult->fetch_assoc();
    $verifiedCount = $row['count'] ?? 0;
}

$countResult = executeQuery("SELECT COUNT(*) as count FROM agents WHERE verification_status = 'rejected'");
if ($countResult !== false && $countResult->num_rows > 0) {
    $row = $countResult->fetch_assoc();
    $rejectedCount = $row['count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Agents - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
    
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38;
            --navy-900: #10254a;
            --navy-800: #163063;
            --navy-700: #1c3d7a;
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
            min-height: 100vh;
        }

        .admin-wrapper { display: flex; min-height: 100vh; }

        /* ============ SIDEBAR ============ */
        .sidebar {
            width: 264px;
            background:
                radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
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
        .sidebar-brand .logo-mark {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-brand .logo-mark .logo-fallback {
            display: flex;
            height: 40px; width: 40px;
            border-radius: 10px;
            align-items: center; justify-content: center;
            font-family: 'Fraunces', serif;
            font-weight: 700; font-size: 18px;
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
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            color: rgba(233,238,250,.68);
            text-decoration: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            position: relative;
            transition: background .25s var(--ease), color .25s var(--ease);
        }
        .sidebar-nav a .icon {
            width: 20px; height: 20px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
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
        .sidebar-nav .nav-divider {
            height: 1px;
            background: rgba(255,255,255,.08);
            margin: 12px 14px;
        }
        .sidebar-nav .logout { color: #f2a3a0; }
        .sidebar-nav .logout:hover { background: rgba(240,90,80,.12); color: #ffb3b0; }
        .sidebar-nav .badge-count {
            margin-left: auto;
            background: rgba(255,255,255,.12);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        /* ============ MAIN CONTENT ============ */
        .main-content {
            margin-left: 264px;
            padding: 26px 32px 50px;
            flex: 1;
            min-width: 0;
        }

        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .main-header .heading-group { display: flex; align-items: center; gap: 14px; }
        .menu-toggle {
            display: none;
            width: 38px; height: 38px;
            border-radius: 9px;
            border: 1px solid var(--line);
            background: var(--paper);
            align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 18px;
            color: var(--ink);
        }
        .main-header h1 {
            font-family: 'Fraunces', serif;
            font-weight: 600;
            color: var(--navy-950);
            font-size: 26px;
            letter-spacing: -.2px;
        }
        .main-header .subtitle { color: var(--ink-soft); font-size: 13.5px; margin-top: 2px; }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .admin-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--paper);
            border: 1px solid var(--line);
            padding: 6px 14px 6px 6px;
            border-radius: 999px;
            box-shadow: var(--shadow-sm);
        }
        .admin-chip .avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            color: var(--navy-950);
            font-weight: 700; font-size: 12.5px;
            display: flex; align-items: center; justify-content: center;
        }
        .admin-chip .name { font-size: 13.5px; font-weight: 600; color: var(--ink); }
        .admin-chip .role { font-size: 11px; color: var(--ink-soft); }

        /* ============ FILTER BAR ============ */
        .filter-bar {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            background: var(--paper);
            padding: 16px 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            align-items: center;
        }
        .filter-bar .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            flex: 1;
        }
        .filter-bar input,
        .filter-bar select {
            padding: 10px 16px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: var(--cream);
            color: var(--ink);
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease);
            min-width: 180px;
        }
        .filter-bar input:focus,
        .filter-bar select:focus {
            border-color: var(--gold-500);
            outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }
        .filter-bar .btn {
            padding: 10px 22px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
            font-family: 'Inter', sans-serif;
        }
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
        }
        .btn-gold:hover {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-400));
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: var(--cream);
            color: var(--ink-soft);
            border: 2px solid var(--line);
        }
        .btn-secondary:hover {
            background: var(--line);
            transform: translateY(-2px);
        }
        .btn-primary {
            background: var(--navy-800);
            color: white;
        }
        .btn-primary:hover {
            background: var(--navy-700);
            transform: translateY(-2px);
        }

        /* ============ TABLE ============ */
        .table-container {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 22px 22px 8px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            overflow-x: auto;
        }
        .table-container .table-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .table-container h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 19px; font-weight: 600;
        }
        .table-container .view-all {
            font-size: 13px; font-weight: 600; color: var(--gold-500);
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px;
            transition: gap .2s var(--ease);
        }
        .table-container .view-all:hover { gap: 8px; }

        table { width: 100%; border-collapse: collapse; min-width: 780px; }
        th {
            text-align: left;
            padding: 12px 14px;
            background: transparent;
            color: var(--ink-soft);
            font-weight: 600;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }
        td { padding: 14px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 14px; }
        tbody tr { transition: background .2s var(--ease); }
        tbody tr:hover { background: #faf9f6; }
        tbody tr:last-child td { border-bottom: none; }

        .agent-cell { display: flex; align-items: center; gap: 10px; }
        .agent-cell .avatar-sm {
            width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
            background: var(--navy-900);
            color: var(--gold-300);
            font-size: 12px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .agent-cell .agent-name { font-weight: 600; color: var(--ink); }
        .agent-cell .agent-id { font-size: 12px; color: var(--ink-soft); }

        .muted { color: var(--ink-soft); }

        .badge {
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }
        .badge-success { background: var(--success-bg); color: var(--success-ink); }
        .badge-success::before { background: var(--success-ink); }
        .badge-warning { background: var(--warn-bg); color: var(--warn-ink); }
        .badge-warning::before { background: var(--warn-ink); }
        .badge-danger { background: var(--danger-bg); color: var(--danger-ink); }
        .badge-danger::before { background: var(--danger-ink); }
        .badge-info { background: var(--info-bg); color: var(--info-ink); }
        .badge-info::before { background: var(--info-ink); }
        .badge-secondary { background: #eef0f2; color: #55606e; }
        .badge-secondary::before { background: #55606e; }

        .btn {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--navy-800); color: white; }
        .btn-primary:hover { background: var(--navy-700); transform: translateY(-1px); }
        .btn-success { background: var(--success-ink); color: white; }
        .btn-success:hover { background: #156b34; transform: translateY(-1px); }
        .btn-danger { background: var(--danger-ink); color: white; }
        .btn-danger:hover { background: #992b26; transform: translateY(-1px); }
        .btn-sm { padding: 4px 10px; font-size: 11px; }
        .btn-outline {
            background: transparent;
            color: var(--navy-800);
            border: 1.5px solid var(--navy-800);
        }
        .btn-outline:hover { background: var(--navy-800); color: white; }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--ink-soft);
        }
        .empty-state i { font-size: 48px; opacity: 0.3; display: block; margin-bottom: 16px; }
        .empty-state a { color: var(--gold-500); font-weight: 600; text-decoration: none; }
        .empty-state a:hover { text-decoration: underline; }

        /* ============ RESPONSIVE ============ */
        .sidebar-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(12,26,56,.45);
            z-index: 30;
        }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
            .filter-bar { flex-direction: column; }
            .filter-bar .filter-group { flex-direction: column; }
            .filter-bar input, .filter-bar select { min-width: unset; width: 100%; }
            .filter-bar .btn { width: 100%; justify-content: center; }
            .main-header { flex-direction: column; align-items: flex-start; }
        }

        @media (max-width: 480px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .sidebar-nav .badge-count { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition-duration: 0.001ms !important; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar Backdrop -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Sidebar -->
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
                <a href="dashboard.php">
                    <span class="icon"><i class="fas fa-th-large"></i></span>
                    <span>Dashboard</span>
                </a>
                <a href="create-agent.php">
                    <span class="icon"><i class="fas fa-user-plus"></i></span>
                    <span>Create Agent</span>
                </a>
                <a href="agents.php" class="active">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                    <span class="badge-count"><?php echo $totalCount; ?></span>
                </a>
                <div class="nav-divider"></div>
                <a href="agents.php?status=pending">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Pending Verification</span>
                    <span class="badge-count"><?php echo $pendingCount; ?></span>
                </a>
                <a href="agents.php?status=verified">
                    <span class="icon"><i class="fas fa-check-circle"></i></span>
                    <span>Verified Agents</span>
                    <span class="badge-count"><?php echo $verifiedCount; ?></span>
                </a>
                <a href="agents.php?status=rejected">
                    <span class="icon"><i class="fas fa-times-circle"></i></span>
                    <span>Rejected</span>
                    <span class="badge-count"><?php echo $rejectedCount; ?></span>
                </a>
                <div class="nav-divider"></div>
                <a href="logout.php" class="logout">
                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="main-header">
                <div class="heading-group">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>All Agents</h1>
                        <div class="subtitle">Manage and view all registered agents</div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="create-agent.php" class="btn btn-primary" style="padding:10px 22px;">
                        <i class="fas fa-plus"></i> New Agent
                    </a>
                    <div class="admin-chip">
                        <div class="avatar">AD</div>
                        <div>
                            <div class="name">Admin</div>
                            <div class="role">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="get" style="display:flex; gap:14px; flex-wrap:wrap; width:100%; align-items:center;">
                    <div class="filter-group">
                        <input type="text" name="search" placeholder="Search by ID, Name, Email, Mobile..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <select name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending Onboarding</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Onboarding Completed</option>
                            <option value="verified" <?php echo $statusFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="correction" <?php echo $statusFilter === 'correction' ? 'selected' : ''; ?>>Correction Requested</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-gold">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="agents.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Table -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-users" style="color:var(--gold-500); margin-right:8px;"></i>Agent List</h2>
                    <span style="color:var(--ink-soft); font-size:13px;">
                        <?php 
                            $count = ($agents && $agents->num_rows > 0) ? $agents->num_rows : 0;
                            echo $count . ' agent' . ($count !== 1 ? 's' : '') . ' found';
                        ?>
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>Created</th>
                            <th>Onboarding</th>
                            <th>Verification</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($agents && $agents->num_rows > 0): ?>
                            <?php while ($row = $agents->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="agent-cell">
                                        <div class="avatar-sm"><?php echo htmlspecialchars(bgsInitials($row['full_name'])); ?></div>
                                        <div>
                                            <div class="agent-name"><?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?></div>
                                            <div class="agent-id">#<?php echo htmlspecialchars($row['agent_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="muted"><?php echo htmlspecialchars($row['mobile'] ?: 'N/A'); ?></td>
                                <td class="muted"><?php echo htmlspecialchars($row['email'] ?: 'N/A'); ?></td>
                                <td class="muted"><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <span class="badge <?php echo $row['onboarding_completed'] ? 'badge-success' : 'badge-warning'; ?>">
                                        <?php echo $row['onboarding_completed'] ? 'Completed' : 'Pending'; ?>
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
                                    <?php if ($row['onboarding_completed'] && $row['verification_status'] !== 'verified'): ?>
                                        <a href="verify-agent.php?id=<?php echo $row['agent_id']; ?>&action=approve" 
                                           class="btn btn-success btn-sm" 
                                           onclick="return confirm('Approve this agent?')">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>No agents found matching your criteria.</p>
                                        <p style="margin-top:10px;">
                                            <a href="create-agent.php" style="color:var(--gold-500); font-weight:600;">
                                                <i class="fas fa-plus"></i> Create your first agent
                                            </a>
                                        </p>
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
        // Mobile sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuToggle = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('show');
        }

        menuToggle?.addEventListener('click', toggleSidebar);
        backdrop?.addEventListener('click', toggleSidebar);

        // Close sidebar on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                toggleSidebar();
            }
        });

        // Smooth hover effect for table rows
        document.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#faf9f6';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
    </script>
</body>
</html>