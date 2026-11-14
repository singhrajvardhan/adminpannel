<?php
// /raj/admin/location-tracking.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$days = $_GET['days'] ?? 20;
$agentFilter = $_GET['agent_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Get all agents for filter
$allAgents = executeQuery("SELECT agent_id, full_name FROM agents ORDER BY full_name ASC");

// Build query
$sql = "SELECT 
    a.agent_id,
    a.full_name,
    a.email,
    a.mobile,
    att.date,
    att.job_start_time,
    att.location_lat,
    att.location_lng,
    att.location_accuracy,
    att.location_captured_at,
    att.location_ip,
    att.login_time,
    att.logout_time,
    att.lunch_start,
    att.lunch_end,
    att.snack_start,
    att.snack_end,
    att.bio_start,
    att.bio_end,
    att.bio_count,
    att.status
FROM attendance att
JOIN agents a ON att.agent_id = a.agent_id
WHERE att.location_lat IS NOT NULL 
    AND att.location_lng IS NOT NULL
    AND att.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";

$params = [$days];
$types = 'i';

if (!empty($agentFilter)) {
    $sql .= " AND att.agent_id = ?";
    $params[] = $agentFilter;
    $types .= 's';
}

if (!empty($dateFrom)) {
    $sql .= " AND att.date >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if (!empty($dateTo)) {
    $sql .= " AND att.date <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$sql .= " ORDER BY att.date DESC, att.job_start_time DESC";

$locations = executeQuery($sql, $params, $types);

// Get all locations for map (decrypted)
$allLocations = [];
$allLocationsResult = executeQuery("
    SELECT 
        a.agent_id,
        a.full_name,
        att.location_lat,
        att.location_lng,
        att.location_accuracy,
        att.date,
        att.job_start_time
    FROM attendance att
    JOIN agents a ON att.agent_id = a.agent_id
    WHERE att.location_lat IS NOT NULL 
        AND att.location_lng IS NOT NULL
        AND att.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY att.date DESC
", [$days]);

if ($allLocationsResult) {
    while ($row = $allLocationsResult->fetch_assoc()) {
        $lat = decryptData($row['location_lat']);
        $lng = decryptData($row['location_lng']);
        if (!empty($lat) && !empty($lng) && is_numeric($lat) && is_numeric($lng)) {
            $allLocations[] = [
                'agent_id' => $row['agent_id'],
                'full_name' => $row['full_name'],
                'latitude' => floatval($lat),
                'longitude' => floatval($lng),
                'accuracy' => $row['location_accuracy'],
                'date' => $row['date'],
                'time' => $row['job_start_time']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Tracking - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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

        .filter-bar {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
        }
        .filter-bar .filter-group {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 8px 14px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            background: var(--cream);
            color: var(--ink);
            min-width: 150px;
        }
        .filter-bar select:focus, .filter-bar input:focus {
            border-color: var(--gold-500);
            outline: none;
        }
        .filter-bar .btn {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s;
        }
        .filter-bar .btn:hover { transform: translateY(-2px); }
        .filter-bar .btn-secondary {
            background: var(--cream);
            color: var(--ink-soft);
            border: 2px solid var(--line);
        }

        .map-container {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            margin-bottom: 24px;
        }
        #locationMap {
            height: 500px;
            width: 100%;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
        }

        .table-container {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 20px 22px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            overflow-x: auto;
            margin-bottom: 24px;
        }
        .table-container .table-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px; flex-wrap: wrap; gap: 8px;
        }
        .table-container h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950); font-size: 17px; font-weight: 600;
        }
        table { width: 100%; border-collapse: collapse; min-width: 780px; }
        th {
            text-align: left; padding: 8px 10px; background: transparent;
            color: var(--ink-soft); font-weight: 600; font-size: 10px;
            text-transform: uppercase; letter-spacing: .5px;
            border-bottom: 1px solid var(--line);
        }
        td { padding: 10px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 12px; }
        tbody tr:hover { background: #faf9f6; }

        .badge {
            padding: 2px 10px; border-radius: 999px; font-size: 10px;
            font-weight: 600; display: inline-flex; align-items: center;
            gap: 3px; white-space: nowrap;
        }
        .badge-success { background: var(--success-bg); color: var(--success-ink); }
        .badge-warning { background: var(--warn-bg); color: var(--warn-ink); }
        .badge-info { background: var(--info-bg); color: var(--info-ink); }
        .badge-secondary { background: #eef0f2; color: #55606e; }

        .btn {
            padding: 4px 10px; border: none; border-radius: 6px;
            font-size: 10px; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center;
            gap: 3px; transition: transform .2s var(--ease);
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--navy-800); color: white; }
        .btn-primary:hover { background: var(--navy-700); transform: translateY(-1px); }
        .btn-sm { padding: 2px 8px; font-size: 9px; }

        .empty-state {
            text-align: center; padding: 30px 20px; color: var(--ink-soft);
        }
        .empty-state i { font-size: 32px; opacity: 0.3; display: block; margin-bottom: 8px; }

        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(12,26,56,.45); z-index: 30; }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
        }

        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .filter-bar .filter-group { flex-direction: column; }
            .filter-bar select, .filter-bar input { width: 100%; min-width: unset; }
            #locationMap { height: 300px; }
        }
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
                <a href="agents.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                </a>
                <div class="nav-divider"></div>
                <a href="location-tracking.php" class="active">
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
            <div class="main-header">
                <div class="heading-group">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>📍 Location Tracking</h1>
                        <div class="subtitle">View agent locations captured during job start (last <?php echo $days; ?> days)</div>
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

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="get" style="width:100%;">
                    <div class="filter-group">
                        <select name="agent_id">
                            <option value="">All Agents</option>
                            <?php 
                            $allAgents2 = executeQuery("SELECT agent_id, full_name FROM agents ORDER BY full_name ASC");
                            while ($row = $allAgents2->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $row['agent_id']; ?>" <?php echo $agentFilter == $row['agent_id'] ? 'selected' : ''; ?>>
                                    <?php echo $row['agent_id']; ?> - <?php echo htmlspecialchars($row['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" placeholder="Date From">
                        <input type="date" name="date_to" value="<?php echo $dateTo; ?>" placeholder="Date To">
                        <select name="days">
                            <option value="15" <?php echo $days == 15 ? 'selected' : ''; ?>>Last 15 Days</option>
                            <option value="20" <?php echo $days == 20 ? 'selected' : ''; ?>>Last 20 Days</option>
                            <option value="30" <?php echo $days == 30 ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="60" <?php echo $days == 60 ? 'selected' : ''; ?>>Last 60 Days</option>
                        </select>
                        <button type="submit" class="btn"><i class="fas fa-search"></i> Filter</button>
                        <a href="location-tracking.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Map -->
            <div class="map-container">
                <div id="locationMap"></div>
                <div style="margin-top:10px;font-size:12px;color:var(--ink-soft);text-align:center;">
                    <i class="fas fa-map-pin" style="color:var(--gold-500);"></i> 
                    <?php echo count($allLocations); ?> location(s) shown on map
                </div>
            </div>

            <!-- Location Data Table -->
            <div class="table-container">
                <div class="table-head">
                    <h2><i class="fas fa-map-pin" style="color:var(--gold-500);"></i> Location History</h2>
                    <span style="color:var(--ink-soft);font-size:12px;">
                        <?php 
                            $count = ($locations && $locations->num_rows > 0) ? $locations->num_rows : 0;
                            echo $count . ' record' . ($count !== 1 ? 's' : '') . ' found';
                        ?>
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Date</th>
                            <th>Start Time</th>
                            <th>Location</th>
                            <th>Accuracy</th>
                            <th>IP Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($locations && $locations->num_rows > 0): ?>
                            <?php while ($row = $locations->fetch_assoc()): 
                                $lat = decryptData($row['location_lat']);
                                $lng = decryptData($row['location_lng']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['full_name'] ?: 'N/A'); ?></strong>
                                    <div style="font-size:10px;color:var(--ink-soft);">#<?php echo htmlspecialchars($row['agent_id']); ?></div>
                                </td>
                                <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                <td><?php echo !empty($row['job_start_time']) ? date('h:i A', strtotime($row['job_start_time'])) : '--'; ?></td>
                                <td>
                                    <?php if (!empty($lat) && !empty($lng)): ?>
                                        <span class="badge badge-info">
                                            <i class="fas fa-map-pin"></i>
                                            <?php echo substr($lat, 0, 8); ?>, <?php echo substr($lng, 0, 8); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No Location</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($row['location_accuracy']) ? $row['location_accuracy'] . 'm' : '--'; ?></td>
                                <td><?php echo htmlspecialchars($row['location_ip'] ?: '--'); ?></td>
                                <td>
                                    <span class="badge <?php echo $row['status'] === 'logged_out' ? 'badge-secondary' : ($row['status'] === 'on_break' ? 'badge-warning' : 'badge-success'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['status'] ?: 'present')); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-map-pin"></i>
                                        <p>No location data found for the selected period.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
        }

        menuToggle?.addEventListener('click', toggleSidebar);

        // Initialize map with all locations
        document.addEventListener('DOMContentLoaded', function() {
            const locations = <?php echo json_encode($allLocations); ?>;
            
            const map = L.map('locationMap');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            if (locations.length > 0) {
                const bounds = [];
                const markers = [];

                locations.forEach(loc => {
                    if (loc.latitude && loc.longitude) {
                        const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);
                        const popupContent = `
                            <strong>${loc.full_name}</strong><br>
                            Agent ID: ${loc.agent_id}<br>
                            Date: ${loc.date}<br>
                            Time: ${loc.time || 'N/A'}<br>
                            Location: ${loc.latitude}, ${loc.longitude}<br>
                            Accuracy: ${loc.accuracy || 'N/A'} meters
                        `;
                        marker.bindPopup(popupContent);
                        markers.push(marker);
                        bounds.push([loc.latitude, loc.longitude]);
                    }
                });

                if (bounds.length > 0) {
                    map.fitBounds(bounds);
                }
            } else {
                // Default view - India
                map.setView([20.5937, 78.9629], 4);
                L.marker([20.5937, 78.9629]).addTo(map)
                    .bindPopup('No location data available');
            }

            // Invalidate map size after a moment
            setTimeout(() => map.invalidateSize(), 500);
        });
    </script>
</body>
</html>