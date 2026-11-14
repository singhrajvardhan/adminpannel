<?php
// /raj/agent/onboarding.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

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

ensureAgentStorage($agentId);
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
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --navy-950: #0c1a38; --navy-900: #10254a; --navy-800: #163063; --navy-700: #1c3d7a;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-300: #e9c877;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success-bg: #e4f4e8; --success-ink: #1e7a41;
            --danger-bg: #fbe7e7; --danger-ink: #b3352f; --warn-bg: #fdf1de;
            --warn-ink: #9a6a10; --info-bg: #e6eefb; --info-ink: #29538c;
            --shadow-sm: 0 1px 2px rgba(12,26,56,.05), 0 1px 3px rgba(12,26,56,.06);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08); --shadow-lg: 0 20px 45px rgba(12,26,56,.14);
            --ease: cubic-bezier(.22,.61,.36,1); --radius: 16px; --radius-sm: 10px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; background: var(--cream); color: var(--ink); -webkit-font-smoothing: antialiased; min-height: 100vh; }
        .onboarding-header { background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%), linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%); color: #eef2fa; padding: 14px 0; position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-md); }
        .header-content { max-width: 1100px; margin: 0 auto; padding: 0 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .header-content .brand { display: flex; align-items: center; gap: 12px; }
        .header-content .brand .logo-mark { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, var(--gold-300), var(--gold-500)); display: flex; align-items: center; justify-content: center; font-family: 'Fraunces', serif; font-weight: 700; font-size: 16px; color: var(--navy-950); }
        .header-content .brand .brand-text { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600; color: #fff; }
        .header-content .brand .brand-text span { color: var(--gold-300); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; display: block; font-weight: 400; }
        .header-content .agent-info { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .header-content .agent-info .agent-id { opacity: 0.7; }
        .header-content .logout-btn { color: #f2a3a0; text-decoration: none; padding: 6px 16px; border-radius: var(--radius-sm); background: rgba(255,255,255,.08); transition: background .25s var(--ease), transform .25s var(--ease); font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .header-content .logout-btn:hover { background: rgba(240,90,80,.15); transform: translateY(-1px); }
        .progress-section { background: var(--paper); padding: 16px 0; border-bottom: 1px solid var(--line); position: sticky; top: 66px; z-index: 99; box-shadow: var(--shadow-sm); }
        .progress-wrapper { max-width: 1100px; margin: 0 auto; padding: 0 24px; }
        .progress-steps { display: flex; justify-content: space-between; margin-bottom: 10px; overflow-x: auto; gap: 2px; padding: 2px 0; }
        .progress-steps::-webkit-scrollbar { height: 3px; }
        .progress-steps::-webkit-scrollbar-thumb { background: var(--gold-300); border-radius: 10px; }
        .step { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 40px; cursor: pointer; opacity: 0.4; transition: opacity .3s var(--ease), transform .3s var(--ease); padding: 2px 2px; position: relative; }
        .step:hover { transform: translateY(-2px); }
        .step.active { opacity: 1; }
        .step.completed .step-num { background: var(--success-ink); color: white; }
        .step.active .step-num { background: linear-gradient(135deg, var(--gold-400), var(--gold-500)); color: var(--navy-950); box-shadow: 0 0 0 4px rgba(201,150,47,.25); transform: scale(1.05); }
        .step-num { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: var(--line); color: var(--ink-soft); font-weight: 700; font-size: 12px; transition: all .3s var(--ease); flex-shrink: 0; }
        .step-label { font-size: 8px; margin-top: 4px; color: var(--ink-soft); text-align: center; white-space: nowrap; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; }
        .step.active .step-label { color: var(--navy-800); }
        .step.completed .step-label { color: var(--success-ink); }
        .progress-bar-container { width: 100%; height: 4px; background: var(--line); border-radius: 4px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, var(--gold-400), var(--gold-500)); transition: width .6s var(--ease); border-radius: 4px; }
        .progress-text { display: flex; justify-content: space-between; margin-top: 6px; font-size: 12px; color: var(--ink-soft); }
        #progressPercentage { font-weight: 700; color: var(--gold-500); }
        .step-content { max-width: 900px; margin: 0 auto; padding: 28px 24px 20px; min-height: 350px; }
        .step-panel { display: none; animation: fadeSlide .4s var(--ease); }
        .step-panel.active { display: block; }
        @keyframes fadeSlide { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        .step-panel h2 { font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 4px; }
        .step-panel .subtitle { color: var(--ink-soft); font-size: 14px; margin-bottom: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: var(--ink); margin-bottom: 5px; }
        .form-group label .required { color: var(--danger-ink); margin-left: 2px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 11px 15px; border: 2px solid var(--line); border-radius: var(--radius-sm); font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color .25s var(--ease), box-shadow .25s var(--ease), background .25s var(--ease); background: var(--cream); color: var(--ink); }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--gold-500); outline: none; box-shadow: 0 0 0 4px rgba(201,150,47,.12); background: var(--paper); }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .help-text { font-size: 12px; color: var(--ink-soft); margin-top: 4px; }
        .btn { padding: 10px 26px; border: none; border-radius: var(--radius-sm); font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: transform .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease); font-family: 'Inter', sans-serif; }
        .btn-primary { background: linear-gradient(135deg, var(--navy-900), var(--navy-700)); color: #fff; box-shadow: 0 2px 8px rgba(12,26,56,.2); }
        .btn-primary:hover { background: linear-gradient(135deg, var(--navy-800), var(--navy-600)); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(12,26,56,.25); }
        .btn-gold { background: linear-gradient(135deg, var(--gold-400), var(--gold-500)); color: var(--navy-950); box-shadow: 0 2px 8px rgba(201,150,47,.3); }
        .btn-gold:hover { background: linear-gradient(135deg, var(--gold-300), var(--gold-400)); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201,150,47,.35); }
        .btn-secondary { background: var(--cream); color: var(--ink-soft); border: 2px solid var(--line); }
        .btn-secondary:hover { background: var(--line); transform: translateY(-2px); }
        .btn-success { background: var(--success-ink); color: white; }
        .btn-success:hover { background: #156b34; transform: translateY(-2px); }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; }
        .navigation-section { background: var(--paper); padding: 16px 0; border-top: 1px solid var(--line); position: sticky; bottom: 0; z-index: 99; box-shadow: 0 -2px 10px rgba(0,0,0,.04); }
        .nav-buttons { max-width: 900px; margin: 0 auto; padding: 0 24px; display: flex; justify-content: space-between; gap: 15px; }
        .nav-buttons .btn { min-width: 130px; justify-content: center; }
        .upload-area { border: 2px dashed var(--line); border-radius: var(--radius-sm); padding: 25px 20px; text-align: center; cursor: pointer; transition: all .3s var(--ease); background: var(--cream); min-height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .upload-area:hover { border-color: var(--gold-500); background: #fdfcf8; }
        .upload-area.dragover { border-color: var(--success-ink); background: var(--success-bg); }
        .upload-area .icon { font-size: 32px; color: var(--ink-soft); margin-bottom: 6px; }
        .upload-area .text { color: var(--ink-soft); font-size: 13px; }
        .upload-area .sub-text { font-size: 11px; color: #aaa; margin-top: 3px; }
        .upload-area .btn-group { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; justify-content: center; }
        .file-preview { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: var(--success-bg); border-radius: var(--radius-sm); margin-top: 10px; border: 1px solid var(--success-ink); }
        .file-preview img { max-width: 60px; max-height: 60px; border-radius: 4px; object-fit: cover; }
        .file-preview .file-info { flex: 1; }
        .file-preview .file-name { font-weight: 600; font-size: 13px; color: var(--success-ink); }
        .file-preview .file-size { font-size: 11px; color: var(--ink-soft); }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,26,56,.85); z-index: 9999; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: var(--paper); border-radius: var(--radius); padding: 24px; max-width: 600px; width: 92%; max-height: 95vh; overflow-y: auto; position: relative; box-shadow: var(--shadow-lg); }
        .modal-content .close { position: absolute; top: 10px; right: 14px; font-size: 26px; cursor: pointer; color: var(--ink-soft); background: none; border: none; transition: color .2s; }
        .modal-content .close:hover { color: var(--ink); }
        #cameraContainer { margin: 12px 0; text-align: center; background: #000; border-radius: var(--radius-sm); overflow: hidden; }
        #cameraPreview { width: 100%; max-height: 380px; background: #000; display: block; }
        .camera-controls { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 12px; }
        #locationMap { height: 280px; width: 100%; border-radius: var(--radius-sm); margin-top: 10px; border: 2px solid var(--line); }
        .location-status { padding: 16px; border-radius: var(--radius-sm); text-align: center; background: var(--cream); margin-top: 10px; }
        .review-section { background: var(--cream); border-radius: var(--radius-sm); padding: 16px 18px; margin-bottom: 14px; border: 1px solid var(--line); }
        .review-section h4 { font-family: 'Fraunces', serif; color: var(--navy-950); margin-bottom: 10px; font-size: 15px; }
        .review-item { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 13px; }
        .review-item:last-child { border-bottom: none; }
        .review-item .label { color: var(--ink-soft); font-weight: 500; }
        .review-item .value { font-weight: 500; text-align: right; }
        .status-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .status-badge.uploaded { background: var(--success-bg); color: var(--success-ink); }
        .status-badge.pending { background: var(--warn-bg); color: var(--warn-ink); }
        .status-badge.mandatory { background: var(--danger-bg); color: var(--danger-ink); }
        .declaration { background: var(--cream); padding: 16px 18px; border-radius: var(--radius-sm); margin: 16px 0; border: 1px solid var(--line); }
        .declaration label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13px; }
        .declaration input[type="checkbox"] { width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0; accent-color: var(--gold-500); }
        .alert { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; font-size: 13px; }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-success { background: var(--success-bg); color: var(--success-ink); border-left: 4px solid var(--success-ink); }
        .alert-danger { background: var(--danger-bg); color: var(--danger-ink); border-left: 4px solid var(--danger-ink); }
        .alert-warning { background: var(--warn-bg); color: var(--warn-ink); border-left: 4px solid var(--warn-ink); }
        .alert-info { background: var(--info-bg); color: var(--info-ink); border-left: 4px solid var(--info-ink); }
        .toast-container { position: fixed; top: 80px; right: 24px; z-index: 9999; max-width: 400px; }
        .toast-container .alert { margin-bottom: 10px; animation: slideIn .4s var(--ease); box-shadow: var(--shadow-md); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(12,26,56,.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .loading-overlay.show { display: flex; }
        .loading-spinner { background: var(--paper); padding: 30px 40px; border-radius: var(--radius); text-align: center; box-shadow: var(--shadow-lg); }
        .loading-spinner .spinner { border: 4px solid var(--line); border-top: 4px solid var(--gold-500); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 12px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-spinner p { color: var(--ink); font-size: 15px; font-weight: 600; }
        .loading-spinner .sub-text { color: var(--ink-soft); font-size: 13px; font-weight: 400; margin-top: 4px; }
        .education-block { border-radius: var(--radius-sm); padding: 18px; margin-bottom: 16px; transition: all .3s var(--ease); }
        .education-block h4 { font-family: 'Fraunces', serif; margin-bottom: 12px; font-size: 15px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .education-block h4 i { color: var(--gold-500); }
        .education-block .upload-area { padding: 12px; min-height: 70px; }
        .edu-mandatory { box-shadow: 0 0 0 3px rgba(179,53,47,.08); }
        .edu-mandatory h4 { color: var(--danger-ink); }
        .edu-mandatory h4 i { color: var(--danger-ink); }
        .edu-optional h4 { color: var(--navy-800); }
        .edu-optional h4 i { color: var(--gold-500); }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; gap: 0; } .step-label { font-size: 7px; } .step-num { width: 26px; height: 26px; font-size: 11px; } .header-content { flex-direction: column; align-items: flex-start; gap: 8px; } .step-content { padding: 20px 16px 16px; } .step-panel h2 { font-size: 20px; } .nav-buttons { flex-direction: column; gap: 10px; } .nav-buttons .btn { width: 100%; min-width: unset; } .review-item { flex-direction: column; align-items: flex-start; gap: 2px; } .review-item .value { text-align: left; } .toast-container { top: 70px; right: 12px; left: 12px; max-width: unset; } .modal-content { padding: 18px; } }
        @media (max-width: 480px) { .step-label { display: none; } .step { min-width: 30px; } .step-content { padding: 16px 12px; } .step-panel h2 { font-size: 18px; } .upload-area { padding: 16px 12px; min-height: 100px; } .upload-area .icon { font-size: 24px; } .loading-spinner { padding: 20px 24px; } }
        @media (prefers-reduced-motion: reduce) { * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; } }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"><div class="spinner"></div><p>Submitting your onboarding...</p><p class="sub-text">Please wait, do not close this page.</p></div>
    </div>

    <div id="onboarding-app">
        <header class="onboarding-header">
            <div class="header-content">
                <div class="brand"><div class="logo-mark">BG</div><div class="brand-text">BetterGrowth<span>Solutions</span></div></div>
                <div class="agent-info"><span class="agent-id">Agent: <strong><?php echo htmlspecialchars($agentId); ?></strong></span><a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
            </div>
        </header>

        <section class="progress-section">
            <div class="progress-wrapper">
                <div class="progress-steps" id="progressSteps">
                    <div class="step active" data-step="1"><span class="step-num">1</span><span class="step-label">Personal</span></div>
                    <div class="step" data-step="2"><span class="step-num">2</span><span class="step-label">Email</span></div>
                    <div class="step" data-step="3"><span class="step-num">3</span><span class="step-label">Resume</span></div>
                    <div class="step" data-step="4"><span class="step-num">4</span><span class="step-label">Education</span></div>
                    <div class="step" data-step="5"><span class="step-num">5</span><span class="step-label">PAN</span></div>
                    <div class="step" data-step="6"><span class="step-num">6</span><span class="step-label">Aadhaar</span></div>
                    <div class="step" data-step="7"><span class="step-num">7</span><span class="step-label">Photo</span></div>
                    <div class="step" data-step="8"><span class="step-num">8</span><span class="step-label">Location</span></div>
                    <div class="step" data-step="9"><span class="step-num">9</span><span class="step-label">House</span></div>
                    <div class="step" data-step="10"><span class="step-num">10</span><span class="step-label">Address</span></div>
                    <div class="step" data-step="11"><span class="step-num">11</span><span class="step-label">Review</span></div>
                </div>
                <div class="progress-bar-container"><div class="progress-bar" id="progressBar" style="width:9%;"></div></div>
                <div class="progress-text"><span id="progressPercentage">9%</span><span id="stepCounter">Step 1 of 11</span></div>
            </div>
        </section>

        <div class="step-content" id="stepContent">
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
                    <div class="form-group">
                        <label>📱 Primary Mobile Number <span class="required">*</span></label>
                        <input type="tel" id="mobile" name="mobile" required placeholder="Enter your primary 10-digit mobile number" pattern="[6-9][0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($onboardingData['mobile'] ?? ''); ?>">
                        <span class="help-text">Your main contact number (required)</span>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>📱 Alternate Mobile Number 1 <span class="required">*</span></label>
                            <input type="tel" id="alt_mobile_1" name="alt_mobile_1" required placeholder="Enter alternate 10-digit number" pattern="[6-9][0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($onboardingData['alt_mobile_1'] ?? ''); ?>">
                            <span class="help-text">Secondary contact number (required)</span>
                        </div>
                        <div class="form-group">
                            <label>📱 Alternate Mobile Number 2 <span style="color:var(--ink-soft);font-weight:400;font-size:11px;">(Optional)</span></label>
                            <input type="tel" id="alt_mobile_2" name="alt_mobile_2" placeholder="Enter additional number (optional)" pattern="[6-9][0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($onboardingData['alt_mobile_2'] ?? ''); ?>">
                            <span class="help-text">Additional contact number (optional)</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>📧 Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required placeholder="Enter your email address" value="<?php echo htmlspecialchars($onboardingData['email'] ?? ''); ?>">
                        <span class="help-text">We'll send OTP to verify this email</span>
                    </div>
                    <div class="form-group">
                        <label>🏠 Current Address <span class="required">*</span></label>
                        <textarea id="current_address" name="current_address" required placeholder="Enter your current address with landmark"><?php echo htmlspecialchars($onboardingData['current_address'] ?? ''); ?></textarea>
                    </div>
                </form>
            </div>
        </div>

        <section class="navigation-section">
            <div class="nav-buttons">
                <button id="prevBtn" class="btn btn-secondary" disabled><i class="fas fa-arrow-left"></i> Back</button>
                <button id="nextBtn" class="btn btn-gold">Save & Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </section>
    </div>

    <div id="cameraModal" class="modal">
        <div class="modal-content">
            <button class="close" id="closeCamera">&times;</button>
            <h3 id="cameraTitle" style="font-family:'Fraunces',serif;color:var(--navy-950);margin-bottom:8px;">📸 Capture Photo</h3>
            <div id="cameraContainer">
                <video id="cameraPreview" autoplay playsinline style="width:100%;max-height:380px;background:#000;display:block;"></video>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
                <img id="capturedImage" style="display:none;max-width:100%;max-height:380px;margin:0 auto;">
            </div>
            <div class="camera-controls">
                <button id="captureBtn" class="btn btn-primary">📸 Capture</button>
                <button id="retakeBtn" class="btn btn-secondary" style="display:none;">🔄 Retake</button>
                <button id="usePhotoBtn" class="btn btn-success" style="display:none;">✅ Use Photo</button>
            </div>
            <p id="cameraStatus" style="margin-top:8px;color:var(--ink-soft);text-align:center;font-size:13px;">📷 Click "Capture" to take a photo</p>
        </div>
    </div>

    <script>
    (function() {
        const agentId = '<?php echo $agentId; ?>';
        const csrfToken = '<?php echo $csrfToken; ?>';
        let stepData = <?php echo json_encode($onboardingData); ?> || {};
        let currentStep = 1;
        const totalSteps = 11;
        const stepContent = document.getElementById('stepContent');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const progressBar = document.getElementById('progressBar');
        const progressPercentage = document.getElementById('progressPercentage');
        const stepCounter = document.getElementById('stepCounter');
        const progressSteps = document.querySelectorAll('.step');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // ============================================================
        // EDUCATION SECTIONS RENDERER
        // ============================================================
        function renderEducationSections(highestLevel) {
            const d = stepData;
            let html = '';
            
            const sections = {
                'post_graduation': {
                    title: "Post-Graduation (Master's Degree)",
                    icon: 'fa-user-graduate',
                    fields: [
                        { id: 'edu_pg_college', label: 'College / University Name', placeholder: 'Enter college/university name' },
                        { id: 'edu_pg_roll', label: 'Roll Number', placeholder: 'Enter roll number' },
                        { id: 'edu_pg_degree', label: 'Degree / Stream', placeholder: 'e.g., M.Com, M.Sc, MBA' },
                        { id: 'edu_pg_year', label: 'Year of Passing', placeholder: 'e.g., 2025', maxlength: 4 },
                        { id: 'edu_pg_percent', label: 'Percentage / CGPA', placeholder: 'e.g., 68% or 6.8 CGPA' }
                    ],
                    fileId: 'eduPgFile', dropId: 'eduPgDropZone', previewId: 'eduPgPreview',
                    uploadBtn: 'eduPgUploadBtn', camBtn: 'eduPgCameraBtn',
                    field: 'edu_pg_doc', type: 'edu_pg'
                },
                'graduation': {
                    title: "Graduation (Bachelor's Degree)",
                    icon: 'fa-graduation-cap',
                    fields: [
                        { id: 'edu_grad_college', label: 'College / University Name', placeholder: 'Enter college/university name' },
                        { id: 'edu_grad_roll', label: 'Roll Number', placeholder: 'Enter roll number' },
                        { id: 'edu_grad_degree', label: 'Degree / Stream', placeholder: 'e.g., B.Com, B.Sc, BBA' },
                        { id: 'edu_grad_year', label: 'Year of Passing', placeholder: 'e.g., 2023', maxlength: 4 },
                        { id: 'edu_grad_percent', label: 'Percentage / CGPA', placeholder: 'e.g., 72% or 7.2 CGPA' }
                    ],
                    fileId: 'eduGradFile', dropId: 'eduGradDropZone', previewId: 'eduGradPreview',
                    uploadBtn: 'eduGradUploadBtn', camBtn: 'eduGradCameraBtn',
                    field: 'edu_grad_doc', type: 'edu_grad'
                },
                '12th': {
                    title: '12th Standard (HSC / Intermediate)',
                    icon: 'fa-book',
                    fields: [
                        { id: 'edu_12_school', label: 'School / College Name', placeholder: 'Enter school/college name' },
                        { id: 'edu_12_roll', label: 'Roll Number', placeholder: 'Enter roll number' },
                        { id: 'edu_12_board', label: 'Board / University', placeholder: 'e.g., CBSE, ICSE, State Board' },
                        { id: 'edu_12_year', label: 'Year of Passing', placeholder: 'e.g., 2020', maxlength: 4 },
                        { id: 'edu_12_percent', label: 'Percentage / CGPA', placeholder: 'e.g., 78% or 7.8 CGPA' }
                    ],
                    fileId: 'edu12File', dropId: 'edu12DropZone', previewId: 'edu12Preview',
                    uploadBtn: 'edu12UploadBtn', camBtn: 'edu12CameraBtn',
                    field: 'edu_12_doc', type: 'edu_12'
                },
                '10th': {
                    title: '10th Standard (SSC / Matriculation)',
                    icon: 'fa-school',
                    fields: [
                        { id: 'edu_10_school', label: 'School Name', placeholder: 'Enter school name' },
                        { id: 'edu_10_roll', label: 'Roll Number', placeholder: 'Enter roll number' },
                        { id: 'edu_10_board', label: 'Board / University', placeholder: 'e.g., CBSE, ICSE, State Board' },
                        { id: 'edu_10_year', label: 'Year of Passing', placeholder: 'e.g., 2018', maxlength: 4 },
                        { id: 'edu_10_percent', label: 'Percentage / CGPA', placeholder: 'e.g., 85% or 8.5 CGPA' }
                    ],
                    fileId: 'edu10File', dropId: 'edu10DropZone', previewId: 'edu10Preview',
                    uploadBtn: 'edu10UploadBtn', camBtn: 'edu10CameraBtn',
                    field: 'edu_10_doc', type: 'edu_10'
                }
            };
            
            const hierarchy = ['10th', '12th', 'graduation', 'post_graduation'];
            const highestIndex = hierarchy.indexOf(highestLevel);
            
            // Show sections from highest (mandatory) down to 10th (optional)
            for (let i = highestIndex; i >= 0; i--) {
                const key = hierarchy[i];
                const section = sections[key];
                if (!section) continue;
                
                const isMandatory = (i === highestIndex);
                const mandatoryLabel = isMandatory 
                    ? '<span class="status-badge mandatory">*Mandatory</span>'
                    : '<span style="font-size:11px;color:var(--ink-soft);font-weight:400;">(Optional)</span>';
                
                const borderColor = isMandatory ? 'var(--danger-ink)' : 'var(--line)';
                const bgColor = isMandatory ? 'linear-gradient(135deg,#fff5f5,#fffafa)' : 'var(--cream)';
                const borderWidth = isMandatory ? '2px' : '1px';
                
                html += `
                <div class="education-block ${isMandatory ? 'edu-mandatory' : 'edu-optional'}" id="eduSection_${key}" style="background:${bgColor};border:${borderWidth} solid ${borderColor};">
                    <h4><i class="fas ${section.icon}"></i> ${section.title} ${mandatoryLabel}</h4>
                    ${isMandatory ? '<div style="font-size:11px;color:var(--danger-ink);background:#fbe7e7;padding:8px 12px;border-radius:6px;margin-bottom:12px;"><i class="fas fa-exclamation-circle"></i> This is your highest education - all fields are required.</div>' : '<div style="font-size:11px;color:var(--ink-soft);background:#f8f7f3;padding:8px 12px;border-radius:6px;margin-bottom:12px;"><i class="fas fa-info-circle"></i> This is optional - fill only if applicable.</div>'}
                    
                    <div class="form-row">
                        <div class="form-group"><label>${section.fields[0].label} ${isMandatory ? '<span class="required">*</span>' : ''}</label><input type="text" id="${section.fields[0].id}" name="${section.fields[0].id}" placeholder="${section.fields[0].placeholder}" value="${d[section.fields[0].id] || ''}"></div>
                        <div class="form-group"><label>${section.fields[1].label} ${isMandatory ? '<span class="required">*</span>' : ''}</label><input type="text" id="${section.fields[1].id}" name="${section.fields[1].id}" placeholder="${section.fields[1].placeholder}" value="${d[section.fields[1].id] || ''}"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>${section.fields[2].label}</label><input type="text" id="${section.fields[2].id}" name="${section.fields[2].id}" placeholder="${section.fields[2].placeholder}" value="${d[section.fields[2].id] || ''}"></div>
                        <div class="form-group"><label>${section.fields[3].label}</label><input type="text" id="${section.fields[3].id}" name="${section.fields[3].id}" placeholder="${section.fields[3].placeholder}" ${section.fields[3].maxlength ? 'maxlength="' + section.fields[3].maxlength + '"' : ''} value="${d[section.fields[3].id] || ''}"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>${section.fields[4].label}</label><input type="text" id="${section.fields[4].id}" name="${section.fields[4].id}" placeholder="${section.fields[4].placeholder}" value="${d[section.fields[4].id] || ''}"></div>
                        <div class="form-group"><label>Document Upload ${isMandatory ? '<span class="required">*</span>' : ''}</label>
                            <div class="upload-area" id="${section.dropId}"><div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="${section.uploadBtn}">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="${section.camBtn}">📸 Capture</button></div><input type="file" id="${section.fileId}" accept=".pdf,image/jpeg,image/png" style="display:none;"></div>
                            <div id="${section.previewId}">${d[section.field] ? '<div class="file-preview" style="padding:6px 10px;"><span style="color:var(--success-ink);font-size:12px;">✅ Uploaded</span></div>' : ''}</div>
                        </div>
                    </div>
                </div>`;
            }
            
            html += `
            <div class="alert alert-warning" style="margin-top:16px;">
                <i class="fas fa-exclamation-triangle"></i>
                <div><strong>Note:</strong> Your highest education details are <strong>mandatory</strong>. All lower-level education details are <strong>optional</strong>.</div>
            </div>`;
            
            return html;
        }

        function handleEducationChange(level) {
            stepData.highest_education = level;
            const container = document.getElementById('educationSectionsContainer');
            if (container) {
                if (!level) {
                    container.innerHTML = '<div class="alert alert-warning" style="text-align:center;padding:30px;"><i class="fas fa-arrow-up"></i> Please select your highest education above to continue.</div>';
                } else {
                    container.innerHTML = renderEducationSections(level);
                    setTimeout(function() { initEducationUploads(); }, 100);
                }
            }
        }

        function getStepHTML(step) {
            const d = stepData;
            switch(step) {
                case 2: return `<h2>✉️ Email Verification</h2><p class="subtitle">Verify your email address with OTP</p><div id="otpContainer"><div class="form-group"><label>Email Address</label><input type="email" id="otp_email" value="${d.email || ''}" readonly style="background:#f5f5f5;"></div><button type="button" class="btn btn-primary" id="sendOtpBtn" style="margin-bottom:12px;">📨 Send OTP</button><div id="otpSection" style="display:none;"><div class="form-group"><label>Enter 6-Digit OTP</label><input type="text" id="otp_input" maxlength="6" placeholder="Enter OTP received via email"></div><div style="display:flex;gap:10px;flex-wrap:wrap;"><button type="button" class="btn btn-success" id="verifyOtpBtn">✅ Verify OTP</button><button type="button" class="btn btn-secondary" id="resendOtpBtn">🔄 Resend OTP</button></div><div id="otpStatus" style="margin-top:8px;"></div><div id="otpTimer" style="margin-top:8px;color:var(--ink-soft);font-size:13px;"></div></div><div id="otpVerified" style="display:${d.email_verified ? 'block' : 'none'};"><div class="alert alert-success">✅ Email Verified Successfully!</div></div></div>`;
                
                case 3: return `<h2>📄 Resume / CV</h2><p class="subtitle">Upload your resume (PDF, JPG, PNG)</p><div id="resumeUpload"><div class="upload-area" id="resumeDropZone"><div class="icon">📁</div><div class="text">Drag & drop your resume here</div><div class="sub-text">or click to browse</div><button type="button" class="btn btn-primary btn-sm" id="resumeUploadBtn" style="margin-top:8px;">📤 Choose File</button><input type="file" id="resumeFile" accept=".pdf,.jpg,.jpeg,.png" style="display:none;"></div><div id="resumePreview">${d.resume ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ Resume uploaded</span></div>' : ''}</div></div>`;
                
                case 4: return `<h2>🎓 Education Details</h2><p class="subtitle">Select your highest education level, then fill in the required details</p>
                    <div class="alert alert-info" style="margin-bottom:20px;">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Please fill correct details.</strong> This is a mandatory step for onboarding with Better Growth Solutions.</div>
                    </div>
                    
                    <div class="education-block" style="background:linear-gradient(135deg,#fdf8f0,#fffdf5);border:2px solid var(--gold-500);">
                        <h4 style="font-size:16px;color:var(--navy-950);"><i class="fas fa-graduation-cap"></i> What is your highest education?</h4>
                        <p style="font-size:12px;color:var(--ink-soft);margin-bottom:12px;">Select the highest level of education you have completed. Your selected level's details will be <strong>mandatory</strong>, and lower levels will be <strong>optional</strong>.</p>
                        <div class="form-group" style="margin-bottom:0;">
                            <select id="highest_education" name="highest_education" onchange="handleEducationChange(this.value)" style="padding:14px 16px;font-size:15px;font-weight:600;border-color:var(--gold-500);">
                                <option value="">-- Select Your Highest Education --</option>
                                <option value="10th" ${d.highest_education === '10th' ? 'selected' : ''}>📘 10th Standard (SSC / Matriculation)</option>
                                <option value="12th" ${d.highest_education === '12th' ? 'selected' : ''}>📗 12th Standard (HSC / Intermediate)</option>
                                <option value="graduation" ${d.highest_education === 'graduation' ? 'selected' : ''}>🎓 Graduation (Bachelor's Degree)</option>
                                <option value="post_graduation" ${d.highest_education === 'post_graduation' ? 'selected' : ''}>🎓 Post-Graduation (Master's Degree)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="educationSectionsContainer">
                        ${d.highest_education ? renderEducationSections(d.highest_education) : '<div class="alert alert-warning" style="text-align:center;padding:30px;"><i class="fas fa-arrow-up"></i> Please select your highest education above to continue.</div>'}
                    </div>`;
                
                case 5: return `<h2>🪪 PAN Card Verification</h2><p class="subtitle">Enter your PAN details and upload document</p><form><div class="form-group"><label>PAN Number <span class="required">*</span></label><input type="text" id="pan_number" name="pan_number" required placeholder="e.g., ABCDE1234F" oninput="this.value=this.value.toUpperCase();" maxlength="10" value="${d.pan_number || ''}"><span class="help-text">Format: 5 letters + 4 digits + 1 letter (e.g., ABCDE1234F)</span></div></form><div class="form-group"><label>Upload PAN Card Image <span class="required">*</span></label><div class="upload-area" id="panDropZone"><div class="icon">🖼️</div><div class="text">Upload PAN image or capture using camera</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="panUploadBtn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="panCameraBtn">📸 Capture</button></div><input type="file" id="panFile" accept="image/jpeg,image/png" style="display:none;"></div><div id="panPreview">${d.pan_image ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ PAN uploaded</span></div>' : ''}</div></div>`;
                
                case 6: return `<h2>🆔 Aadhaar Card</h2><p class="subtitle">Enter your Aadhaar details and upload documents</p><form><div class="form-group"><label>Aadhaar Number <span class="required">*</span></label><input type="text" id="aadhaar_number" name="aadhaar_number" required placeholder="12-digit Aadhaar number" maxlength="12" value="${d.aadhaar_number || ''}"><span class="help-text">Enter 12 digits (will be masked after submission)</span></div></form><div class="form-row"><div class="form-group"><label>Front Side <span class="required">*</span></label><div class="upload-area" id="aadhaarFrontDropZone"><div class="icon">🖼️</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="aadhaarFrontBtn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="aadhaarFrontCameraBtn">📸 Capture</button></div><input type="file" id="aadhaarFrontFile" accept="image/jpeg,image/png" style="display:none;"></div><div id="aadhaarFrontPreview">${d.aadhaar_front ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ Front uploaded</span></div>' : ''}</div></div><div class="form-group"><label>Back Side <span class="required">*</span></label><div class="upload-area" id="aadhaarBackDropZone"><div class="icon">🖼️</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="aadhaarBackBtn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="aadhaarBackCameraBtn">📸 Capture</button></div><input type="file" id="aadhaarBackFile" accept="image/jpeg,image/png" style="display:none;"></div><div id="aadhaarBackPreview">${d.aadhaar_back ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ Back uploaded</span></div>' : ''}</div></div></div>`;
                
                case 7: return `<h2>📸 Candidate Photograph</h2><p class="subtitle">Upload a recent photograph</p><div class="form-group"><div class="upload-area" id="photoDropZone"><div class="icon">📷</div><div class="text">Upload or capture your photo</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="photoUploadBtn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="photoCameraBtn">📸 Capture Live</button></div><input type="file" id="photoFile" accept="image/jpeg,image/png" style="display:none;"></div><div id="photoPreview">${d.candidate_photo ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ Photo uploaded</span></div>' : ''}</div></div>`;
                
                case 8: return `<h2>📍 Address Verification</h2><p class="subtitle">We need your current location for verification</p><div id="locationContainer"><div id="locationStatus" class="location-status">${d.location_captured ? '<div class="alert alert-success">✅ Location Captured Successfully!</div>' : '<p>To verify the address you provided, please allow location access.</p><button type="button" class="btn btn-gold" id="getLocationBtn">📍 Allow Location</button>'}</div><div id="locationMap" style="display:${d.location_captured ? 'block' : 'none'};"></div><div id="locationResult" style="display:${d.location_captured ? 'block' : 'none'};" class="alert alert-success">✅ Location Captured Successfully!</div></div>`;
                
                case 9: return `<h2>🏠 House Photographs</h2><p class="subtitle">Upload photos of your residence</p><div class="form-row"><div class="form-group"><label>House Photo 1 <span class="required">*</span></label><div class="upload-area" id="house1DropZone"><div class="icon">🏠</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="house1Btn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="house1CameraBtn">📸 Capture</button></div><input type="file" id="house1File" accept="image/jpeg,image/png" style="display:none;"></div><div id="house1Preview">${d.house_photo_1 ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ House 1 uploaded</span></div>' : ''}</div></div><div class="form-group"><label>House Photo 2 <span class="required">*</span></label><div class="upload-area" id="house2DropZone"><div class="icon">🏠</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="house2Btn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="house2CameraBtn">📸 Capture</button></div><input type="file" id="house2File" accept="image/jpeg,image/png" style="display:none;"></div><div id="house2Preview">${d.house_photo_2 ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ House 2 uploaded</span></div>' : ''}</div></div></div><div class="form-group"><label>Street Photo <span class="required">*</span></label><div class="upload-area" id="streetDropZone"><div class="icon">🛣️</div><div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center;"><button type="button" class="btn btn-primary btn-sm" id="streetBtn">📤 Upload</button><button type="button" class="btn btn-success btn-sm" id="streetCameraBtn">📸 Capture</button></div><input type="file" id="streetFile" accept="image/jpeg,image/png" style="display:none;"></div><div id="streetPreview">${d.street_photo ? '<div class="file-preview"><span style="color:var(--success-ink);">✅ Street uploaded</span></div>' : ''}</div></div>`;
                
                case 10: return `<h2>📍 Complete Address</h2><p class="subtitle">Enter your complete address details</p><form><div class="form-row"><div class="form-group"><label>House / Flat Number <span class="required">*</span></label><input type="text" id="house_number" name="house_number" required placeholder="e.g., 123, A-101" value="${d.house_number || ''}"></div><div class="form-group"><label>Street Address <span class="required">*</span></label><input type="text" id="street" name="street" required placeholder="Street name" value="${d.street || ''}"></div></div><div class="form-row"><div class="form-group"><label>Area / Locality <span class="required">*</span></label><input type="text" id="locality" name="locality" required placeholder="Area or locality name" value="${d.locality || ''}"></div><div class="form-group"><label>Landmark</label><input type="text" id="landmark" name="landmark" placeholder="Nearby landmark (optional)" value="${d.landmark || ''}"></div></div><div class="form-row"><div class="form-group"><label>City <span class="required">*</span></label><input type="text" id="city" name="city" required placeholder="City name" value="${d.city || ''}"></div><div class="form-group"><label>State <span class="required">*</span></label><input type="text" id="state" name="state" required placeholder="State name" value="${d.state || ''}"></div></div><div class="form-row"><div class="form-group"><label>PIN Code <span class="required">*</span></label><input type="text" id="pincode" name="pincode" required placeholder="6-digit PIN" maxlength="6" value="${d.pincode || ''}"></div><div class="form-group"><label>Country</label><input type="text" id="country" name="country" value="India" readonly style="background:#f5f5f5;"></div></div></form>`;
                
                case 11: return `<h2>📋 Review Your Information</h2><p class="subtitle">Please review all details before submission</p>
                    <div class="review-section"><h4>Personal Information</h4><div class="review-item"><span class="label">Full Name</span><span class="value">${d.full_name || 'N/A'}</span></div><div class="review-item"><span class="label">Father's Name</span><span class="value">${d.father_name || 'N/A'}</span></div><div class="review-item"><span class="label">Mother's Name</span><span class="value">${d.mother_name || 'N/A'}</span></div></div>
                    <div class="review-section"><h4>📱 Contact Information</h4><div class="review-item"><span class="label">Primary Mobile</span><span class="value"><strong>${d.mobile || 'N/A'}</strong></span></div><div class="review-item"><span class="label">Alternate Mobile 1</span><span class="value"><strong>${d.alt_mobile_1 || 'N/A'}</strong></span></div><div class="review-item"><span class="label">Alternate Mobile 2</span><span class="value">${d.alt_mobile_2 || '<span style="color:#aaa;">Not provided</span>'}</span></div><div class="review-item"><span class="label">Email</span><span class="value">${d.email || 'N/A'}</span></div><div class="review-item"><span class="label">Email Verified</span><span class="value"><span class="status-badge ${d.email_verified ? 'uploaded' : 'pending'}">${d.email_verified ? '✅ Verified' : '⏳ Pending'}</span></span></div></div>
                    <div class="review-section"><h4>🎓 Education Details</h4>
                        <div class="review-item"><span class="label">Highest Education</span><span class="value"><strong>${d.highest_education === 'post_graduation' ? '🎓 Post-Graduation' : d.highest_education === 'graduation' ? '🎓 Graduation' : d.highest_education === '12th' ? '📗 12th Standard' : d.highest_education === '10th' ? '📘 10th Standard' : 'N/A'}</strong></span></div>
                        ${d.edu_pg_college ? `<div class="review-item"><span class="label">Post-Graduation</span><span class="value">${d.edu_pg_college} - ${d.edu_pg_degree || 'N/A'} ${d.edu_pg_doc ? '✅' : ''}</span></div>` : ''}
                        ${d.edu_grad_college ? `<div class="review-item"><span class="label">Graduation</span><span class="value">${d.edu_grad_college} - ${d.edu_grad_degree || 'N/A'} ${d.edu_grad_doc ? '✅' : ''}</span></div>` : ''}
                        ${d.edu_12_school ? `<div class="review-item"><span class="label">12th Standard</span><span class="value">${d.edu_12_school} ${d.edu_12_doc ? '✅' : ''}</span></div>` : ''}
                        ${d.edu_10_school ? `<div class="review-item"><span class="label">10th Standard</span><span class="value">${d.edu_10_school} ${d.edu_10_doc ? '✅' : ''}</span></div>` : ''}
                    </div>
                    <div class="review-section"><h4>Documents</h4><div class="review-item"><span class="label">Resume</span><span class="value"><span class="status-badge ${d.resume ? 'uploaded' : 'pending'}">${d.resume ? '✅ Uploaded' : '⏳ Pending'}</span></span></div><div class="review-item"><span class="label">PAN Card</span><span class="value"><span class="status-badge ${d.pan_image ? 'uploaded' : 'pending'}">${d.pan_image ? '✅ Submitted' : '⏳ Pending'}</span></span></div><div class="review-item"><span class="label">Aadhaar Card</span><span class="value"><span class="status-badge ${d.aadhaar_front && d.aadhaar_back ? 'uploaded' : 'pending'}">${d.aadhaar_front && d.aadhaar_back ? '✅ Submitted' : '⏳ Pending'}</span></span></div><div class="review-item"><span class="label">Candidate Photo</span><span class="value"><span class="status-badge ${d.candidate_photo ? 'uploaded' : 'pending'}">${d.candidate_photo ? '✅ Uploaded' : '⏳ Pending'}</span></span></div></div>
                    <div class="review-section"><h4>Address Verification</h4><div class="review-item"><span class="label">Location Captured</span><span class="value"><span class="status-badge ${d.location_captured ? 'uploaded' : 'pending'}">${d.location_captured ? '✅ Captured' : '⏳ Pending'}</span></span></div><div class="review-item"><span class="label">House Photos</span><span class="value"><span class="status-badge ${d.house_photo_1 && d.house_photo_2 && d.street_photo ? 'uploaded' : 'pending'}">${d.house_photo_1 && d.house_photo_2 && d.street_photo ? '✅ Uploaded' : '⏳ Pending'}</span></span></div></div>
                    <div class="review-section"><h4>Address</h4><div class="review-item"><span class="label">House/Flat</span><span class="value">${d.house_number || 'N/A'}</span></div><div class="review-item"><span class="label">Street</span><span class="value">${d.street || 'N/A'}</span></div><div class="review-item"><span class="label">Locality</span><span class="value">${d.locality || 'N/A'}</span></div><div class="review-item"><span class="label">City</span><span class="value">${d.city || 'N/A'}</span></div><div class="review-item"><span class="label">State</span><span class="value">${d.state || 'N/A'}</span></div><div class="review-item"><span class="label">PIN Code</span><span class="value">${d.pincode || 'N/A'}</span></div></div>
                    <div class="declaration"><label><input type="checkbox" id="agreeCheckbox" required><span>I confirm that the information and documents provided by me are accurate and submitted for employment verification purposes.</span></label></div>
                    <div class="declaration" style="background:#e6eefb;border-color:#29538c;"><label><input type="checkbox" id="privacyCheckbox" required><span>I have read and agree to the <a href="/privacy-policy.php" target="_blank" style="color:#29538c;font-weight:600;text-decoration:underline;">Privacy Policy</a>. I consent to the collection, storage, and processing of my personal data for employment purposes as per the policy.</span></label></div>`;
                
                default: return '<p>Step not found.</p>';
            }
        }

        function renderStep(step) {
            document.querySelectorAll('.step-panel:not(#step1)').forEach(el => el.remove());
            document.getElementById('step1').classList.remove('active');
            if (step === 1) { document.getElementById('step1').classList.add('active'); }
            else {
                let existing = document.getElementById('step' + step);
                if (existing) { existing.classList.add('active'); }
                else { const div = document.createElement('div'); div.className = 'step-panel active'; div.id = 'step' + step; div.innerHTML = getStepHTML(step); stepContent.appendChild(div); }
            }
            progressSteps.forEach((el, index) => { const num = index + 1; el.classList.remove('active', 'completed'); if (num === step) el.classList.add('active'); if (num < step) el.classList.add('completed'); });
            prevBtn.disabled = step === 1;
            nextBtn.textContent = step === totalSteps ? '📤 Submit & Complete' : 'Save & Continue →';
            const pct = Math.round((step / totalSteps) * 100);
            progressBar.style.width = pct + '%';
            progressPercentage.textContent = pct + '%';
            stepCounter.textContent = 'Step ' + step + ' of ' + totalSteps;
            setTimeout(() => {
                if (step === 2) initOTP();
                if (step === 3) initFileUpload(3);
                if (step === 4) initEducationUploads();
                if (step === 5 || step === 6) initFileUpload(step);
                if (step === 7) initFileUpload(7);
                if (step === 8) initLocation();
                if (step === 9) initFileUpload(9);
            }, 100);
        }

        function validateStep(step) {
            const panel = document.getElementById('step' + step) || document.getElementById('step1');
            if (!panel) return true;
            const inputs = panel.querySelectorAll('[required]');
            let valid = true;
            inputs.forEach(inp => { if (!inp.value.trim()) { inp.style.borderColor = '#dc3545'; valid = false; } else { inp.style.borderColor = ''; } });
            
            if (step === 1) {
                const mobile = document.getElementById('mobile');
                if (!mobile || !/^[6-9]\d{9}$/.test(mobile.value)) { showToast('❌ Enter a valid 10-digit Primary Mobile Number', 'error'); if(mobile) mobile.style.borderColor='#dc3545'; return false; }
                if(mobile) mobile.style.borderColor='';
                const alt1 = document.getElementById('alt_mobile_1');
                if (!alt1 || !/^[6-9]\d{9}$/.test(alt1.value)) { showToast('❌ Enter a valid Alternate Mobile Number 1', 'error'); if(alt1) alt1.style.borderColor='#dc3545'; return false; }
                if(alt1) alt1.style.borderColor='';
                if (mobile && alt1 && mobile.value === alt1.value) { showToast('❌ Alternate Mobile 1 must be different from Primary', 'error'); alt1.style.borderColor='#dc3545'; return false; }
                const alt2 = document.getElementById('alt_mobile_2');
                if (alt2 && alt2.value.trim() !== '') {
                    if (!/^[6-9]\d{9}$/.test(alt2.value)) { showToast('❌ Alternate Mobile 2 is invalid', 'error'); alt2.style.borderColor='#dc3545'; return false; }
                    if (mobile && alt2.value === mobile.value) { showToast('❌ Alternate Mobile 2 must be different from Primary', 'error'); alt2.style.borderColor='#dc3545'; return false; }
                    if (alt1 && alt2.value === alt1.value) { showToast('❌ Alternate Mobile 2 must be different from Alternate 1', 'error'); alt2.style.borderColor='#dc3545'; return false; }
                }
                if(alt2) alt2.style.borderColor='';
                const email = document.getElementById('email');
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) { showToast('❌ Enter a valid Email Address', 'error'); if(email) email.style.borderColor='#dc3545'; return false; }
                if(email) email.style.borderColor='';
            }
            
            if (step === 4) {
                const highestEdu = document.getElementById('highest_education');
                if (!highestEdu || !highestEdu.value) {
                    showToast('Please select your highest education level', 'error');
                    if (highestEdu) highestEdu.style.borderColor = '#dc3545';
                    return false;
                }
                if (highestEdu) highestEdu.style.borderColor = '';
                
                const level = highestEdu.value;
                
                // Validate the HIGHEST education (mandatory)
                if (level === '10th') {
                    const school = document.getElementById('edu_10_school');
                    const roll = document.getElementById('edu_10_roll');
                    if (!school || !school.value.trim()) { showToast('Please fill 10th school name (mandatory)', 'error'); return false; }
                    if (!roll || !roll.value.trim()) { showToast('Please fill 10th roll number (mandatory)', 'error'); return false; }
                    if (!stepData.edu_10_doc) { showToast('Please upload 10th marksheet (mandatory)', 'error'); return false; }
                }
                else if (level === '12th') {
                    const school = document.getElementById('edu_12_school');
                    const roll = document.getElementById('edu_12_roll');
                    if (!school || !school.value.trim()) { showToast('Please fill 12th school name (mandatory)', 'error'); return false; }
                    if (!roll || !roll.value.trim()) { showToast('Please fill 12th roll number (mandatory)', 'error'); return false; }
                    if (!stepData.edu_12_doc) { showToast('Please upload 12th marksheet (mandatory)', 'error'); return false; }
                }
                else if (level === 'graduation') {
                    const college = document.getElementById('edu_grad_college');
                    const roll = document.getElementById('edu_grad_roll');
                    if (!college || !college.value.trim()) { showToast('Please fill graduation college name (mandatory)', 'error'); return false; }
                    if (!roll || !roll.value.trim()) { showToast('Please fill graduation roll number (mandatory)', 'error'); return false; }
                    if (!stepData.edu_grad_doc) { showToast('Please upload graduation certificate (mandatory)', 'error'); return false; }
                }
                else if (level === 'post_graduation') {
                    const college = document.getElementById('edu_pg_college');
                    const roll = document.getElementById('edu_pg_roll');
                    if (!college || !college.value.trim()) { showToast('Please fill post-graduation college name (mandatory)', 'error'); return false; }
                    if (!roll || !roll.value.trim()) { showToast('Please fill post-graduation roll number (mandatory)', 'error'); return false; }
                    if (!stepData.edu_pg_doc) { showToast('Please upload post-graduation certificate (mandatory)', 'error'); return false; }
                }
            }
            
            if (step === 5) {
                const pan = document.getElementById('pan_number');
                if (pan && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value.toUpperCase())) { showToast('Invalid PAN format. Must be AAAAA9999A', 'error'); return false; }
                if (!stepData.pan_image) { showToast('Please upload PAN image', 'error'); return false; }
            }
            
            if (step === 6) {
                const aad = document.getElementById('aadhaar_number');
                if (aad && !/^\d{12}$/.test(aad.value)) { showToast('Aadhaar must be 12 digits', 'error'); return false; }
                if (!stepData.aadhaar_front) { showToast('Upload Aadhaar front side', 'error'); return false; }
                if (!stepData.aadhaar_back) { showToast('Upload Aadhaar back side', 'error'); return false; }
            }
            
            if (step === 7 && !stepData.candidate_photo) { showToast('Upload your photograph', 'error'); return false; }
            if (step === 8 && !stepData.location_captured) { showToast('Capture your location', 'error'); return false; }
            
            if (step === 9) {
                if (!stepData.house_photo_1) { showToast('Upload House Photo 1', 'error'); return false; }
                if (!stepData.house_photo_2) { showToast('Upload House Photo 2', 'error'); return false; }
                if (!stepData.street_photo) { showToast('Upload Street Photo', 'error'); return false; }
            }
            
            if (step === 10) { const pin = document.getElementById('pincode'); if (pin && !/^\d{6}$/.test(pin.value)) { showToast('PIN Code must be 6 digits', 'error'); return false; } }
            
            if (step === 11) {
                const cb = document.getElementById('agreeCheckbox');
                if (!cb || !cb.checked) { showToast('Please agree to the declaration', 'error'); return false; }
                const pc = document.getElementById('privacyCheckbox');
                if (!pc || !pc.checked) { showToast('Please agree to the Privacy Policy', 'error'); return false; }
            }
            
            if (!valid) { showToast('Fill all required fields', 'error'); return false; }
            return true;
        }

        function saveStep(step) {
            const panel = document.getElementById('step' + step) || document.getElementById('step1');
            if (!panel) return;
            const form = panel.querySelector('form');
            if (form) { const data = {}; new FormData(form).forEach((v, k) => data[k] = v); stepData = { ...stepData, ...data }; }
            // Also collect all visible input fields (for education step)
            panel.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], select').forEach(inp => {
                if (inp.id && inp.value) stepData[inp.id] = inp.value;
            });
            fetch('/raj/api/save-step.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ agent_id: agentId, csrf_token: csrfToken, step: step, data: JSON.stringify(stepData) }) })
            .then(r => r.json()).then(d => { if (d.success) console.log('Step ' + step + ' saved'); }).catch(err => console.log('Save error:', err));
        }

        function initOTP() {
            const sendBtn = document.getElementById('sendOtpBtn'), verifyBtn = document.getElementById('verifyOtpBtn'), resendBtn = document.getElementById('resendOtpBtn');
            const otpSection = document.getElementById('otpSection'), otpVerified = document.getElementById('otpVerified'), otpTimer = document.getElementById('otpTimer');
            let cooldown = 0, timer = null;
            if (stepData.email_verified) { if(otpVerified) otpVerified.style.display='block'; if(sendBtn) sendBtn.disabled=true; return; }
            if(!sendBtn) return;
            sendBtn.onclick = function() {
                const email = document.getElementById('otp_email').value;
                if(!email) { showToast('Enter email first','error'); return; }
                this.disabled=true; this.textContent='⏳ Sending...';
                fetch('/raj/api/send-otp.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({agent_id:agentId,email:email}) })
                .then(r=>r.json()).then(d=>{ this.disabled=false; this.textContent='📨 Send OTP'; if(d.success){ showToast('OTP sent!','success'); otpSection.style.display='block'; startCooldown(60); } else showToast('Error: '+d.message,'error'); })
                .catch(()=>{ this.disabled=false; this.textContent='📨 Send OTP'; showToast('Network error','error'); });
            };
            if(verifyBtn) verifyBtn.onclick = function() {
                const otp = document.getElementById('otp_input').value;
                if(otp.length!==6) { showToast('Enter 6-digit OTP','error'); return; }
                this.disabled=true; this.textContent='⏳ Verifying...';
                fetch('/raj/api/verify-otp.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({agent_id:agentId,otp:otp}) })
                .then(r=>r.json()).then(d=>{ this.disabled=false; this.textContent='✅ Verify OTP'; if(d.success){ showToast('✅ Email verified!','success'); stepData.email_verified=true; if(otpVerified) otpVerified.style.display='block'; if(otpSection) otpSection.style.display='none'; if(sendBtn) sendBtn.disabled=true; } else showToast('Error: '+d.message,'error'); })
                .catch(()=>{ this.disabled=false; this.textContent='✅ Verify OTP'; showToast('Network error','error'); });
            };
            if(resendBtn) resendBtn.onclick = function() { if(cooldown>0){ showToast('Wait '+cooldown+'s','warning'); return; } sendBtn.click(); };
            function startCooldown(sec) { cooldown=sec; if(resendBtn) resendBtn.disabled=true; clearInterval(timer); timer=setInterval(()=>{ cooldown--; if(otpTimer) otpTimer.textContent='Resend in '+cooldown+'s'; if(cooldown<=0){ clearInterval(timer); if(resendBtn) resendBtn.disabled=false; if(otpTimer) otpTimer.textContent=''; } },1000); }
        }

        function initFileUpload(step) {
            const configs = {
                3: [{ drop:'resumeDropZone', file:'resumeFile', btn:'resumeUploadBtn', preview:'resumePreview', field:'resume', type:'resume' }],
                5: [{ drop:'panDropZone', file:'panFile', btn:'panUploadBtn', cam:'panCameraBtn', preview:'panPreview', field:'pan_image', type:'pan' }],
                6: [{ drop:'aadhaarFrontDropZone', file:'aadhaarFrontFile', btn:'aadhaarFrontBtn', cam:'aadhaarFrontCameraBtn', preview:'aadhaarFrontPreview', field:'aadhaar_front', type:'aadhaar_front' }, { drop:'aadhaarBackDropZone', file:'aadhaarBackFile', btn:'aadhaarBackBtn', cam:'aadhaarBackCameraBtn', preview:'aadhaarBackPreview', field:'aadhaar_back', type:'aadhaar_back' }],
                7: [{ drop:'photoDropZone', file:'photoFile', btn:'photoUploadBtn', cam:'photoCameraBtn', preview:'photoPreview', field:'candidate_photo', type:'photo' }],
                9: [{ drop:'house1DropZone', file:'house1File', btn:'house1Btn', cam:'house1CameraBtn', preview:'house1Preview', field:'house_photo_1', type:'house1' }, { drop:'house2DropZone', file:'house2File', btn:'house2Btn', cam:'house2CameraBtn', preview:'house2Preview', field:'house_photo_2', type:'house2' }, { drop:'streetDropZone', file:'streetFile', btn:'streetBtn', cam:'streetCameraBtn', preview:'streetPreview', field:'street_photo', type:'street' }]
            };
            const cfgs = configs[step];
            if(!cfgs) return;
            cfgs.forEach(c => {
                const drop=document.getElementById(c.drop), file=document.getElementById(c.file), btn=document.getElementById(c.btn), cam=document.getElementById(c.cam), preview=document.getElementById(c.preview);
                if(!drop||!file) return;
                if(btn) btn.onclick=function(e){ e.preventDefault(); e.stopPropagation(); file.click(); };
                drop.onclick=function(e){ if(e.target.closest('button')) return; file.click(); };
                drop.ondragover=function(e){ e.preventDefault(); this.classList.add('dragover'); };
                drop.ondragleave=function(e){ e.preventDefault(); this.classList.remove('dragover'); };
                drop.ondrop=function(e){ e.preventDefault(); this.classList.remove('dragover'); if(e.dataTransfer.files.length){ file.files=e.dataTransfer.files; handleUpload(file,c,preview); } };
                file.onchange=function(){ if(this.files&&this.files.length) handleUpload(this,c,preview); };
                if(cam) cam.onclick=function(e){ e.preventDefault(); e.stopPropagation(); openCamera(function(dataUrl){ fetch(dataUrl).then(r=>r.blob()).then(blob=>{ const f=new File([blob],'camera-capture.jpg',{type:'image/jpeg'}); const dt=new DataTransfer(); dt.items.add(f); file.files=dt.files; handleUpload(file,c,preview); }); },'document'); };
            });
        }

        function initEducationUploads() {
            const configs = [
                { drop:'edu10DropZone', file:'edu10File', btn:'edu10UploadBtn', cam:'edu10CameraBtn', preview:'edu10Preview', field:'edu_10_doc', type:'edu_10' },
                { drop:'edu12DropZone', file:'edu12File', btn:'edu12UploadBtn', cam:'edu12CameraBtn', preview:'edu12Preview', field:'edu_12_doc', type:'edu_12' },
                { drop:'eduGradDropZone', file:'eduGradFile', btn:'eduGradUploadBtn', cam:'eduGradCameraBtn', preview:'eduGradPreview', field:'edu_grad_doc', type:'edu_grad' },
                { drop:'eduPgDropZone', file:'eduPgFile', btn:'eduPgUploadBtn', cam:'eduPgCameraBtn', preview:'eduPgPreview', field:'edu_pg_doc', type:'edu_pg' }
            ];
            configs.forEach(c => {
                const drop = document.getElementById(c.drop);
                const file = document.getElementById(c.file);
                const btn = document.getElementById(c.btn);
                const cam = document.getElementById(c.cam);
                const preview = document.getElementById(c.preview);
                if (!drop || !file) return;
                if (drop.dataset.initialized === '1') return;
                drop.dataset.initialized = '1';
                if (btn) btn.onclick = function(e) { e.preventDefault(); e.stopPropagation(); file.click(); };
                drop.onclick = function(e) { if (e.target.closest('button')) return; file.click(); };
                drop.ondragover = function(e) { e.preventDefault(); this.classList.add('dragover'); };
                drop.ondragleave = function(e) { e.preventDefault(); this.classList.remove('dragover'); };
                drop.ondrop = function(e) { e.preventDefault(); this.classList.remove('dragover'); if (e.dataTransfer.files.length) { file.files = e.dataTransfer.files; handleUpload(file, c, preview); } };
                file.onchange = function() { if (this.files && this.files.length) handleUpload(this, c, preview); };
                if (cam) cam.onclick = function(e) { e.preventDefault(); e.stopPropagation(); openCamera(function(dataUrl) { fetch(dataUrl).then(r => r.blob()).then(blob => { const f = new File([blob], 'edu-capture.jpg', { type: 'image/jpeg' }); const dt = new DataTransfer(); dt.items.add(f); file.files = dt.files; handleUpload(file, c, preview); }); }, 'document'); };
            });
        }

        function handleUpload(fileInput, cfg, preview) {
            if(!fileInput.files||!fileInput.files.length) return;
            const file=fileInput.files[0];
            if(file.size>5*1024*1024){ showToast('File too large (max 5MB)','error'); fileInput.value=''; return; }
            const fd=new FormData(); fd.append('agent_id',agentId); fd.append('type',cfg.type||'document'); fd.append('file',file);
            const btn=document.getElementById(cfg.btn); if(btn){ btn.textContent='⏳ Uploading...'; btn.disabled=true; }
            fetch('/raj/api/upload-document.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(btn){ btn.textContent='📤 Upload'; btn.disabled=false; } if(d.success){ stepData[cfg.field]=d.path; showToast('✅ Uploaded!','success'); if(preview){ if(file.type.startsWith('image/')){ const reader=new FileReader(); reader.onload=function(e){ preview.innerHTML='<div class="file-preview"><img src="'+e.target.result+'" style="max-width:70px;max-height:70px;border-radius:4px;object-fit:cover;"><div class="file-info"><div class="file-name">'+file.name+'</div><div class="file-size">'+(file.size/1024).toFixed(1)+' KB</div></div><span style="color:var(--success-ink);font-weight:600;">✅ Uploaded</span></div>'; }; reader.readAsDataURL(file); } else { preview.innerHTML='<div class="file-preview"><span style="font-size:28px;">📄</span><div class="file-info"><div class="file-name">'+file.name+'</div><div class="file-size">'+(file.size/1024).toFixed(1)+' KB</div></div><span style="color:var(--success-ink);font-weight:600;">✅ Uploaded</span></div>'; } } } else { showToast('Upload failed: '+d.message,'error'); fileInput.value=''; } }).catch(()=>{ showToast('Network error','error'); fileInput.value=''; if(btn){ btn.textContent='📤 Upload'; btn.disabled=false; } });
        }

        function openCamera(callback, type) {
            const modal=document.getElementById('cameraModal'), video=document.getElementById('cameraPreview'), canvas=document.getElementById('cameraCanvas'), image=document.getElementById('capturedImage');
            const status=document.getElementById('cameraStatus'), captureBtn=document.getElementById('captureBtn'), retakeBtn=document.getElementById('retakeBtn'), useBtn=document.getElementById('usePhotoBtn'), closeBtn=document.getElementById('closeCamera');
            let stream=null, captured=null;
            modal.style.display='flex'; status.textContent='⏳ Starting camera...'; image.style.display='none'; video.style.display='block'; captureBtn.style.display='inline-block'; retakeBtn.style.display='none'; useBtn.style.display='none';
            if(navigator.mediaDevices&&navigator.mediaDevices.getUserMedia){ navigator.mediaDevices.getUserMedia({video:true}).then(s=>{ stream=s; video.srcObject=s; return video.play(); }).then(()=>{ status.textContent='📷 Ready. Click Capture.'; }).catch(err=>{ status.textContent='❌ Camera error: '+err.message; }); } else { status.textContent='❌ Camera not supported.'; }
            captureBtn.onclick=function(){ if(!stream){ status.textContent='❌ No camera'; return; } const ctx=canvas.getContext('2d'); canvas.width=video.videoWidth||640; canvas.height=video.videoHeight||480; ctx.drawImage(video,0,0,canvas.width,canvas.height); captured=canvas.toDataURL('image/jpeg',0.95); image.src=captured; image.style.display='block'; video.style.display='none'; captureBtn.style.display='none'; retakeBtn.style.display='inline-block'; useBtn.style.display='inline-block'; status.textContent='✅ Captured!'; };
            retakeBtn.onclick=function(){ captured=null; image.style.display='none'; video.style.display='block'; captureBtn.style.display='inline-block'; retakeBtn.style.display='none'; useBtn.style.display='none'; status.textContent='📷 Ready.'; };
            useBtn.onclick=function(){ if(captured&&callback){ callback(captured); closeCamera(); } };
            function closeCamera(){ if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; } modal.style.display='none'; video.pause(); video.srcObject=null; }
            closeBtn.onclick=closeCamera;
            modal.onclick=function(e){ if(e.target===modal) closeCamera(); };
        }

        function initLocation() {
            const btn=document.getElementById('getLocationBtn');
            if(btn) btn.onclick=getLocation;
            if(stepData.location_captured){ const status=document.getElementById('locationStatus'); if(status) status.innerHTML='<div class="alert alert-success">✅ Location Captured!</div>'; const mapDiv=document.getElementById('locationMap'), result=document.getElementById('locationResult'); if(mapDiv) mapDiv.style.display='block'; if(result) result.style.display='block'; if(stepData.latitude&&stepData.longitude&&typeof L!=='undefined'){ setTimeout(()=>{ const map=L.map(mapDiv).setView([parseFloat(stepData.latitude),parseFloat(stepData.longitude)],15); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map); L.marker([parseFloat(stepData.latitude),parseFloat(stepData.longitude)]).addTo(map).bindPopup('📍 Your Location'); setTimeout(()=>map.invalidateSize(),300); },200); } }
        }

        function getLocation() {
            const status=document.getElementById('locationStatus'), mapDiv=document.getElementById('locationMap'), result=document.getElementById('locationResult');
            if(!navigator.geolocation){ status.innerHTML='<div class="alert alert-danger">❌ Geolocation not supported</div>'; return; }
            status.innerHTML='<p>📍 Getting location...</p>';
            navigator.geolocation.getCurrentPosition(function(pos){ const lat=pos.coords.latitude, lng=pos.coords.longitude; if(mapDiv) mapDiv.style.display='block'; if(result) result.style.display='block'; status.innerHTML='<div class="alert alert-success">✅ Location Captured!</div>'; if(typeof L!=='undefined'){ const map=L.map(mapDiv).setView([lat,lng],15); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map); L.marker([lat,lng]).addTo(map).bindPopup('📍 Your Location'); setTimeout(()=>map.invalidateSize(),300); } stepData.latitude=lat; stepData.longitude=lng; stepData.location_captured=true; fetch('/raj/api/save-location.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({agent_id:agentId,latitude:lat,longitude:lng,accuracy:pos.coords.accuracy})}).then(r=>r.json()).then(d=>{if(d.success)console.log('Location saved');}).catch(err=>console.log('Location save error:',err)); },function(err){ status.innerHTML='<div class="alert alert-danger">❌ Location permission denied.</div>'; },{enableHighAccuracy:true,timeout:10000});
        }

        function submitOnboarding() {
            const cb=document.getElementById('agreeCheckbox');
            if(!cb||!cb.checked){ showToast('Please agree to the declaration','error'); return; }
            const pc=document.getElementById('privacyCheckbox');
            if(!pc||!pc.checked){ showToast('Please agree to the Privacy Policy','error'); return; }
            if(!confirm('Please review your information carefully. After final submission, you will not be able to edit these details yourself. Do you want to continue?')) return;
            loadingOverlay.classList.add('show'); nextBtn.disabled=true; nextBtn.textContent='⏳ Submitting...';
            const submitData={...stepData};
            // Collect all visible form data from all panels
            document.querySelectorAll('.step-panel').forEach(panel => {
                panel.querySelectorAll('input, select, textarea').forEach(inp => {
                    if (inp.id && inp.value && inp.type !== 'file' && inp.type !== 'checkbox') {
                        submitData[inp.id] = inp.value;
                    }
                });
            });
            submitData.privacy_accepted = 1;
            fetch('/raj/api/submit-onboarding.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({agent_id:agentId,csrf_token:csrfToken,data:JSON.stringify(submitData)})})
            .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(data=>{ loadingOverlay.classList.remove('show'); nextBtn.disabled=false; nextBtn.textContent='📤 Submit & Complete'; if(data.success){ showToast('✅ Onboarding submitted!','success'); setTimeout(()=>{window.location.href='dashboard.php';},2000); } else showToast('Error: '+data.message,'error'); })
            .catch(err=>{ loadingOverlay.classList.remove('show'); nextBtn.disabled=false; nextBtn.textContent='📤 Submit & Complete'; showToast('Network error: '+err.message,'error'); });
        }

        function showToast(msg, type) {
            type=type||'info';
            const colors={success:'#d4edda',error:'#f8d7da',warning:'#fff3cd',info:'#d1ecf1'};
            const text={success:'#155724',error:'#721c24',warning:'#856404',info:'#0c5460'};
            const border={success:'#28a745',error:'#dc3545',warning:'#ffc107',info:'#17a2b8'};
            const existing=document.querySelector('.toast-container'); if(existing) existing.remove();
            const container=document.createElement('div'); container.className='toast-container';
            const toast=document.createElement('div'); toast.className='alert'; toast.textContent=msg;
            toast.style.cssText='padding:14px 18px;border-radius:8px;box-shadow:0 5px 20px rgba(0,0,0,0.2);animation:slideIn 0.3s ease;background:'+(colors[type]||'#d1ecf1')+';color:'+(text[type]||'#0c5460')+';border-left:4px solid '+(border[type]||'#17a2b8')+';font-weight:500;margin-bottom:10px;font-size:14px;';
            container.appendChild(toast); document.body.appendChild(container);
            setTimeout(()=>{ toast.style.opacity='0'; toast.style.transition='opacity 0.5s'; setTimeout(()=>container.remove(),500); },4000);
        }

        // Expose global functions for inline handlers
        window.handleEducationChange = handleEducationChange;

        prevBtn.addEventListener('click', function() { if (currentStep > 1) { if (validateStep(currentStep)) saveStep(currentStep); currentStep--; renderStep(currentStep); } });
        nextBtn.addEventListener('click', function() { if (validateStep(currentStep)) { saveStep(currentStep); if (currentStep < totalSteps) { currentStep++; renderStep(currentStep); } else { submitOnboarding(); } } });
        renderStep(1);
        console.log('Onboarding started for agent:', agentId);
    })();
    </script>
</body>
</html>