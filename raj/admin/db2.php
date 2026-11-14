<?php
// /raj/admin/dashboard.php
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
$currentMonth = date('Y-m');
$today = date('Y-m-d');

// Quick stats for dashboard
$totalAgents = 0;
$countQuery = executeQuery("SELECT COUNT(*) as total FROM agents WHERE onboarding_completed = 1");
if ($countQuery && $countQuery->num_rows > 0) $totalAgents = (int)$countQuery->fetch_assoc()['total'];

$teamSales = ['total' => 0];
$ts = executeQuery("SELECT COALESCE(SUM(total_sales),0) as total FROM daily_sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?", [$currentMonth]);
if ($ts && $ts->num_rows > 0) $teamSales = $ts->fetch_assoc();

$pendingSales = 0;
$ps = executeQuery("SELECT COUNT(*) as c FROM daily_sales WHERE status='pending'");
if ($ps && $ps->num_rows > 0) $pendingSales = (int)$ps->fetch_assoc()['c'];

$pendingReports = 0;
$pr = executeQuery("SELECT COUNT(*) as c FROM sales_report_requests WHERE status='pending'");
if ($pr && $pr->num_rows > 0) $pendingReports = (int)$pr->fetch_assoc()['c'];

$pendingCompliance = 0;
$pc = executeQuery("SELECT COUNT(*) as c FROM compliance_agreements WHERE status='pending'");
if ($pc && $pc->num_rows > 0) $pendingCompliance = (int)$pc->fetch_assoc()['c'];

$pendingTraining = 0;
$pt = executeQuery("SELECT COUNT(*) as c FROM training_letters WHERE status='pending'");
if ($pt && $pt->num_rows > 0) $pendingTraining = (int)$pt->fetch_assoc()['c'];

$pendingJoining = 0;
$pj = executeQuery("SELECT COUNT(*) as c FROM joining_letters WHERE status='pending'");
if ($pj && $pj->num_rows > 0) $pendingJoining = (int)$pj->fetch_assoc()['c'];

$pendingAgents = 0;
$pa = executeQuery("SELECT COUNT(*) as c FROM agents WHERE onboarding_completed = 0");
if ($pa && $pa->num_rows > 0) $pendingAgents = (int)$pa->fetch_assoc()['c'];

$totalPending = $pendingSales + $pendingReports + $pendingCompliance + $pendingTraining + $pendingJoining + $pendingAgents;

// Today's attendance
$presentToday = 0; $absentToday = 0;
$attQ = executeQuery("SELECT attendance_status FROM attendance WHERE date=?", [$today]);
if($attQ) while($r=$attQ->fetch_assoc()) {
    if(($r['attendance_status']??'present')==='present') $presentToday++; else $absentToday++;
}

