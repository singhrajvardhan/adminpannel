<?php
// /raj/agent/onboarding.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
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

if ($agent['onboarding_completed'] == 1) {
    header('Location: dashboard.php');
    exit;
}

// Ensure storage directory exists
ensureAgentStorage($agentId);

// Get existing onboarding data
$onboardingData = getOnboardingData($agentId) ?: [];

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboarding - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    
     <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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

        /* ============ HEADER ============ */
        .onboarding-header {
            background:
                radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-md);
        }

        .header-content {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-content .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-content .brand .logo-mark {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Fraunces', serif;
            font-weight: 700;
            font-size: 16px;
            color: var(--navy-950);
        }

        .header-content .brand .brand-text {
            font-family: 'Fraunces', serif;
            font-size: 16px;
            font-weight: 600;
            color: #fff;
        }

        .header-content .brand .brand-text span {
            color: var(--gold-300);
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            display: block;
            font-weight: 400;
        }

        .header-content .agent-info {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
        }

        .header-content .agent-info .agent-id {
            opacity: 0.7;
        }

        .header-content .logout-btn {
            color: #f2a3a0;
            text-decoration: none;
            padding: 6px 16px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,.08);
            transition: background .25s var(--ease), transform .25s var(--ease);
            font-weight: 500;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .header-content .logout-btn:hover {
            background: rgba(240,90,80,.15);
            transform: translateY(-1px);
        }

        /* ============ PROGRESS SECTION ============ */
        .progress-section {
            background: var(--paper);
            padding: 20px 0;
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 70px;
            z-index: 99;
            box-shadow: var(--shadow-sm);
        }

        .progress-wrapper {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 14px;
            overflow-x: auto;
            gap: 2px;
            padding: 2px 0;
        }

        .progress-steps::-webkit-scrollbar {
            height: 3px;
        }

        .progress-steps::-webkit-scrollbar-thumb {
            background: var(--gold-300);
            border-radius: 10px;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            min-width: 44px;
            cursor: pointer;
            opacity: 0.4;
            transition: opacity .3s var(--ease), transform .3s var(--ease);
            padding: 4px 2px;
            position: relative;
        }

        .step:hover {
            transform: translateY(-2px);
        }

        .step.active {
            opacity: 1;
        }

        .step.completed .step-num {
            background: var(--success-ink);
            color: white;
        }

        .step.active .step-num {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            box-shadow: 0 0 0 4px rgba(201,150,47,.25);
            transform: scale(1.05);
        }

        .step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--line);
            color: var(--ink-soft);
            font-weight: 700;
            font-size: 13px;
            transition: all .3s var(--ease);
            flex-shrink: 0;
        }

        .step-label {
            font-size: 9px;
            margin-top: 5px;
            color: var(--ink-soft);
            text-align: center;
            white-space: nowrap;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .step.active .step-label {
            color: var(--navy-800);
        }

        .step.completed .step-label {
            color: var(--success-ink);
        }

        .progress-bar-container {
            width: 100%;
            height: 4px;
            background: var(--line);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--gold-400), var(--gold-500));
            transition: width .6s var(--ease);
            border-radius: 4px;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 13px;
            color: var(--ink-soft);
        }

        #progressPercentage {
            font-weight: 700;
            color: var(--gold-500);
        }

        /* ============ STEP CONTENT ============ */
        .step-content {
            max-width: 900px;
            margin: 0 auto;
            padding: 32px 24px;
            min-height: 400px;
        }

        .step-panel {
            display: none;
            animation: fadeSlide .4s var(--ease);
        }

        .step-panel.active {
            display: block;
        }

        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-panel h2 {
            font-family: 'Fraunces', serif;
            font-size: 26px;
            color: var(--navy-950);
            margin-bottom: 4px;
        }

        .step-panel .subtitle {
            color: var(--ink-soft);
            font-size: 14px;
            margin-bottom: 28px;
        }

        /* ============ FORM ============ */
        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: var(--ink);
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: var(--danger-ink);
            margin-left: 2px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease), background .25s var(--ease);
            background: var(--cream);
            color: var(--ink);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--gold-500);
            outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .help-text {
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 5px;
        }

        /* ============ UPLOAD AREA ============ */
        .upload-area {
            border: 2px dashed var(--line);
            border-radius: var(--radius-sm);
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all .3s var(--ease);
            background: var(--cream);
            min-height: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .upload-area:hover {
            border-color: var(--gold-500);
            background: #fdfcf8;
        }

        .upload-area.dragover {
            border-color: var(--success-ink);
            background: var(--success-bg);
        }

        .upload-area .icon {
            font-size: 36px;
            color: var(--ink-soft);
            margin-bottom: 8px;
        }

        .upload-area .text {
            color: var(--ink-soft);
            font-size: 14px;
        }

        .upload-area .sub-text {
            font-size: 12px;
            color: #aaa;
            margin-top: 4px;
        }

        .upload-area .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .file-preview {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 16px;
            background: var(--success-bg);
            border-radius: var(--radius-sm);
            margin-top: 12px;
            border: 1px solid var(--success-ink);
        }

        .file-preview img {
            max-width: 70px;
            max-height: 70px;
            border-radius: 6px;
            object-fit: cover;
        }

        .file-preview .file-info {
            flex: 1;
        }

        .file-preview .file-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--success-ink);
        }

        .file-preview .file-size {
            font-size: 12px;
            color: var(--ink-soft);
        }

        /* ============ BUTTONS ============ */
        .btn {
            padding: 11px 28px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--navy-900), var(--navy-700));
            color: #fff;
            box-shadow: 0 2px 8px rgba(12,26,56,.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--navy-800), var(--navy-600));
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(12,26,56,.25);
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            box-shadow: 0 2px 8px rgba(201,150,47,.3);
        }

        .btn-gold:hover {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-400));
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201,150,47,.35);
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

        .btn-success {
            background: var(--success-ink);
            color: white;
        }

        .btn-success:hover {
            background: #156b34;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 7px 16px;
            font-size: 12px;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ============ NAVIGATION ============ */
        .navigation-section {
            background: var(--paper);
            padding: 18px 0;
            border-top: 1px solid var(--line);
            position: sticky;
            bottom: 0;
            z-index: 99;
            box-shadow: 0 -2px 10px rgba(0,0,0,.04);
        }

        .nav-buttons {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
        }

        .nav-buttons .btn {
            min-width: 140px;
            justify-content: center;
        }

        /* ============ ALERT ============ */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
        }

        .alert i { font-size: 18px; margin-top: 1px; flex-shrink: 0; }
        .alert-success {
            background: var(--success-bg);
            color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }
        .alert-danger {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }
        .alert-warning {
            background: var(--warn-bg);
            color: var(--warn-ink);
            border-left: 4px solid var(--warn-ink);
        }
        .alert-info {
            background: var(--info-bg);
            color: var(--info-ink);
            border-left: 4px solid var(--info-ink);
        }

        /* ============ LOCATION ============ */
        #locationMap {
            height: 300px;
            width: 100%;
            border-radius: var(--radius-sm);
            margin-top: 12px;
            border: 2px solid var(--line);
        }

        .location-status {
            padding: 20px;
            border-radius: var(--radius-sm);
            text-align: center;
            background: var(--cream);
            margin-top: 10px;
        }

        /* ============ REVIEW ============ */
        .review-section {
            background: var(--cream);
            border-radius: var(--radius-sm);
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid var(--line);
        }

        .review-section h4 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            margin-bottom: 12px;
            font-size: 16px;
        }

        .review-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--line);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-item .label {
            color: var(--ink-soft);
            font-weight: 500;
        }

        .review-item .value {
            font-weight: 500;
            text-align: right;
        }

        .status-badge {
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.uploaded {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .status-badge.pending {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }

        .declaration {
            background: var(--cream);
            padding: 20px;
            border-radius: var(--radius-sm);
            margin: 20px 0;
            border: 1px solid var(--line);
        }

        .declaration label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            font-size: 14px;
        }

        .declaration input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 1px;
            flex-shrink: 0;
            accent-color: var(--gold-500);
        }

        /* ============ TOAST ============ */
        .toast-container {
            position: fixed;
            top: 80px;
            right: 24px;
            z-index: 9999;
            max-width: 400px;
        }

        .toast-container .alert {
            margin-bottom: 10px;
            animation: slideIn .4s var(--ease);
            box-shadow: var(--shadow-md);
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .progress-steps {
                gap: 2px;
            }

            .step-label {
                font-size: 7px;
            }

            .step-num {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }

            .step-content {
                padding: 24px 16px;
            }

            .step-panel h2 {
                font-size: 22px;
            }

            .nav-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .nav-buttons .btn {
                width: 100%;
                min-width: unset;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .review-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
            }

            .review-item .value {
                text-align: left;
            }

            .toast-container {
                top: 70px;
                right: 12px;
                left: 12px;
                max-width: unset;
            }
        }

        @media (max-width: 480px) {
            .step-label {
                display: none;
            }

            .step {
                min-width: 30px;
            }

            .step-content {
                padding: 16px 12px;
            }

            .step-panel h2 {
                font-size: 20px;
            }

            .upload-area {
                padding: 20px 15px;
                min-height: 120px;
            }

            .upload-area .icon {
                font-size: 28px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="onboarding-header">
        <div class="header-content">
            <div class="brand">
                <div class="logo-mark">BG</div>
                <div class="brand-text">
                    BetterGrowth
                    <span>Solutions</span>
                </div>
            </div>
            <div class="agent-info">
                <span class="agent-id">Agent: <strong><?php echo htmlspecialchars($agentId); ?></strong></span>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Progress -->
    <section class="progress-section">
        <div class="progress-wrapper">
            <div class="progress-steps" id="progressSteps">
                <div class="step active" data-step="1">
                    <span class="step-num">1</span>
                    <span class="step-label">Personal</span>
                </div>
                <div class="step" data-step="2">
                    <span class="step-num">2</span>
                    <span class="step-label">Email</span>
                </div>
                <div class="step" data-step="3">
                    <span class="step-num">3</span>
                    <span class="step-label">Resume</span>
                </div>
                <div class="step" data-step="4">
                    <span class="step-num">4</span>
                    <span class="step-label">PAN</span>
                </div>
                <div class="step" data-step="5">
                    <span class="step-num">5</span>
                    <span class="step-label">Aadhaar</span>
                </div>
                <div class="step" data-step="6">
                    <span class="step-num">6</span>
                    <span class="step-label">Photo</span>
                </div>
                <div class="step" data-step="7">
                    <span class="step-num">7</span>
                    <span class="step-label">Location</span>
                </div>
                <div class="step" data-step="8">
                    <span class="step-num">8</span>
                    <span class="step-label">House</span>
                </div>
                <div class="step" data-step="9">
                    <span class="step-num">9</span>
                    <span class="step-label">Address</span>
                </div>
                <div class="step" data-step="10">
                    <span class="step-num">10</span>
                    <span class="step-label">Review</span>
                </div>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar" id="progressBar" style="width:10%;"></div>
            </div>
            <div class="progress-text">
                <span id="progressPercentage">10%</span>
                <span id="stepCounter">Step 1 of 10</span>
            </div>
        </div>
    </section>

    <!-- Step Content -->
    <div class="step-content" id="stepContent">
        <!-- Step 1: Personal Information -->
        <div class="step-panel active" id="step1">
            <h2>📋 Personal Information</h2>
            <p class="subtitle">Please provide your personal details</p>
            <form>
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name" value="<?php echo htmlspecialchars($onboardingData['full_name'] ?? ''); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Father's Name <span class="required">*</span></label>
                        <input type="text" id="father_name" name="father_name" required placeholder="Enter father's name" value="<?php echo htmlspecialchars($onboardingData['father_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Mother's Name <span class="required">*</span></label>
                        <input type="text" id="mother_name" name="mother_name" required placeholder="Enter mother's name" value="<?php echo htmlspecialchars($onboardingData['mother_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Mobile Number <span class="required">*</span></label>
                        <input type="tel" id="mobile" name="mobile" required placeholder="10-digit mobile number" pattern="[6-9][0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($onboardingData['mobile'] ?? ''); ?>">
                        <span class="help-text">Enter a valid Indian mobile number (10 digits)</span>
                    </div>
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required placeholder="Enter your email address" value="<?php echo htmlspecialchars($onboardingData['email'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Current Address <span class="required">*</span></label>
                    <textarea id="current_address" name="current_address" required placeholder="Enter your current address"><?php echo htmlspecialchars($onboardingData['current_address'] ?? ''); ?></textarea>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <section class="navigation-section">
        <div class="nav-buttons">
            <button id="prevBtn" class="btn btn-secondary" disabled>
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <button id="nextBtn" class="btn btn-gold">
                Save & Continue <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </section>

    <!-- Camera Modal -->
    <div id="cameraModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(12,26,56,.85); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-content" style="background:var(--paper); border-radius:var(--radius); padding:28px; max-width:600px; width:92%; max-height:95vh; overflow-y:auto; position:relative; box-shadow:var(--shadow-lg);">
            <button class="close" id="closeCamera" style="position:absolute; top:12px; right:16px; font-size:28px; cursor:pointer; color:var(--ink-soft); background:none; border:none; transition:color .2s;">&times;</button>
            <h3 id="cameraTitle" style="font-family:'Fraunces',serif; color:var(--navy-950); margin-bottom:12px;">📸 Capture Photo</h3>
            <div id="cameraContainer" style="margin:12px 0; text-align:center; background:#000; border-radius:var(--radius-sm); overflow:hidden; position:relative;">
                <video id="cameraPreview" autoplay playsinline style="width:100%; max-height:400px; background:#000; display:block;"></video>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
                <img id="capturedImage" style="display:none; max-width:100%; max-height:400px; margin:0 auto;">
            </div>
            <div class="camera-controls" style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:16px;">
                <button id="captureBtn" class="btn btn-primary">📸 Capture</button>
                <button id="retakeBtn" class="btn btn-secondary" style="display:none;">🔄 Retake</button>
                <button id="usePhotoBtn" class="btn btn-success" style="display:none;">✅ Use Photo</button>
            </div>
            <p id="cameraStatus" style="margin-top:10px; color:var(--ink-soft); text-align:center; font-size:14px;">📷 Click "Capture" to take a photo</p>
        </div>
    </div>

    <script>
        // PHP data for JavaScript
        const agentId = '<?php echo $agentId; ?>';
        const csrfToken = '<?php echo $csrfToken; ?>';
        const onboardingData = <?php echo json_encode($onboardingData); ?>;
    </script>
    <script src="/raj/assets/js/onboarding.js"></script>
    <script src="/raj/assets/js/camera.js"></script>
    <script src="/raj/assets/js/location.js"></script>
</body>
</html>