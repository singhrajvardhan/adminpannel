<?php
// /raj/admin/create-agent.php
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
$agentId = '';
$dob = '';
$name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $agentId = trim(strtoupper($_POST['agent_id'] ?? ''));
        $dob = trim($_POST['dob'] ?? '');
        $name = trim($_POST['name'] ?? '');
        
        // Validate Agent ID format (BGS + 4 digits)
        if (empty($agentId)) {
            $error = 'Agent ID is required.';
        } elseif (!preg_match('/^BGS\d{4}$/', $agentId)) {
            $error = 'Agent ID must follow format: BGS followed by 4 digits (e.g., BGS1001)';
        } else {
            // Check if Agent ID already exists
            $check = executeQuery("SELECT agent_id FROM agents WHERE agent_id = ?", [$agentId]);
            
            // Check if query was successful and if agent exists
            $agentExists = false;
            if ($check !== false && $check->num_rows > 0) {
                $agentExists = true;
            }
            
            if ($agentExists) {
                $error = "Agent ID <strong>$agentId</strong> already exists! Please use a different ID.";
            } else {
                // Validate DOB format (DD/MM/YYYY)
                $dobParts = explode('/', $dob);
                if (count($dobParts) != 3 || !checkdate((int)$dobParts[1], (int)$dobParts[0], (int)$dobParts[2])) {
                    $error = 'Invalid DOB format. Use DD/MM/YYYY.';
                } else {
                    // Create agent with manual ID
                    $encDob = encryptData($dob);
                    $result = executeQuery(
                        "INSERT INTO agents (agent_id, date_of_birth, full_name) VALUES (?, ?, ?)",
                        [$agentId, $encDob, $name]
                    );
                    
                    // For INSERT queries, executeQuery returns true on success
                    if ($result === true) {
                        addAuditLog($agentId, 'agent_created', "by admin with ID: $agentId");
                        $success = "Agent <strong>$agentId</strong> created successfully!";
                        
                        // Clear fields after success
                        $agentId = '';
                        $dob = '';
                        $name = '';
                    } else {
                        $error = 'Failed to create agent. Please try again.';
                    }
                }
            }
        }
    }
}
$csrf = generateCsrfToken();

