<?php
// /raj/admin/view-agent.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json-storage.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$agentId = $_GET['id'] ?? '';
if (!$agentId) {
    die('Agent ID required');
}

$agent = getAgent($agentId);
if (!$agent) {
    die('Agent not found');
}

// Get onboarding data
$onboardingData = getOnboardingData($agentId) ?: [];
$auditLog = getAuditLog($agentId);

// Decrypt sensitive data
$panNumber = !empty($agent['pan_number']) ? decryptData($agent['pan_number']) : '';
$aadhaarNumber = !empty($agent['aadhaar_number']) ? decryptData($agent['aadhaar_number']) : '';

// If decryption fails, try raw value
if (empty($panNumber) && !empty($agent['pan_number'])) $panNumber = $agent['pan_number'];
if (empty($aadhaarNumber) && !empty($agent['aadhaar_number'])) $aadhaarNumber = $agent['aadhaar_number'];

// Get location
$locLat = '';
$locLng = '';
$locAccuracy = '';
$locTimestamp = '';

if (isset($onboardingData['latitude']) && isset($onboardingData['longitude'])) {
    $locLat = $onboardingData['latitude'];
    $locLng = $onboardingData['longitude'];
    $locAccuracy = $onboardingData['accuracy'] ?? '';
} elseif (!empty($agent['latitude']) && !empty($agent['longitude'])) {
    $locLat = $agent['latitude'];
    $locLng = $agent['longitude'];
    $locAccuracy = $agent['accuracy'] ?? '';
    $locTimestamp = $agent['location_timestamp'] ?? '';
}

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

// Get shift details
$shiftType = $agent['shift_type'] ?? 'day';
$workLocation = $agent['work_location'] ?? 'office';

// Get status badge class
$statusBadgeClass = getStatusBadgeClass($agent['verification_status']);
$verificationStatusLabel = getStatusLabel($agent['verification_status']);

// Calculate time since creation
$createdAt = new DateTime($agent['created_at']);
$now = new DateTime();
$timeDiff = $createdAt->diff($now);
$timeAgo = '';
if ($timeDiff->y > 0) $timeAgo = $timeDiff->y . 'y ago';
elseif ($timeDiff->m > 0) $timeAgo = $timeDiff->m . 'm ago';
elseif ($timeDiff->d > 0) $timeAgo = $timeDiff->d . 'd ago';
elseif ($timeDiff->h > 0) $timeAgo = $timeDiff->h . 'h ago';
else $timeAgo = $timeDiff->i . 'm ago';

// ============================================================
// EDUCATION DATA HELPER
// ============================================================
function getEduData($agent, $onboardingData, $key) {
    return $agent[$key] ?? $onboardingData[$key] ?? '';
}