// Current page for active nav
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?php echo APP_NAME; ?></title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --navy-700: #1a3a6e;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-300: #e9c877;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f;
            --warn: #9a6a10; --info: #29538c; --purple: #7c3aed;
            --sidebar-width: 250px;
            --radius: 16px; --radius-sm: 10px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,.05);
            --shadow-md: 0 8px 24px rgba(0,0,0,.1);
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f5f4f0;color:var(--ink);display:flex;min-height:100vh;}

        /* ===== SIDEBAR ===== */
        .sidebar{
            width:var(--sidebar-width); background:linear-gradient(180deg,var(--navy-950),var(--navy-800));
            color:#e0e8f0; position:fixed; top:0; left:0; height:100vh;
            overflow-y:auto; z-index:200; transition:transform .3s;
            display:flex; flex-direction:column;
        }
        .sidebar::-webkit-scrollbar { width:4px; }
        .sidebar::-webkit-scrollbar-track { background:rgba(255,255,255,.05); }
        .sidebar::-webkit-scrollbar-thumb { background:var(--gold-500); border-radius:4px; }

        .sidebar .logo{
            padding:20px; text-align:center; border-bottom:1px solid rgba(255,255,255,.1);
        }
        .sidebar .logo .icon{
            width:48px; height:48px; border-radius:12px;
            background:linear-gradient(135deg,var(--gold-300),var(--gold-500));
            display:inline-flex; align-items:center; justify-content:center;
            font-family:'Fraunces',serif; font-weight:700; font-size:20px; color:var(--navy-950);
        }
        .sidebar .logo h2{font-family:'Fraunces',serif; font-size:16px; color:#fff; margin-top:8px;}
        .sidebar .logo span{font-size:9px; color:var(--gold-300); letter-spacing:2px; text-transform:uppercase;}

        .sidebar .user-info{
            padding:14px 20px; display:flex; align-items:center; gap:10px;
            border-bottom:1px solid rgba(255,255,255,.1);
        }
        .sidebar .user-avatar{
            width:36px; height:36px; border-radius:50%; background:var(--gold-500);
            display:flex; align-items:center; justify-content:center;
            font-weight:700; color:var(--navy-950); font-size:14px;
        }
        .sidebar .user-name{font-size:13px; font-weight:500; color:#fff;}
        .sidebar .user-role{font-size:10px; color:var(--gold-300);}

        .sidebar .nav-section{padding:6px 0;}
        .sidebar .nav-title{
            padding:8px 20px 4px; font-size:9px; text-transform:uppercase;
            letter-spacing:2px; color:rgba(255,255,255,.4); font-weight:600;
        }
        .sidebar .nav-item{
            display:flex; align-items:center; gap:10px; padding:9px 20px;
            color:#c0c8d4; text-decoration:none; font-size:12px; font-weight:500;
            transition:all .2s; border-left:3px solid transparent; margin:1px 0;
        }
        .sidebar .nav-item:hover{background:rgba(255,255,255,.05); color:#fff;}
        .sidebar .nav-item.active{
            background:rgba(201,150,47,.15); color:var(--gold-300);
            border-left-color:var(--gold-500); font-weight:600;
        }
        .sidebar .nav-item i{width:20px; text-align:center; font-size:13px; color:#7a8a9e;}
        .sidebar .nav-item.active i{color:var(--gold-400);}
        .sidebar .nav-item .badge{
            margin-left:auto; background:var(--danger); color:#fff;
            padding:1px 8px; border-radius:10px; font-size:9px; font-weight:700;
        }
        .sidebar .nav-item .badge.warn{background:var(--warn);}
        .sidebar .nav-item .badge.success{background:var(--success);}
        .sidebar .nav-item .badge.info{background:var(--info);}

        .sidebar .sidebar-footer{
            margin-top:auto; padding:14px 20px; border-top:1px solid rgba(255,255,255,.1);
            font-size:10px; color:rgba(255,255,255,.3); text-align:center;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content{
            margin-left:var(--sidebar-width); flex:1; min-height:100vh;
            display:flex; flex-direction:column;
        }
        .topbar{
            background:var(--paper); padding:14px 28px; display:flex;
            justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
            box-shadow:var(--shadow-sm); border-bottom:1px solid var(--line);
            position:sticky; top:0; z-index:100;
        }
        .topbar .menu-toggle{
            display:none; background:none; border:none; font-size:22px;
            cursor:pointer; color:var(--ink);
        }
        .topbar .breadcrumb{font-size:13px; color:var(--ink-soft);}
        .topbar .breadcrumb span{color:var(--navy-950); font-weight:600;}
        .topbar .top-actions{display:flex; gap:8px; align-items:center;}
        .topbar .top-actions a{
            padding:6px 14px; border-radius:20px; font-size:11px; text-decoration:none;
            color:var(--ink-soft); border:1px solid var(--line); transition:all .2s;
        }
        .topbar .top-actions a:hover{background:var(--cream); border-color:var(--gold-500);}

        .content{padding:24px 28px 40px; flex:1;}

        /* KPI Cards */
        .kpi-grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:22px;}
        .kpi-card{
            background:var(--paper); border-radius:var(--radius); padding:18px 20px;
            box-shadow:var(--shadow-sm); border:1px solid var(--line);
            display:flex; align-items:center; gap:14px; cursor:pointer;
            transition:transform .2s,box-shadow .2s;
        }
        .kpi-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
        .kpi-icon{
            width:48px; height:48px; border-radius:12px; display:flex;
            align-items:center; justify-content:center; font-size:18px; flex-shrink:0;
        }
        .kpi-icon.gold{background:linear-gradient(135deg,#fdf1de,#fce8c0);color:var(--gold-500);}
        .kpi-icon.green{background:#e4f4e8;color:var(--success);}
        .kpi-icon.blue{background:#e6eefb;color:var(--info);}
        .kpi-icon.red{background:#fbe7e7;color:var(--danger);}
        .kpi-icon.purple{background:#f3e8ff;color:var(--purple);}
        .kpi-info .kpi-val{font-family:'Fraunces',serif;font-size:24px;font-weight:700;color:var(--navy-950);}
        .kpi-info .kpi-lbl{font-size:10px;color:var(--ink-soft);font-weight:500;text-transform:uppercase;}

        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
        .card{background:var(--paper);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow-sm);border:1px solid var(--line);}
        .card h3{font-family:'Fraunces',serif;font-size:16px;color:var(--navy-950);margin-bottom:14px;display:flex;align-items:center;gap:8px;}

        .quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
        .quick-card{
            background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-sm);
            padding:20px;text-align:center;text-decoration:none;color:var(--ink);
            transition:transform .2s,box-shadow .2s;position:relative;
        }
        .quick-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--gold-500);}
        .quick-card .qc-icon{font-size:28px;margin-bottom:8px;}
        .quick-card .qc-label{font-size:12px;font-weight:600;}
        .quick-card .qc-sub{font-size:10px;color:var(--ink-soft);}
        .quick-card .qc-badge{
            position:absolute;top:8px;right:8px;background:var(--danger);color:#fff;
            padding:2px 8px;border-radius:10px;font-size:9px;font-weight:700;
        }

        .copyright-footer{text-align:center;padding:16px;margin-top:auto;border-top:1px solid var(--line);font-size:11px;color:var(--ink-soft);}
        .copyright-footer a{color:var(--gold-500);text-decoration:none;font-weight:600;}

        @media(max-width:1024px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.open{transform:translateX(0);}
            .main-content{margin-left:0;}
            .topbar .menu-toggle{display:block;}
            .grid-2{grid-template-columns:1fr;}
            .kpi-grid{grid-template-columns:1fr 1fr;}
        }
        @media(max-width:576px){
            .kpi-grid{grid-template-columns:1fr;}
            .topbar{padding:10px 16px;}
            .content{padding:16px;}
            .quick-grid{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <div class="icon">BG</div>
            <h2>BetterGrowth</h2>
            <span>Admin Panel</span>
        </div>

        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($adminName,0,1)); ?></div>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($adminName); ?></div>
                <div class="user-role">Administrator</div>
            </div>
        </div>

        <nav>
            <!-- ===== MAIN ===== -->
            <div class="nav-section">
                <div class="nav-title">Main</div>
                <a href="dashboard.php" class="nav-item <?php echo $currentPage=='dashboard.php'?'active':''; ?>">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="dbsales.php" class="nav-item <?php echo $currentPage=='dbsales.php'?'active':''; ?>">
                    <i class="fas fa-chart-pie"></i> Analytics
                </a>
            </div>

            <!-- ===== AGENTS ===== -->
            <div class="nav-section">
                <div class="nav-title">Agents</div>
                <a href="agents-manage.php" class="nav-item <?php echo $currentPage=='agents-manage.php'?'active':''; ?>">
                    <i class="fas fa-users"></i> All Agents
                    <?php if($pendingAgents>0): ?><span class="badge info"><?php echo $pendingAgents; ?></span><?php endif; ?>
                </a>
                <a href="agent-comparison.php" class="nav-item <?php echo $currentPage=='agent-comparison.php'?'active':''; ?>">
                    <i class="fas fa-balance-scale"></i> Compare Agents
                </a>
                <a href="agent-reports.php" class="nav-item <?php echo $currentPage=='agent-reports.php'?'active':''; ?>">
                    <i class="fas fa-file-alt"></i> Agent Reports
                </a>
            </div>

            <!-- ===== SALES ===== -->
            <div class="nav-section">
                <div class="nav-title">Sales</div>
                <a href="sales-manage.php" class="nav-item <?php echo $currentPage=='sales-manage.php'?'active':''; ?>">
                    <i class="fas fa-chart-line"></i> Sales Management
                    <?php if($pendingSales>0): ?><span class="badge"><?php echo $pendingSales; ?></span><?php endif; ?>
                </a>
                <a href="sales-edit.php" class="nav-item <?php echo $currentPage=='sales-edit.php'?'active':''; ?>">
                    <i class="fas fa-edit"></i> Edit Sales
                </a>
                <a href="targets-manage.php" class="nav-item <?php echo $currentPage=='targets-manage.php'?'active':''; ?>">
                    <i class="fas fa-bullseye"></i> Set Targets
                </a>
                <a href="incentives-manage.php" class="nav-item <?php echo $currentPage=='incentives-manage.php'?'active':''; ?>">
                    <i class="fas fa-gift"></i> Incentives
                </a>
            </div>

            <!-- ===== ATTENDANCE ===== -->
            <div class="nav-section">
                <div class="nav-title">Attendance</div>
                <a href="attendance-manage.php" class="nav-item <?php echo $currentPage=='attendance-manage.php'?'active':''; ?>">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="reports-manage.php" class="nav-item <?php echo $currentPage=='reports-manage.php'?'active':''; ?>">
                    <i class="fas fa-envelope"></i> Email Reports
                    <?php if($pendingReports>0): ?><span class="badge"><?php echo $pendingReports; ?></span><?php endif; ?>
                </a>
            </div>

            <!-- ===== COMPLIANCE & DOCUMENTS ===== -->
            <div class="nav-section">
                <div class="nav-title">Compliance & Docs</div>
                <a href="compliance-manage.php" class="nav-item <?php echo $currentPage=='compliance-manage.php'?'active':''; ?>">
                    <i class="fas fa-file-signature"></i> Compliance
                    <?php if($pendingCompliance>0): ?><span class="badge warn"><?php echo $pendingCompliance; ?></span><?php endif; ?>
                </a>
                <a href="training-letters-manage.php" class="nav-item <?php echo $currentPage=='training-letters-manage.php'?'active':''; ?>">
                    <i class="fas fa-file-alt"></i> Training Letters
                    <?php if($pendingTraining>0): ?><span class="badge warn"><?php echo $pendingTraining; ?></span><?php endif; ?>
                </a>
                <a href="joining-letter-setup.php" class="nav-item <?php echo $currentPage=='joining-letter-setup.php'?'active':''; ?>">
                    <i class="fas fa-file-contract"></i> Joining Letters
                    <?php if($pendingJoining>0): ?><span class="badge warn"><?php echo $pendingJoining; ?></span><?php endif; ?>
                </a>
                <a href="joining-letter-setup.php" class="nav-item <?php echo $currentPage=='joining-letter-setup.php'?'active':''; ?>">
                    <i class="fas fa-pen-fancy"></i> Manage Letters
                    <?php if($pendingJoining>0): ?><span class="badge warn"><?php echo $pendingJoining; ?></span><?php endif; ?>
                </a>
            </div>

            <!-- ===== REPORTS ===== -->
            <div class="nav-section">
                <div class="nav-title">Reports</div>
                <a href="sales-reports.php" class="nav-item <?php echo $currentPage=='sales-reports.php'?'active':''; ?>">
                    <i class="fas fa-file-invoice"></i> Sales Reports
                </a>
                <a href="agent-performance.php" class="nav-item <?php echo $currentPage=='agent-performance.php'?'active':''; ?>">
                    <i class="fas fa-trophy"></i> Performance
                </a>
                <a href="export-data.php" class="nav-item <?php echo $currentPage=='export-data.php'?'active':''; ?>">
                    <i class="fas fa-download"></i> Export Data
                </a>
            </div>

            <!-- ===== SETTINGS ===== -->
            <div class="nav-section">
                <div class="nav-title">Settings</div>
                <a href="profile-setup.php" class="nav-item <?php echo $currentPage=='profile-setup.php'?'active':''; ?>">
                    <i class="fas fa-user-cog"></i> Profile
                </a>
                <a href="settings.php" class="nav-item <?php echo $currentPage=='settings.php'?'active':''; ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="logo-settings.php" class="nav-item <?php echo $currentPage=='logo-settings.php'?'active':''; ?>">
                    <i class="fas fa-image"></i> Logo & Branding
                </a>
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            © <?php echo date('Y'); ?> Rajvardhan Singh &amp; Family
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div class="breadcrumb">
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </div>
            <div class="top-actions">
                <a href="profile-setup.php"><i class="fas fa-user"></i> Profile</a>
                <a href="../agent/dashboard.php" target="_blank"><i class="fas fa-external-link-alt"></i> Agent View</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <h2 style="font-family:'Fraunces',serif;font-size:24px;color:var(--navy-950);margin-bottom:4px;">
                👋 Welcome, <?php echo htmlspecialchars($adminName); ?>
            </h2>
            <p style="color:var(--ink-soft);font-size:13px;margin-bottom:20px;">
                <?php echo date('l, F d, Y'); ?> | Overview of your team
            </p>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card" onclick="location.href='sales-manage.php'">
                    <div class="kpi-icon gold"><i class="fas fa-chart-bar"></i></div>
                    <div class="kpi-info">
                        <div class="kpi-val">₹<?php echo number_format((int)$teamSales['total']); ?></div>
                        <div class="kpi-lbl">Monthly Sales</div>
                    </div>
                </div>
                <div class="kpi-card" onclick="location.href='agents-manage.php'">
                    <div class="kpi-icon blue"><i class="fas fa-users"></i></div>
                    <div class="kpi-info">
                        <div class="kpi-val"><?php echo $totalAgents; ?></div>
                        <div class="kpi-lbl">Total Agents</div>
                    </div>
                </div>
                <div class="kpi-card" onclick="location.href='attendance-manage.php'">
                    <div class="kpi-icon green"><i class="fas fa-user-check"></i></div>
                    <div class="kpi-info">
                        <div class="kpi-val"><?php echo $presentToday; ?></div>
                        <div class="kpi-lbl">Present Today</div>
                    </div>
                </div>
                <div class="kpi-card" onclick="location.href='compliance-manage.php'">
                    <div class="kpi-icon purple"><i class="fas fa-clock"></i></div>
                    <div class="kpi-info">
                        <div class="kpi-val"><?php echo $totalPending; ?></div>
                        <div class="kpi-lbl">Pending Actions</div>
                    </div>
                </div>
            </div>

            <!-- Quick Access Cards -->
            <div class="card" style="margin-bottom:20px;">
                <h3>⚡ Quick Access</h3>
                <div class="quick-grid">
                    <a href="joining-letter-setup.php" class="quick-card">
                        <div class="qc-icon">📝</div>
                        <div class="qc-label">Joining Letters</div>
                        <div class="qc-sub">Create & manage appointment letters</div>
                        <?php if($pendingJoining>0): ?><span class="qc-badge"><?php echo $pendingJoining; ?></span><?php endif; ?>
                    </a>
                    <a href="compliance-manage.php" class="quick-card">
                        <div class="qc-icon">📋</div>
                        <div class="qc-label">Compliance</div>
                        <div class="qc-sub">Review & approve agreements</div>
                        <?php if($pendingCompliance>0): ?><span class="qc-badge"><?php echo $pendingCompliance; ?></span><?php endif; ?>
                    </a>
                    <a href="training-letters-manage.php" class="quick-card">
                        <div class="qc-icon">📄</div>
                        <div class="qc-label">Training Letters</div>
                        <div class="qc-sub">Training & policy letters</div>
                        <?php if($pendingTraining>0): ?><span class="qc-badge"><?php echo $pendingTraining; ?></span><?php endif; ?>
                    </a>
                    <a href="admin-joining-letter.php" class="quick-card">
                        <div class="qc-icon">💰</div>
                        <div class="qc-label">Admin Joing Letter  </div>
                        <div class="qc-sub">ApproveAdmin Joing Letter </div>
                        <?php if($pendingSales>0): ?><span class="qc-badge"><?php echo $pendingSales; ?></span><?php endif; ?>
                    </a>
                    <a href="view-letter.php" class="quick-card">
                        <div class="qc-icon">🎯</div>
                        <div class="qc-label">view letter page</div>
                        <div class="qc-sub">view letter page</div>
                    </a>
                    <a href="attendance-manage.php" class="quick-card">
                        <div class="qc-icon">📅</div>
                        <div class="qc-label">Attendance</div>
                        <div class="qc-sub">Mark & manage attendance</div>
                    </a>
                    <a href="agents-manage.php" class="quick-card">
                        <div class="qc-icon">👥</div>
                        <div class="qc-label">Manage Agents</div>
                        <div class="qc-sub">Add, edit & manage agents</div>
                        <?php if($pendingAgents>0): ?><span class="qc-badge"><?php echo $pendingAgents; ?></span><?php endif; ?>
                    </a>
                    <a href="settings.php" class="quick-card">
                        <div class="qc-icon">⚙️</div>
                        <div class="qc-label">Settings</div>
                        <div class="qc-sub">System configurations</div>
                    </a>
                </div>
            </div>

            <!-- Recent Activity Placeholder -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                <div class="card">
                    <h3><i class="fas fa-user-plus" style="color:var(--gold-500);"></i> Recent Agents</h3>
                    <?php
                    $recentAgents = executeQuery("SELECT full_name, agent_id, created_at FROM agents ORDER BY created_at DESC LIMIT 5");
                    if($recentAgents && $recentAgents->num_rows > 0): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <?php while($a = $recentAgents->fetch_assoc()): ?>
                        <tr style="border-bottom:1px solid var(--line);">
                            <td style="padding:8px 0;"><strong><?php echo htmlspecialchars($a['full_name']); ?></strong></td>
                            <td style="padding:8px 0;color:var(--ink-soft);font-size:11px;"><?php echo htmlspecialchars($a['agent_id']); ?></td>
                            <td style="padding:8px 0;color:var(--ink-soft);font-size:11px;text-align:right;"><?php echo date('d M', strtotime($a['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </table>
                    <?php else: ?>
                    <p style="color:var(--ink-soft);text-align:center;padding:16px;">No agents registered yet.</p>
                    <?php endif; ?>
                    <a href="agents-manage.php" style="display:inline-block;margin-top:10px;font-size:12px;color:var(--gold-500);text-decoration:none;">View All →</a>
                </div>

                <div class="card">
                    <h3><i class="fas fa-file-contract" style="color:var(--gold-500);"></i> Recent Letters</h3>
                    <?php
                    $recentLetters = executeQuery("
                        SELECT jl.*, a.full_name 
                        FROM joining_letters jl 
                        LEFT JOIN agents a ON jl.agent_id = a.agent_id 
                        ORDER BY jl.created_at DESC LIMIT 5
                    ");
                    if($recentLetters && $recentLetters->num_rows > 0): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <?php while($l = $recentLetters->fetch_assoc()): 
                            $status = $l['status'] ?? 'pending';
                            $color = $status === 'approved' ? 'var(--success)' : ($status === 'rejected' ? 'var(--danger)' : 'var(--warn)');
                        ?>
                        <tr style="border-bottom:1px solid var(--line);">
                            <td style="padding:8px 0;"><strong><?php echo htmlspecialchars($l['full_name'] ?? 'N/A'); ?></strong></td>
                            <td style="padding:8px 0;color:<?php echo $color; ?>;font-size:11px;"><?php echo ucfirst($status); ?></td>
                            <td style="padding:8px 0;color:var(--ink-soft);font-size:11px;text-align:right;"><?php echo date('d M', strtotime($l['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </table>
                    <?php else: ?>
                    <p style="color:var(--ink-soft);text-align:center;padding:16px;">No joining letters yet.</p>
                    <?php endif; ?>
                    <a href="joining-letter-steup.php" style="display:inline-block;margin-top:10px;font-size:12px;color:var(--gold-500);text-decoration:none;">View All →</a>
                </div>
            </div>
        </div>

        <div class="copyright-footer">
            Copyright &copy; <?php echo date('Y'); ?> <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; BGS. All Rights Reserved.
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        // Close sidebar on outside click (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.menu-toggle');
            if (window.innerWidth <= 1024 && !sidebar.contains(e.target) && e.target !== toggle) {
                sidebar.classList.remove('open');
            }
        });
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('sidebar').classList.remove('open');
            }
        });
    </script>
</body>
</html>