// Helper: get all agents count
$agentCount = 0;
$countResult = executeQuery("SELECT COUNT(*) as count FROM agents");
if ($countResult !== false && $countResult->num_rows > 0) {
    $row = $countResult->fetch_assoc();
    $agentCount = $row['count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Agent - <?php echo APP_NAME; ?></title>
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

        /* ============ FORM CARD ============ */
        .form-container {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 32px 36px 40px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            max-width: 820px;
        }

        .form-header {
            margin-bottom: 28px;
        }
        .form-header h2 {
            font-family: 'Fraunces', serif;
            font-size: 22px;
            color: var(--navy-950);
            font-weight: 600;
        }
        .form-header p {
            color: var(--ink-soft);
            font-size: 14px;
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

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
        .form-group .help-text {
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 11px 15px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease);
            background: var(--cream);
            color: var(--ink);
        }
        .form-group input:focus {
            border-color: var(--gold-500);
            outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }

        .example-box {
            background: var(--cream);
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-top: 10px;
            border: 1px dashed var(--line);
            font-size: 13px;
        }
        .example-box code {
            background: var(--paper);
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            color: var(--navy-800);
            border: 1px solid var(--line);
        }

        /* ============ ALERTS ============ */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
        }
        .alert i { font-size: 18px; margin-top: 1px; }
        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }
        .alert-success {
            background: var(--success-bg);
            color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }
        .alert .close-btn {
            margin-left: auto;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            color: inherit;
            opacity: .6;
            transition: opacity .2s;
        }
        .alert .close-btn:hover { opacity: 1; }

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
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        /* ============ INFO BADGES ============ */
        .info-badges {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
        }
        .info-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--ink-soft);
        }
        .info-badge .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .info-badge .dot.gold { background: var(--gold-500); }
        .info-badge .dot.green { background: var(--success-ink); }
        .info-badge .dot.red { background: var(--danger-ink); }
        .info-badge strong { color: var(--ink); }

        .tip-box {
            background: var(--cream);
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            margin-top: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid var(--line);
        }
        .tip-box i { color: var(--gold-500); font-size: 18px; margin-top: 2px; }
        .tip-box p { font-size: 13.5px; color: var(--ink-soft); }

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
            .form-container { padding: 24px 20px 30px; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .main-header h1 { font-size: 22px; }
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; justify-content: center; }
            .form-container { padding: 20px 16px 24px; }
            .info-badges { flex-direction: column; gap: 8px; }
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
                <a href="create-agent.php" class="active">
                    <span class="icon"><i class="fas fa-user-plus"></i></span>
                    <span>Create Agent</span>
                </a>
                <a href="agents.php">
                    <span class="icon"><i class="fas fa-users"></i></span>
                    <span>All Agents</span>
                </a>
                <div class="nav-divider"></div>
                <a href="agents.php?status=pending">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Pending Verification</span>
                </a>
                <a href="agents.php?status=verified">
                    <span class="icon"><i class="fas fa-check-circle"></i></span>
                    <span>Verified Agents</span>
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
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1>Create Agent</h1>
                        <div class="subtitle">Add a new agent to the system</div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="agents.php" class="btn btn-secondary" style="padding:8px 18px; font-size:13px;">
                        <i class="fas fa-arrow-left"></i> Back to Agents
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

            <div class="form-container">
                <div class="form-header">
                    <h2><i class="fas fa-user-plus" style="color:var(--gold-500); margin-right:10px;"></i>New Agent Registration</h2>
                    <p>Enter the agent details below. All fields marked with <span style="color:var(--danger-ink);">*</span> are required.</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" id="alertBox">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?php echo $error; ?></div>
                        <button class="close-btn" onclick="this.closest('.alert').remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success" id="alertBox">
                        <i class="fas fa-check-circle"></i>
                        <div><?php echo $success; ?></div>
                        <button class="close-btn" onclick="this.closest('.alert').remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="post" id="createAgentForm" novalidate>
                    <?php echo csrfField(); ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="agent_id">Agent ID <span class="required">*</span></label>
                            <input type="text"
                                   id="agent_id"
                                   name="agent_id"
                                   value="<?php echo htmlspecialchars($agentId); ?>"
                                   placeholder="e.g., BGS1001"
                                   required
                                   pattern="BGS[0-9]{4}"
                                   title="Must be BGS followed by 4 digits (e.g., BGS1001)"
                                   autocomplete="off">
                            <span class="help-text">Format: <strong>BGS</strong> followed by <strong>4 digits</strong></span>
                        </div>

                        <div class="form-group">
                            <label for="dob">Date of Birth <span class="required">*</span></label>
                            <input type="text"
                                   id="dob"
                                   name="dob"
                                   value="<?php echo htmlspecialchars($dob); ?>"
                                   placeholder="DD/MM/YYYY"
                                   required
                                   autocomplete="off">
                            <span class="help-text">Format: Day/Month/Year (e.g., 15/08/1990)</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="name">Optional Initial Name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="<?php echo htmlspecialchars($name); ?>"
                               placeholder="Employee's full name if known"
                               autocomplete="off">
                        <span class="help-text">Can be updated later by the employee during onboarding</span>
                    </div>

                    <div class="example-box">
                        <strong>Valid Examples:</strong>
                        <code>BGS1001</code>
                        <code>BGS1002</code>
                        <code>BGS1003</code>
                        <code>BGS1010</code>
                        <code>BGS9999</code>
                        <br><br>
                        <strong>Invalid Examples:</strong>
                        <code style="color: var(--danger-ink);">BGS10001</code>
                        <span style="color: var(--danger-ink); font-size: 13px;">(5 digits - too many)</span>
                        <br>
                        <code style="color: var(--danger-ink);">BGS101</code>
                        <span style="color: var(--danger-ink); font-size: 13px;">(3 digits - too few)</span>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-gold">
                            <i class="fas fa-plus"></i> Create Agent
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Clear Form
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>

                <div class="info-badges">
                    <div class="info-badge">
                        <span class="dot gold"></span>
                        <strong>Format:</strong> BGS + 4 digits
                    </div>
                    <div class="info-badge">
                        <span class="dot green"></span>
                        <strong>Unique:</strong> Each ID must be unique
                    </div>
                    <div class="info-badge">
                        <span class="dot red"></span>
                        <strong>Required:</strong> Agent ID cannot be empty
                    </div>
                    <div class="info-badge">
                        <i class="fas fa-users" style="color:var(--ink-soft);"></i>
                        <span><strong>Total Agents:</strong> <?php echo $agentCount; ?></span>
                    </div>
                </div>

                <div class="tip-box">
                    <i class="fas fa-lightbulb"></i>
                    <p><strong>💡 Tip:</strong> The employee will use this Agent ID and their Date of Birth to login. Make sure to share these credentials securely.</p>
                </div>
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

        // Auto-close alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(function(alert) {
            setTimeout(function() {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(function() {
                    if (alert.parentNode) alert.remove();
                }, 500);
            }, 5000);
        });

        // Form validation - real-time feedback
        document.getElementById('agent_id')?.addEventListener('input', function() {
            const val = this.value.toUpperCase();
            this.value = val;
            if (val && !/^BGS[0-9]{4}$/.test(val)) {
                this.style.borderColor = '#b3352f';
                this.style.backgroundColor = '#fbe7e7';
            } else if (val) {
                this.style.borderColor = '#1e7a41';
                this.style.backgroundColor = '#e4f4e8';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });

        // DOB validation - auto format
        document.getElementById('dob')?.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            if (val.length > 2 && val.length <= 4) {
                val = val.substring(0, 2) + '/' + val.substring(2);
            } else if (val.length > 4) {
                val = val.substring(0, 2) + '/' + val.substring(2, 4) + '/' + val.substring(4, 8);
            }
            this.value = val;
        });

        // Form validation before submit
        document.getElementById('createAgentForm')?.addEventListener('submit', function(e) {
            const agentId = document.getElementById('agent_id');
            const dob = document.getElementById('dob');
            let errors = [];
            
            if (agentId && !/^BGS[0-9]{4}$/.test(agentId.value)) {
                errors.push('Please enter a valid Agent ID (BGS + 4 digits)');
                agentId.style.borderColor = '#b3352f';
                agentId.style.backgroundColor = '#fbe7e7';
            }
            
            if (dob && !/^\d{2}\/\d{2}\/\d{4}$/.test(dob.value)) {
                errors.push('Please enter a valid Date of Birth (DD/MM/YYYY)');
                dob.style.borderColor = '#b3352f';
                dob.style.backgroundColor = '#fbe7e7';
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert(errors.join('\n'));
                return false;
            }
        });
    </script>
</body>
</html>