// Highest education label
$highestEdu = getEduData($agent, $onboardingData, 'highest_education');
$highestEduLabel = 'Not specified';
$highestEduIcon = 'fa-graduation-cap';
if ($highestEdu === 'post_graduation') { $highestEduLabel = 'Post-Graduation (Master\'s Degree)'; $highestEduIcon = 'fa-user-graduate'; }
elseif ($highestEdu === 'graduation') { $highestEduLabel = 'Graduation (Bachelor\'s Degree)'; $highestEduIcon = 'fa-graduation-cap'; }
elseif ($highestEdu === '12th') { $highestEduLabel = '12th Standard (HSC / Intermediate)'; $highestEduIcon = 'fa-book'; }
elseif ($highestEdu === '10th') { $highestEduLabel = '10th Standard (SSC / Matriculation)'; $highestEduIcon = 'fa-school'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Profile - <?php echo htmlspecialchars($agent['full_name'] ?? $agentId); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --navy-950: #0c1a38; --navy-900: #10254a; --navy-800: #163063; --navy-700: #1c407a;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-300: #e9c877; --gold-100: #f8f0d8;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085; --ink-light: #98a2b3;
            --line: #ecebe6; --success-bg: #e4f4e8; --success-ink: #1e7a41;
            --danger-bg: #fbe7e7; --danger-ink: #b3352f; --warn-bg: #fdf1de; --warn-ink: #9a6a10;
            --info-bg: #e6eefb; --info-ink: #29538c;
            --shadow-sm: 0 1px 2px rgba(12,26,56,.05), 0 1px 3px rgba(12,26,56,.06);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08); --shadow-lg: 0 20px 45px rgba(12,26,56,.14);
            --ease: cubic-bezier(.22,.61,.36,1); --radius: 16px; --radius-sm: 10px; --radius-xs: 6px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; background: var(--cream); color: var(--ink); -webkit-font-smoothing: antialiased; }

        /* SIDEBAR */
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
        .sidebar-brand .brand-text b { color: #fff; font-weight: 600; display:block; font-size: 16px; }
        .sidebar-brand .brand-text span { color: var(--gold-300); font-size: 11px; letter-spacing: 2px; text-transform: uppercase; }
        .sidebar-nav { padding: 6px 12px; display: flex; flex-direction: column; gap: 2px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 11px 14px;
            color: rgba(233,238,250,.68); text-decoration: none; border-radius: 10px;
            font-size: 14px; font-weight: 500; position: relative;
            transition: background .25s var(--ease), color .25s var(--ease);
        }
        .sidebar-nav a .icon { width: 20px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
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
        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(12,26,56,.45); z-index: 30; }

        /* MAIN */
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

        .btn {
            padding: 8px 18px; border: none; border-radius: var(--radius-xs);
            font-size: 13px; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center;
            gap: 6px; transition: all .25s var(--ease); font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--navy-800); color: white; }
        .btn-primary:hover { background: var(--navy-700); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-success { background: var(--success-ink); color: white; }
        .btn-success:hover { background: #155d34; transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-danger { background: var(--danger-ink); color: white; }
        .btn-danger:hover { background: #8f2a25; transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-warning { background: var(--gold-500); color: var(--navy-950); }
        .btn-warning:hover { background: var(--gold-400); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--ink-soft); border: 1.5px solid var(--line); }
        .btn-outline:hover { background: var(--cream); border-color: var(--ink-soft); }
        .btn-sm { padding: 4px 12px; font-size: 11px; }

        .badge {
            padding: 3px 12px; border-radius: 999px; font-size: 11px;
            font-weight: 600; display: inline-flex; align-items: center;
            gap: 4px; white-space: nowrap;
        }
        .badge-success { background: var(--success-bg); color: var(--success-ink); }
        .badge-warning { background: var(--warn-bg); color: var(--warn-ink); }
        .badge-danger { background: var(--danger-bg); color: var(--danger-ink); }
        .badge-info { background: var(--info-bg); color: var(--info-ink); }
        .badge-gold { background: var(--gold-100); color: var(--gold-500); }
        .badge-day { background: rgba(255,193,7,.15); color: #b8860b; }
        .badge-night { background: rgba(23,162,184,.15); color: #0d6f7a; }
        .badge-office { background: rgba(40,167,69,.15); color: #1a7a32; }
        .badge-home { background: rgba(201,150,47,.15); color: #a87a1a; }

        /* PROFILE HEADER */
        .profile-header {
            background: var(--paper); border-radius: var(--radius); padding: 28px 32px;
            margin-bottom: 28px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            display: flex; align-items: center; gap: 28px; flex-wrap: wrap;
            position: relative; overflow: hidden;
        }
        .profile-header::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--gold-300), var(--gold-500), var(--gold-300));
        }
        .profile-photo {
            width: 110px; height: 110px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--gold-500); flex-shrink: 0; background: var(--cream);
        }
        .profile-photo-placeholder {
            width: 110px; height: 110px; border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-100), var(--cream));
            display: flex; align-items: center; justify-content: center;
            font-size: 44px; color: var(--gold-500);
            border: 3px solid var(--gold-300); flex-shrink: 0;
        }
        .profile-info { flex: 1; min-width: 200px; }
        .profile-info .name {
            font-family: 'Fraunces', serif; font-size: 26px; font-weight: 700;
            color: var(--navy-950); display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .profile-info .agent-id { font-size: 13px; color: var(--ink-soft); font-weight: 400; font-family: 'Inter', sans-serif; }
        .profile-info .meta-row { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 8px; }
        .profile-info .meta-row .meta-item { display: flex; align-items: center; gap: 5px; font-size: 13px; color: var(--ink-soft); }
        .profile-info .meta-row .meta-item i { color: var(--gold-500); width: 16px; }
        .profile-info .time-ago { font-size: 12px; color: var(--ink-light); margin-top: 6px; }
        .profile-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-left: auto; }

        /* STATS */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .stat-chip {
            background: var(--paper); border-radius: var(--radius-sm); padding: 14px 18px;
            border: 1px solid var(--line); box-shadow: var(--shadow-sm);
            text-align: center; transition: all .3s var(--ease);
        }
        .stat-chip:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-chip .number { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 700; color: var(--navy-950); }
        .stat-chip .label { font-size: 10px; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .3px; font-weight: 600; margin-top: 2px; }
        .stat-chip .icon { font-size: 16px; display: block; margin-bottom: 2px; }

        /* SECTIONS */
        .section-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .section {
            background: var(--paper); border-radius: var(--radius); padding: 22px 26px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: box-shadow .3s var(--ease);
        }
        .section:hover { box-shadow: var(--shadow-md); }
        .section.full { grid-column: 1 / -1; }
        .section h3 {
            font-family: 'Fraunces', serif; color: var(--navy-950); font-size: 17px; font-weight: 600;
            margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid var(--cream);
            display: flex; align-items: center; gap: 8px;
        }
        .section h3 i { color: var(--gold-500); }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; }
        .info-row { display: flex; padding: 6px 0; border-bottom: 1px solid #f8f7f3; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { color: var(--ink-soft); font-weight: 500; width: 130px; flex-shrink: 0; }
        .info-row .value { color: var(--ink); font-weight: 500; word-break: break-word; }

        /* DOC CARDS */
        .doc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .doc-card {
            background: var(--cream); border-radius: var(--radius-sm);
            padding: 16px 18px; border: 1px solid var(--line);
            transition: all .3s var(--ease);
        }
        .doc-card:hover { border-color: var(--gold-300); background: var(--gold-100); }
        .doc-card .doc-name {
            font-weight: 600; font-size: 13px; color: var(--navy-950);
            display: flex; align-items: center; gap: 6px; margin-bottom: 8px;
        }
        .doc-card .doc-name i { color: var(--gold-500); }
        .doc-card .doc-detail { font-size: 12px; color: var(--ink-soft); margin-top: 4px; line-height: 1.6; }
        .doc-card .doc-detail strong { color: var(--navy-800); font-weight: 600; }
        .doc-card .doc-link {
            display: inline-block; margin-top: 8px; color: var(--info-ink);
            font-weight: 500; font-size: 12px; text-decoration: none;
        }
        .doc-card .doc-link:hover { text-decoration: underline; }

        /* Number badge */
        .num-badge {
            font-family: 'Courier New', monospace;
            font-size: 14px; font-weight: 700;
            letter-spacing: 1.5px;
            padding: 6px 12px; border-radius: 8px;
            display: inline-block; margin-top: 6px;
        }
        .num-badge.pan {
            background: linear-gradient(135deg, #fffdf5, #fdf1de);
            color: var(--navy-950);
            border: 2px solid var(--gold-500);
        }
        .num-badge.aadhaar {
            background: linear-gradient(135deg, #f0f4f8, #e6eefb);
            color: var(--navy-950);
            border: 2px solid var(--info-ink);
        }

        /* PHOTO GALLERY */
        .photo-gallery { display: flex; gap: 14px; flex-wrap: wrap; }
        .photo-gallery .photo-item {
            border: 1px solid var(--line); border-radius: var(--radius-sm);
            overflow: hidden; width: 180px;
            transition: all .3s var(--ease); background: var(--cream);
        }
        .photo-gallery .photo-item:hover {
            transform: scale(1.02); box-shadow: var(--shadow-md);
            border-color: var(--gold-300);
        }
        .photo-gallery .photo-item img { width: 100%; height: 140px; object-fit: cover; display: block; }
        .photo-gallery .photo-item .label {
            font-size: 11px; color: var(--ink-soft); text-align: center;
            padding: 6px 10px; font-weight: 500; background: white;
        }

        /* MAP */
        #locationMap { height: 320px; width: 100%; border-radius: var(--radius-sm); border: 1px solid var(--line); margin-top: 10px; }
        .location-info {
            margin-top: 10px; padding: 12px 16px; background: var(--cream);
            border-radius: var(--radius-sm); display: flex; flex-wrap: wrap; gap: 16px; font-size: 13px;
        }
        .location-info .loc-item { display: flex; align-items: center; gap: 6px; color: var(--ink-soft); }
        .location-info .loc-item strong { color: var(--ink); }
        .no-location {
            padding: 40px 20px; text-align: center; color: var(--ink-soft);
            background: var(--cream); border-radius: var(--radius-sm);
        }
        .no-location .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        /* AUDIT */
        .audit-list { max-height: 320px; overflow-y: auto; }
        .audit-list::-webkit-scrollbar { width: 5px; }
        .audit-list::-webkit-scrollbar-thumb { background: var(--line); border-radius: 10px; }
        .audit-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px solid var(--cream); font-size: 13px;
        }
        .audit-item:last-child { border-bottom: none; }
        .audit-item .action { font-weight: 500; color: var(--navy-950); }
        .audit-item .action .audit-icon { color: var(--gold-500); margin-right: 6px; }
        .audit-item .time { color: var(--ink-light); font-size: 12px; }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
            .section-grid { grid-template-columns: 1fr; }
            .info-grid { grid-template-columns: 1fr; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-actions { margin-left: 0; justify-content: center; width: 100%; }
        }
        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .main-content { padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .profile-info .name { font-size: 22px; }
            .profile-photo, .profile-photo-placeholder { width: 80px; height: 80px; font-size: 32px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .doc-grid { grid-template-columns: 1fr; }
            .photo-gallery .photo-item { width: 100%; max-width: 280px; }
            .header-actions { flex-direction: column; width: 100%; }
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
                <div class="nav-divider"></div>
                <a href="logout.php" class="logout">
                    <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <!-- HEADER -->
            <div class="main-header">
                <div class="heading-group">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>
                            <i class="fas fa-user-circle" style="color:var(--gold-500);font-size:28px;"></i>
                            Agent Profile
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-id-card"></i> 
                            <?php echo htmlspecialchars($agent['full_name'] ?: $agentId); ?> 
                            &middot; Joined <?php echo $timeAgo; ?>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="agents.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="edit-agent.php?agent=<?php echo $agentId; ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="view-agent.php?id=<?php echo $agentId; ?>&export=1" class="btn btn-primary btn-sm">
                        <i class="fas fa-download"></i> Export
                    </a>
                </div>
            </div>

            <!-- PROFILE HEADER -->
            <div class="profile-header">
                <?php if ($photoExists && !empty($photoUrl)): ?>
                    <img src="<?php echo $photoUrl; ?>" alt="Profile Photo" class="profile-photo"
                         onerror="this.style.display='none'; this.parentElement.querySelector('.profile-photo-placeholder').style.display='flex';">
                    <div class="profile-photo-placeholder" style="display:none;">👤</div>
                <?php else: ?>
                    <div class="profile-photo-placeholder">👤</div>
                <?php endif; ?>

                <div class="profile-info">
                    <div class="name">
                        <?php echo htmlspecialchars($agent['full_name'] ?: 'N/A'); ?>
                        <span class="agent-id">#<?php echo htmlspecialchars($agentId); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-item">
                            <i class="fas fa-shield-alt"></i>
                            <span class="badge <?php echo $statusBadgeClass; ?>">
                                <?php echo $verificationStatusLabel; ?>
                            </span>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-circle" style="color:<?php echo ($agent['account_status'] ?? 'active') === 'active' ? '#1e7a41' : '#b3352f'; ?>;font-size:10px;"></i>
                            <?php echo ucfirst($agent['account_status'] ?? 'active'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas <?php echo $shiftType === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                            <span class="badge <?php echo $shiftType === 'day' ? 'badge-day' : 'badge-night'; ?>">
                                <?php echo ucfirst($shiftType); ?> Shift
                            </span>
                        </span>
                        <span class="meta-item">
                            <i class="fas <?php echo $workLocation === 'office' ? 'fa-building' : 'fa-home'; ?>"></i>
                            <span class="badge <?php echo $workLocation === 'office' ? 'badge-office' : 'badge-home'; ?>">
                                <?php echo ucfirst($workLocation); ?>
                            </span>
                        </span>
                    </div>
                    <div class="time-ago">
                        <i class="fas fa-calendar-plus"></i>
                        Created: <?php echo date('d M Y, h:i A', strtotime($agent['created_at'])); ?>
                        <?php if (!empty($agent['submitted_at'])): ?>
                            &middot; Submitted: <?php echo date('d M Y, h:i A', strtotime($agent['submitted_at'])); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-actions">
                    <?php if (!empty($agent['onboarding_completed']) && ($agent['verification_status'] ?? '') !== 'verified'): ?>
                        <a href="verify-agent.php?id=<?php echo $agentId; ?>&action=approve" 
                           class="btn btn-success btn-sm" 
                           onclick="return confirm('✅ Approve this agent?')">
                            <i class="fas fa-check"></i> Approve
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($agent['onboarding_completed']) && ($agent['verification_status'] ?? '') !== 'rejected'): ?>
                        <a href="verify-agent.php?id=<?php echo $agentId; ?>&action=reject" 
                           class="btn btn-danger btn-sm" 
                           onclick="return confirm('❌ Reject this agent?')">
                            <i class="fas fa-times"></i> Reject                        </a>
                    <?php endif; ?>
                    <?php if (!empty($agent['onboarding_completed'])): ?>
                        <a href="verify-agent.php?id=<?php echo $agentId; ?>&action=correction" 
                           class="btn btn-warning btn-sm">
                            <i class="fas fa-edit"></i> Request Correction
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STATS ROW -->
            <div class="stats-row">
                <div class="stat-chip">
                    <span class="icon">📋</span>
                    <div class="number"><?php echo !empty($agent['onboarding_completed']) ? '✅' : '⏳'; ?></div>
                    <div class="label"><?php echo !empty($agent['onboarding_completed']) ? 'Onboarding Complete' : 'Pending'; ?></div>
                </div>
                <div class="stat-chip">
                    <span class="icon">📧</span>
                    <div class="number"><?php echo !empty($agent['email_verified']) ? '✅' : '⏳'; ?></div>
                    <div class="label"><?php echo !empty($agent['email_verified']) ? 'Email Verified' : 'Email Pending'; ?></div>
                </div>
                <div class="stat-chip">
                    <span class="icon">📍</span>
                    <div class="number"><?php echo !empty($locLat) && !empty($locLng) ? '📍' : '❌'; ?></div>
                    <div class="label"><?php echo !empty($locLat) && !empty($locLng) ? 'Location Captured' : 'No Location'; ?></div>
                </div>
                <div class="stat-chip">
                    <span class="icon">📄</span>
                    <div class="number"><?php 
                        $docCount = 0;
                        if (!empty($agent['resume_path'])) $docCount++;
                        if (!empty($agent['pan_image_path'])) $docCount++;
                        if (!empty($agent['aadhaar_front_path']) || !empty($agent['aadhaar_back_path'])) $docCount++;
                        echo $docCount;
                    ?></div>
                    <div class="label">Documents</div>
                </div>
                <div class="stat-chip">
                    <span class="icon">🎓</span>
                    <div class="number"><?php 
                        $eduCount = 0;
                        if (getEduData($agent, $onboardingData, 'edu_10_doc')) $eduCount++;
                        if (getEduData($agent, $onboardingData, 'edu_12_doc')) $eduCount++;
                        if (getEduData($agent, $onboardingData, 'edu_grad_doc')) $eduCount++;
                        if (getEduData($agent, $onboardingData, 'edu_pg_doc')) $eduCount++;
                        echo $eduCount;
                    ?></div>
                    <div class="label">Education Certificates</div>
                </div>
            </div>

            <!-- PERSONAL & CONTACT -->
            <div class="section-grid">
                <div class="section">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-row"><span class="label">Full Name</span><span class="value"><?php echo htmlspecialchars($agent['full_name'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Father's Name</span><span class="value"><?php echo htmlspecialchars($agent['father_name'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Mother's Name</span><span class="value"><?php echo htmlspecialchars($agent['mother_name'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Date of Birth</span><span class="value">
                            <?php 
                                if (!empty($agent['date_of_birth'])) {
                                    $dobRaw = $agent['date_of_birth'];
                                    $dobDecrypted = decryptData($dobRaw);
                                    if (!empty($dobDecrypted) && strtotime($dobDecrypted)) {
                                        echo htmlspecialchars(date('d M Y', strtotime($dobDecrypted)));
                                    } elseif (strtotime($dobRaw)) {
                                        echo htmlspecialchars(date('d M Y', strtotime($dobRaw)));
                                    } else {
                                        echo htmlspecialchars($dobRaw);
                                    }
                                } else { echo 'N/A'; }
                            ?>
                        </span></div>
                    </div>
                </div>

                <div class="section">
                    <h3><i class="fas fa-phone"></i> Contact Information</h3>
                    <div class="info-grid">
                        <div class="info-row">
                            <span class="label">📱 Primary Mobile</span>
                            <span class="value"><strong><?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></strong></span>
                        </div>
                        <div class="info-row">
                            <span class="label">📱 Alternate Mobile 1</span>
                            <span class="value"><strong><?php echo htmlspecialchars($agent['alt_mobile_1'] ?? $onboardingData['alt_mobile_1'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="info-row">
                            <span class="label">📱 Alternate Mobile 2</span>
                            <span class="value"><?php echo htmlspecialchars($agent['alt_mobile_2'] ?? $onboardingData['alt_mobile_2'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">📧 Email</span>
                            <span class="value"><?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email Verified</span>
                            <span class="value">
                                <span class="badge <?php echo !empty($agent['email_verified']) ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo !empty($agent['email_verified']) ? '✅ Verified' : '⏳ Pending'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="label">Current Address</span>
                            <span class="value"><?php echo htmlspecialchars($agent['current_address'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== EDUCATION DETAILS ===== -->
            <div class="section full">
                <h3><i class="fas fa-graduation-cap"></i> Education Details</h3>
                
                <div style="background:linear-gradient(135deg,#fdf8f0,#fffdf5);border:2px solid var(--gold-500);border-radius:var(--radius-sm);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
                    <i class="fas <?php echo $highestEduIcon; ?>" style="font-size:24px;color:var(--gold-500);"></i>
                    <div>
                        <div style="font-size:11px;color:var(--ink-soft);text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Highest Education</div>
                        <div style="font-size:15px;font-weight:700;color:var(--navy-950);"><?php echo $highestEduLabel; ?></div>
                    </div>
                </div>
                
                <div class="doc-grid">
                    <?php 
                    // Post-Graduation
                    $pgCollege = getEduData($agent, $onboardingData, 'edu_pg_college');
                    $pgRoll = getEduData($agent, $onboardingData, 'edu_pg_roll');
                    $pgDegree = getEduData($agent, $onboardingData, 'edu_pg_degree');
                    $pgYear = getEduData($agent, $onboardingData, 'edu_pg_year');
                    $pgPercent = getEduData($agent, $onboardingData, 'edu_pg_percent');
                    $pgDoc = getEduData($agent, $onboardingData, 'edu_pg_doc');
                    
                    // Graduation
                    $gradCollege = getEduData($agent, $onboardingData, 'edu_grad_college');
                    $gradRoll = getEduData($agent, $onboardingData, 'edu_grad_roll');
                    $gradDegree = getEduData($agent, $onboardingData, 'edu_grad_degree');
                    $gradYear = getEduData($agent, $onboardingData, 'edu_grad_year');
                    $gradPercent = getEduData($agent, $onboardingData, 'edu_grad_percent');
                    $gradDoc = getEduData($agent, $onboardingData, 'edu_grad_doc');
                    
                    // 12th
                    $edu12School = getEduData($agent, $onboardingData, 'edu_12_school');
                    $edu12Roll = getEduData($agent, $onboardingData, 'edu_12_roll');
                    $edu12Board = getEduData($agent, $onboardingData, 'edu_12_board');
                    $edu12Year = getEduData($agent, $onboardingData, 'edu_12_year');
                    $edu12Percent = getEduData($agent, $onboardingData, 'edu_12_percent');
                    $edu12Doc = getEduData($agent, $onboardingData, 'edu_12_doc');
                    
                    // 10th
                    $edu10School = getEduData($agent, $onboardingData, 'edu_10_school');
                    $edu10Roll = getEduData($agent, $onboardingData, 'edu_10_roll');
                    $edu10Board = getEduData($agent, $onboardingData, 'edu_10_board');
                    $edu10Year = getEduData($agent, $onboardingData, 'edu_10_year');
                    $edu10Percent = getEduData($agent, $onboardingData, 'edu_10_percent');
                    $edu10Doc = getEduData($agent, $onboardingData, 'edu_10_doc');
                    
                    $hasAnyEdu = false;
                    ?>
                    
                    <?php if (!empty($pgCollege) || !empty($pgDoc)): $hasAnyEdu = true; ?>
                    <div class="doc-card" style="border-left:4px solid #7c3aed;">
                        <div class="doc-name"><i class="fas fa-user-graduate" style="color:#7c3aed;"></i> Post-Graduation (Master's)</div>
                        <?php if (!empty($pgCollege)): ?><div class="doc-detail"><strong>College:</strong> <?php echo htmlspecialchars($pgCollege); ?></div><?php endif; ?>
                        <?php if (!empty($pgRoll)): ?><div class="doc-detail"><strong>Roll No:</strong> <?php echo htmlspecialchars($pgRoll); ?></div><?php endif; ?>
                        <?php if (!empty($pgDegree)): ?><div class="doc-detail"><strong>Degree:</strong> <?php echo htmlspecialchars($pgDegree); ?></div><?php endif; ?>
                        <?php if (!empty($pgYear)): ?><div class="doc-detail"><strong>Year:</strong> <?php echo htmlspecialchars($pgYear); ?></div><?php endif; ?>
                        <?php if (!empty($pgPercent)): ?><div class="doc-detail"><strong>Score:</strong> <?php echo htmlspecialchars($pgPercent); ?></div><?php endif; ?>
                        <?php if (!empty($pgDoc)): ?>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($pgDoc); ?>" target="_blank" class="doc-link">
                                <i class="fas fa-external-link-alt"></i> View Certificate
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);margin-top:6px;">Certificate not uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($gradCollege) || !empty($gradDoc)): $hasAnyEdu = true; ?>
                    <div class="doc-card" style="border-left:4px solid #29538c;">
                        <div class="doc-name"><i class="fas fa-graduation-cap" style="color:#29538c;"></i> Graduation (Bachelor's)</div>
                        <?php if (!empty($gradCollege)): ?><div class="doc-detail"><strong>College:</strong> <?php echo htmlspecialchars($gradCollege); ?></div><?php endif; ?>
                        <?php if (!empty($gradRoll)): ?><div class="doc-detail"><strong>Roll No:</strong> <?php echo htmlspecialchars($gradRoll); ?></div><?php endif; ?>
                        <?php if (!empty($gradDegree)): ?><div class="doc-detail"><strong>Degree:</strong> <?php echo htmlspecialchars($gradDegree); ?></div><?php endif; ?>
                        <?php if (!empty($gradYear)): ?><div class="doc-detail"><strong>Year:</strong> <?php echo htmlspecialchars($gradYear); ?></div><?php endif; ?>
                        <?php if (!empty($gradPercent)): ?><div class="doc-detail"><strong>Score:</strong> <?php echo htmlspecialchars($gradPercent); ?></div><?php endif; ?>
                        <?php if (!empty($gradDoc)): ?>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($gradDoc); ?>" target="_blank" class="doc-link">
                                <i class="fas fa-external-link-alt"></i> View Certificate
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);margin-top:6px;">Certificate not uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($edu12School) || !empty($edu12Doc)): $hasAnyEdu = true; ?>
                    <div class="doc-card" style="border-left:4px solid #1e7a41;">
                        <div class="doc-name"><i class="fas fa-book" style="color:#1e7a41;"></i> 12th Standard (HSC)</div>
                        <?php if (!empty($edu12School)): ?><div class="doc-detail"><strong>School:</strong> <?php echo htmlspecialchars($edu12School); ?></div><?php endif; ?>
                        <?php if (!empty($edu12Roll)): ?><div class="doc-detail"><strong>Roll No:</strong> <?php echo htmlspecialchars($edu12Roll); ?></div><?php endif; ?>
                        <?php if (!empty($edu12Board)): ?><div class="doc-detail"><strong>Board:</strong> <?php echo htmlspecialchars($edu12Board); ?></div><?php endif; ?>
                        <?php if (!empty($edu12Year)): ?><div class="doc-detail"><strong>Year:</strong> <?php echo htmlspecialchars($edu12Year); ?></div><?php endif; ?>
                        <?php if (!empty($edu12Percent)): ?><div class="doc-detail"><strong>Score:</strong> <?php echo htmlspecialchars($edu12Percent); ?></div><?php endif; ?>
                        <?php if (!empty($edu12Doc)): ?>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($edu12Doc); ?>" target="_blank" class="doc-link">
                                <i class="fas fa-external-link-alt"></i> View Marksheet
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);margin-top:6px;">Marksheet not uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($edu10School) || !empty($edu10Doc)): $hasAnyEdu = true; ?>
                    <div class="doc-card" style="border-left:4px solid #c9962f;">
                        <div class="doc-name"><i class="fas fa-school" style="color:#c9962f;"></i> 10th Standard (SSC)</div>
                        <?php if (!empty($edu10School)): ?><div class="doc-detail"><strong>School:</strong> <?php echo htmlspecialchars($edu10School); ?></div><?php endif; ?>
                        <?php if (!empty($edu10Roll)): ?><div class="doc-detail"><strong>Roll No:</strong> <?php echo htmlspecialchars($edu10Roll); ?></div><?php endif; ?>
                        <?php if (!empty($edu10Board)): ?><div class="doc-detail"><strong>Board:</strong> <?php echo htmlspecialchars($edu10Board); ?></div><?php endif; ?>
                        <?php if (!empty($edu10Year)): ?><div class="doc-detail"><strong>Year:</strong> <?php echo htmlspecialchars($edu10Year); ?></div><?php endif; ?>
                        <?php if (!empty($edu10Percent)): ?><div class="doc-detail"><strong>Score:</strong> <?php echo htmlspecialchars($edu10Percent); ?></div><?php endif; ?>
                        <?php if (!empty($edu10Doc)): ?>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($edu10Doc); ?>" target="_blank" class="doc-link">
                                <i class="fas fa-external-link-alt"></i> View Marksheet
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);margin-top:6px;">Marksheet not uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$hasAnyEdu): ?>
                    <div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--ink-light);">
                        <i class="fas fa-graduation-cap" style="font-size:40px;opacity:.3;display:block;margin-bottom:10px;"></i>
                        <p>No education details submitted yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== DOCUMENTS ===== -->
            <div class="section full">
                <h3><i class="fas fa-file-alt"></i> Identity Documents</h3>
                <div class="doc-grid">
                    <!-- PAN Card -->
                    <div class="doc-card" style="border-left:4px solid #c9962f;">
                        <div class="doc-name"><i class="fas fa-id-card" style="color:#c9962f;"></i> PAN Card</div>
                        <?php if (!empty($panNumber)): ?>
                            <div class="doc-detail"><strong>PAN Number:</strong></div>
                            <span class="num-badge pan"><?php echo htmlspecialchars($panNumber); ?></span>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);">Number not available</div>
                        <?php endif; ?>
                        <?php if (!empty($agent['pan_image_path'])): ?>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($agent['pan_image_path']); ?>" 
                               target="_blank" class="doc-link">
                                <i class="fas fa-image"></i> View PAN Image
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);margin-top:6px;">Image not uploaded</div>
                        <?php endif; ?>
                    </div>

                    <!-- Aadhaar Card -->
                    <div class="doc-card" style="border-left:4px solid #29538c;">
                        <div class="doc-name"><i class="fas fa-id-card" style="color:#29538c;"></i> Aadhaar Card</div>
                        <?php if (!empty($aadhaarNumber)): ?>
                            <div class="doc-detail"><strong>Aadhaar Number:</strong></div>
                            <span class="num-badge aadhaar"><?php echo htmlspecialchars($aadhaarNumber); ?></span>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);">Number not available</div>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                            <?php if (!empty($agent['aadhaar_front_path'])): ?>
                                <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($agent['aadhaar_front_path']); ?>" 
                                   target="_blank" class="doc-link">
                                    <i class="fas fa-image"></i> View Front
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($agent['aadhaar_back_path'])): ?>
                                <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($agent['aadhaar_back_path']); ?>" 
                                   target="_blank" class="doc-link">
                                    <i class="fas fa-image"></i> View Back
                                </a>
                            <?php endif; ?>
                            <?php if (empty($agent['aadhaar_front_path']) && empty($agent['aadhaar_back_path'])): ?>
                                <div class="doc-detail" style="color:var(--ink-light);">Images not uploaded</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Resume -->
                    <div class="doc-card" style="border-left:4px solid #1e7a41;">
                        <div class="doc-name"><i class="fas fa-file-pdf" style="color:#1e7a41;"></i> Resume / CV</div>
                        <?php if (!empty($agent['resume_path'])): ?>
                            <div class="doc-detail">Uploaded successfully</div>
                            <a href="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($agent['resume_path']); ?>" 
                               target="_blank" class="doc-link">
                                <i class="fas fa-external-link-alt"></i> View Resume
                            </a>
                        <?php else: ?>
                            <div class="doc-detail" style="color:var(--ink-light);">Not uploaded</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== ADDRESS & LOCATION ===== -->
            <div class="section-grid">
                <div class="section">
                    <h3><i class="fas fa-map-pin"></i> Address Details</h3>
                    <div class="info-grid">
                        <div class="info-row"><span class="label">House/Flat</span><span class="value"><?php echo htmlspecialchars($agent['house_number'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Street</span><span class="value"><?php echo htmlspecialchars($agent['street'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Locality</span><span class="value"><?php echo htmlspecialchars($agent['locality'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Landmark</span><span class="value"><?php echo htmlspecialchars($agent['landmark'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">City</span><span class="value"><?php echo htmlspecialchars($agent['city'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">State</span><span class="value"><?php echo htmlspecialchars($agent['state'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">PIN Code</span><span class="value"><?php echo htmlspecialchars($agent['pincode'] ?: 'N/A'); ?></span></div>
                        <div class="info-row"><span class="label">Country</span><span class="value"><?php echo htmlspecialchars($agent['country'] ?: 'India'); ?></span></div>
                    </div>
                </div>

                <div class="section">
                    <h3><i class="fas fa-location-dot"></i> Captured Location</h3>
                    <?php if (!empty($locLat) && !empty($locLng) && is_numeric($locLat) && is_numeric($locLng)): ?>
                        <div id="locationMap"></div>
                        <div class="location-info">
                            <span class="loc-item">
                                <i class="fas fa-crosshairs" style="color:var(--gold-500);"></i>
                                <strong><?php echo $locLat; ?>, <?php echo $locLng; ?></strong>
                            </span>
                            <?php if (!empty($locAccuracy) && is_numeric($locAccuracy)): ?>
                                <span class="loc-item">
                                    <i class="fas fa-bullseye"></i>
                                    Accuracy: <strong><?php echo htmlspecialchars($locAccuracy); ?>m</strong>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($locTimestamp)): ?>
                                <span class="loc-item">
                                    <i class="fas fa-clock"></i>
                                    Captured: <strong><?php echo date('d M Y, h:i:s A', strtotime($locTimestamp)); ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-location">
                            <span class="icon">📍</span>
                            <p>Location not captured yet.</p>
                            <p style="font-size:12px;color:var(--ink-light);margin-top:4px;">The agent hasn't completed the location verification step.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== HOUSE PHOTOS ===== -->
            <div class="section full">
                <h3><i class="fas fa-home"></i> House Photographs</h3>
                <div class="photo-gallery">
                    <?php 
                    $housePhotos = [
                        ['path' => $agent['house_photo_1'] ?? '', 'label' => 'House Photo 1'],
                        ['path' => $agent['house_photo_2'] ?? '', 'label' => 'House Photo 2'],
                        ['path' => $agent['street_photo'] ?? '', 'label' => 'Street Photo'],
                    ];
                    $hasPhotos = false;
                    foreach ($housePhotos as $photo):
                        if (empty($photo['path'])) continue;
                        $hasPhotos = true;
                    ?>
                        <div class="photo-item">
                            <img src="/raj/api/upload-document.php?action=serve&agent=<?php echo $agentId; ?>&file=<?php echo urlencode($photo['path']); ?>" 
                                 alt="<?php echo $photo['label']; ?>"
                                 onerror="this.parentElement.style.display='none'">
                            <div class="label"><?php echo $photo['label']; ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hasPhotos): ?>
                        <p style="color:var(--ink-light);padding:10px 0;">No house photos uploaded.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ===== AUDIT LOG ===== -->
            <div class="section full">
                <h3><i class="fas fa-history"></i> Audit History</h3>
                <div class="audit-list">
                    <?php if (!empty($auditLog)): ?>
                        <?php foreach (array_reverse($auditLog) as $entry): ?>
                            <div class="audit-item">
                                <span class="action">
                                    <span class="audit-icon">
                                        <?php 
                                            $act = $entry['action'] ?? '';
                                            if (strpos($act, 'created') !== false) echo '📝';
                                            elseif (strpos($act, 'approved') !== false) echo '✅';
                                            elseif (strpos($act, 'rejected') !== false) echo '❌';
                                            elseif (strpos($act, 'updated') !== false) echo '✏️';
                                            elseif (strpos($act, 'verified') !== false) echo '🔒';
                                            else echo '📋';
                                        ?>
                                    </span>
                                    <?php echo htmlspecialchars($act); ?>
                                </span>
                                <span class="time">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('d M Y, h:i:s A', strtotime($entry['timestamp'] ?? 'now')); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--ink-light);padding:10px 0;">No audit records found.</p>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // SIDEBAR TOGGLE
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

        // MAP
        <?php if (!empty($locLat) && !empty($locLng) && is_numeric($locLat) && is_numeric($locLng)): ?>
            (function() {
                try {
                    var lat = parseFloat(<?php echo json_encode($locLat); ?>);
                    var lng = parseFloat(<?php echo json_encode($locLng); ?>);
                    
                    if (!isNaN(lat) && !isNaN(lng)) {
                        var map = L.map('locationMap').setView([lat, lng], 15);
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                        }).addTo(map);
                        
                        var marker = L.marker([lat, lng]).addTo(map);
                        marker.bindPopup(
                            '<div style="font-family:Inter,sans-serif;padding:4px;">' +
                            '<strong style="color:#1a3c6e;">📍 Agent Location</strong><br>' +
                            'Lat: <strong>' + lat.toFixed(6) + '</strong><br>' +
                            'Lng: <strong>' + lng.toFixed(6) + '</strong>' +
                            '</div>'
                        );
                        marker.openPopup();

                        <?php if (!empty($locAccuracy) && is_numeric($locAccuracy) && $locAccuracy > 0 && $locAccuracy < 1000): ?>
                            L.circle([lat, lng], {
                                radius: parseFloat(<?php echo json_encode($locAccuracy); ?>),
                                color: '#c9962f',
                                fillColor: '#c9962f',
                                fillOpacity: 0.08,
                                weight: 2,
                                dashArray: '6,6'
                            }).addTo(map);
                        <?php endif; ?>

                        setTimeout(function() { map.invalidateSize(); }, 500);
                        window.addEventListener('resize', function() { map.invalidateSize(); });
                    }
                } catch (e) {
                    console.error('Map error:', e);
                }
            })();
        <?php endif; ?>
    </script>
</body>
</html>