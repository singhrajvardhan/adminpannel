<?php
// /raj/agent/identity-card.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$agentId = $_SESSION['agent_id'] ?? '';
if (!$agentId) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$agent = getAgent($agentId);
if (!$agent) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($agent['onboarding_completed'] != 1) {
    header('Location: onboarding.php');
    exit;
}

$name = $agent['full_name'] ?: 'Employee';

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

// Get verification status
$verificationStatus = $agent['verification_status'] ?? 'pending';
$shiftType = $agent['shift_type'] ?? 'day';
$workLocation = $agent['work_location'] ?? 'office';

$statusColor = [
    'verified' => '#28a745',
    'rejected' => '#dc3545',
    'correction_requested' => '#ffc107',
    'pending' => '#17a2b8'
];

$shiftBadge = $shiftType === 'day' ? ' Day Shift' : ' Night Shift';
$locationBadge = $workLocation === 'office' ? ' Work From Office' : ' Work From Home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identity Card - <?php echo APP_NAME; ?></title>
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
            --shadow-lg: 0 20px 60px rgba(12,26,56,.18);
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container { max-width: 520px; width: 100%; }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--navy-800);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: var(--paper);
            border-radius: var(--radius-sm);
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            transition: transform .2s;
        }
        .back-btn:hover { transform: translateX(-4px); }
        .identity-card {
            background: linear-gradient(145deg, var(--navy-950), var(--navy-800));
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        .identity-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(201,150,47,.08);
        }
        .identity-card .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        .identity-card .card-header .logo-img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            background: rgba(255,255,255,.05);
            border-radius: 8px;
            padding: 2px 6px;
        }
        .identity-card .card-header .logo-fallback {
            display: none;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            align-items: center;
            justify-content: center;
            font-family: 'Fraunces', serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--navy-950);
        }
        .identity-card .card-header .logo-img.broken {
            display: none;
        }
        .identity-card .card-header .logo-img.broken + .logo-fallback {
            display: flex;
        }
        .identity-card .card-header .brand-text {
            color: #fff;
            font-family: 'Fraunces', serif;
            font-size: 16px;
            font-weight: 600;
            margin-left: auto;
        }
        .identity-card .card-header .brand-text span {
            display: block;
            color: var(--gold-300);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 400;
        }
        .identity-card .card-body {
            position: relative;
            z-index: 1;
            text-align: center;
        }
        .identity-card .photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--gold-500);
            margin: 0 auto 16px;
            display: block;
            background: var(--cream);
        }
        .identity-card .photo-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: #aaa;
            margin: 0 auto 16px;
            border: 4px solid var(--gold-500);
        }
        .identity-card .name {
            color: #fff;
            font-family: 'Fraunces', serif;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .identity-card .agent-id {
            color: var(--gold-300);
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 1px;
            margin-bottom: 16px;
        }
        .identity-card .divider {
            height: 1px;
            background: rgba(255,255,255,.1);
            margin: 16px 0;
        }
        .identity-card .shift-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .identity-card .shift-info .shift-badge {
            padding: 4px 16px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,.1);
            color: #fff;
        }
        .identity-card .shift-info .shift-badge.day { background: rgba(255,193,7,.2); color: #ffc107; }
        .identity-card .shift-info .shift-badge.night { background: rgba(23,162,184,.2); color: #17a2b8; }
        .identity-card .shift-info .shift-badge.office { background: rgba(40,167,69,.2); color: #28a745; }
        .identity-card .shift-info .shift-badge.home { background: rgba(201,150,47,.2); color: #c9962f; }

        .identity-card .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 20px;
            text-align: left;
        }
        .identity-card .info-grid .label {
            color: rgba(255,255,255,.5);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .identity-card .info-grid .value {
            color: #fff;
            font-size: 14px;
            font-weight: 500;
        }
        .identity-card .status-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 12px;
            background: <?php echo $statusColor[$verificationStatus] ?? '#17a2b8'; ?>;
            color: #fff;
        }
        .identity-card .company-info {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,.08);
            text-align: center;
        }
        .identity-card .company-info .company-email {
            color: var(--gold-300);
            font-size: 12px;
            font-weight: 500;
        }
        .identity-card .company-info .company-address {
            color: rgba(255,255,255,.4);
            font-size: 11px;
            margin-top: 4px;
        }
        .identity-card .card-footer {
            margin-top: 16px;
            text-align: center;
            color: rgba(255,255,255,.2);
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
            transition: transform .2s, box-shadow .2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            box-shadow: 0 2px 8px rgba(201,150,47,.3);
        }
        .btn-gold:hover { box-shadow: 0 6px 20px rgba(201,150,47,.35); }
        .btn-secondary {
            background: var(--paper);
            color: var(--ink-soft);
            border: 2px solid var(--line);
        }
        .btn-secondary:hover { background: var(--line); }
        @media (max-width: 500px) {
            .identity-card { padding: 20px; }
            .identity-card .info-grid { grid-template-columns: 1fr; gap: 4px; }
            .actions { flex-direction: column; }
            .actions .btn { width: 100%; justify-content: center; }
            .identity-card .card-header .brand-text { display: none; }
            .identity-card .shift-info { gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <div class="identity-card">
            <div class="card-header">
                <img src="/assets/images/logo.png" alt="BetterGrowth Solutions" class="logo-img"
                     onerror="this.classList.add('broken')">
                <div class="logo-fallback">BG</div>
                <div class="brand-text">
                    BetterGrowth
                    <span>Solutions</span>
                </div>
            </div>
            
            <div class="card-body">
                <?php if ($photoExists && !empty($photoUrl)): ?>
                    <img src="<?php echo $photoUrl; ?>" alt="Photo" class="photo"
                         onerror="this.style.display='none'; this.parentElement.querySelector('.photo-placeholder').style.display='flex';">
                    <div class="photo-placeholder" style="display:none;">👤</div>
                <?php else: ?>
                    <div class="photo-placeholder">👤</div>
                <?php endif; ?>
                
                <div class="name"><?php echo htmlspecialchars($name); ?></div>
                <div class="agent-id"># <?php echo htmlspecialchars($agentId); ?></div>
                
                <div class="shift-info">
                    <span class="shift-badge <?php echo $shiftType; ?>">
                        <i class="fas <?php echo $shiftType === 'day' ? 'fa-sun' : 'fa-moon'; ?>"></i>
                        <?php echo $shiftBadge; ?>
                    </span>
                    <span class="shift-badge <?php echo $workLocation; ?>">
                        <i class="fas <?php echo $workLocation === 'office' ? 'fa-building' : 'fa-home'; ?>"></i>
                        <?php echo $locationBadge; ?>
                    </span>
                </div>
                
                <div class="divider"></div>
                
                <div class="info-grid">
                    <div><span class="label">Email</span><div class="value"><?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></div></div>
                    <div><span class="label">Mobile</span><div class="value"><?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></div></div>
                    <div><span class="label">Department</span><div class="value">Sales</div></div>
                    <div><span class="label">Joining Date</span><div class="value"><?php echo date('d M Y', strtotime($agent['created_at'])); ?></div></div>
                </div>
                
                <div>
                    <span class="status-badge">
                        <?php echo ucfirst(str_replace('_', ' ', $verificationStatus)); ?>
                    </span>
                </div>
                
                <div class="company-info">
                    <div class="company-email">
                        <i class="fas fa-envelope" style="margin-right:6px;"></i>
                        info@bettergrowthsolutions.com
                    </div>
                    <div class="company-address">
                        <i class="fas fa-map-marker-alt" style="margin-right:6px;"></i>
                        Cosmos City, Bicholi Mardan, Indore, Madhya Pradesh
                    </div>
                </div>
            </div>
            
            <div class="card-footer">Authorized Employee Identity Card</div>
        </div>
        
        <div class="actions">
            <button class="btn btn-gold" onclick="window.print()">
                <i class="fas fa-print"></i> Print Card
            </button>
            <a href="daily-report.php" class="btn btn-secondary">
                <i class="fas fa-file-alt"></i> Daily Report
            </a>
        </div>
    </div>
</body>
</html>