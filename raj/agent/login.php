<?php
// /raj/agent/login.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect
if (isset($_SESSION['agent_logged_in']) && $_SESSION['agent_logged_in'] === true) {
    $agentId = $_SESSION['agent_id'] ?? '';
    if ($agentId) {
        $agent = getAgent($agentId);
        if ($agent && $agent['onboarding_completed'] == 1) {
            header('Location: dashboard.php');
        } else {
            header('Location: onboarding.php');
        }
        exit;
    }
}

$error = '';
$lockout = false;

// Rate limiting
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
    $lockout = true;
    $error = 'Too many failed attempts. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockout) {
    $agentId = trim(strtoupper($_POST['agent_id'] ?? ''));
    $dob = trim($_POST['dob'] ?? '');
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (empty($agentId)) {
        $error = 'Agent ID is required.';
    } elseif (empty($dob)) {
        $error = 'Date of Birth is required.';
    } else {
        // Validate DOB format
        $dobParts = explode('/', $dob);
        if (count($dobParts) != 3 || !checkdate((int)$dobParts[1], (int)$dobParts[0], (int)$dobParts[2])) {
            $error = 'Invalid Date of Birth format. Use DD/MM/YYYY.';
        } else {
            // Try to login
            $agent = getAgent($agentId);
            
            if (!$agent) {
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                $error = 'Invalid Agent ID or Date of Birth.';
            } elseif ($agent['account_status'] !== 'active') {
                $error = 'Account is not active. Please contact admin.';
            } else {
                // Verify DOB
                $decryptedDob = decryptData($agent['date_of_birth']);
                
                if ($decryptedDob === $dob) {
                    // Login successful
                    $_SESSION['agent_logged_in'] = true;
                    $_SESSION['agent_id'] = $agentId;
                    unset($_SESSION['login_attempts']);
                    
                    addAuditLog($agentId, 'login_success', 'Agent logged in');
                    
                    // Redirect based on onboarding status
                    if ($agent['onboarding_completed'] == 1) {
                        header('Location: dashboard.php');
                    } else {
                        header('Location: onboarding.php');
                    }
                    exit;
                } else {
                    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                    $error = 'Invalid Agent ID or Date of Birth.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Login - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

 <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
<link rel="apple-touch-icon" href="/bettergrowthsolutions.jpg" />

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
            --danger-bg: #fbe7e7;
            --danger-ink: #b3352f;
            --shadow-lg: 0 20px 60px rgba(12,26,56,.18);
            --shadow-md: 0 6px 20px rgba(12,26,56,.08);
            --ease: cubic-bezier(.22,.61,.36,1);
            --radius: 16px;
            --radius-sm: 10px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: var(--cream);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Background decorative elements */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            right: -30%;
            width: 800px;
            height: 800px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(201,150,47,.06), transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -40%;
            left: -20%;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(26,60,110,.04), transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .login-container {
            display: flex;
            max-width: 960px;
            width: 100%;
            background: var(--paper);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        /* ============ LEFT PANEL ============ */
        .login-left {
            flex: 1;
            padding: 50px 45px;
            background:
                radial-gradient(140% 120% at 100% 0%, rgba(201,150,47,.12), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            min-height: 400px;
        }

        .login-left .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 30px;
        }

        .login-left .brand .logo-mark {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Fraunces', serif;
            font-weight: 700;
            font-size: 22px;
            color: var(--navy-950);
        }

        .login-left .brand .brand-text {
            font-family: 'Fraunces', serif;
        }

        .login-left .brand .brand-text h1 {
            font-size: 24px;
            font-weight: 600;
            color: #fff;
            letter-spacing: -.3px;
            line-height: 1.2;
        }

        .login-left .brand .brand-text span {
            font-size: 12px;
            color: var(--gold-300);
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .login-left .welcome-text {
            margin-top: 20px;
        }

        .login-left .welcome-text h2 {
            font-family: 'Fraunces', serif;
            font-size: 28px;
            font-weight: 600;
            color: #fff;
            line-height: 1.3;
            margin-bottom: 12px;
        }

        .login-left .welcome-text h2 .highlight {
            color: var(--gold-300);
        }

        .login-left .welcome-text p {
            color: rgba(233,238,250,.7);
            font-size: 15px;
            line-height: 1.6;
            max-width: 380px;
        }

        .login-left .features {
            margin-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .login-left .features .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(233,238,250,.8);
            font-size: 14px;
        }

        .login-left .features .feature-item i {
            width: 20px;
            color: var(--gold-300);
            font-size: 14px;
        }

        /* ============ RIGHT PANEL ============ */
        .login-right {
            flex: 0 0 400px;
            padding: 50px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--paper);
        }

        .login-right .login-header {
            margin-bottom: 32px;
        }

        .login-right .login-header h2 {
            font-family: 'Fraunces', serif;
            font-size: 26px;
            font-weight: 600;
            color: var(--navy-950);
            letter-spacing: -.3px;
        }

        .login-right .login-header p {
            color: var(--ink-soft);
            font-size: 14px;
            margin-top: 4px;
        }

        .login-right .error {
            padding: 14px 18px;
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            border-left: 4px solid var(--danger-ink);
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-8px); }
            40%, 80% { transform: translateX(8px); }
        }

        .login-right .error i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: var(--ink);
            margin-bottom: 6px;
        }

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-soft);
            font-size: 16px;
            transition: color .3s var(--ease);
        }

        .form-group input {
            width: 100%;
            padding: 13px 16px 13px 44px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            background: var(--cream);
            color: var(--ink);
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease), background .25s var(--ease);
        }

        .form-group input:focus {
            border-color: var(--gold-500);
            outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }

        .form-group input::placeholder {
            color: #aaa;
        }

        .form-group .input-hint {
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 4px;
            display: block;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
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

        .btn-gold:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-gold i {
            font-size: 16px;
        }

        .login-footer {
            margin-top: 24px;
            text-align: center;
            color: var(--ink-soft);
            font-size: 13px;
        }

        .login-footer a {
            color: var(--navy-800);
            text-decoration: none;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 850px) {
            .login-container {
                flex-direction: column;
                max-width: 440px;
            }

            .login-left {
                padding: 35px 30px;
                min-height: auto;
            }

            .login-left .welcome-text h2 {
                font-size: 24px;
            }

            .login-right {
                flex: 1;
                padding: 35px 30px;
            }

            .login-left .features {
                display: none;
            }
        }

        @media (max-width: 480px) {
            body { padding: 10px; }

            .login-left {
                padding: 25px 20px;
            }

            .login-left .brand .logo-mark {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }

            .login-left .brand .brand-text h1 {
                font-size: 20px;
            }

            .login-left .welcome-text h2 {
                font-size: 20px;
            }

            .login-right {
                padding: 25px 20px;
            }

            .login-right .login-header h2 {
                font-size: 22px;
            }

            .form-group input {
                padding: 11px 14px 11px 40px;
                font-size: 14px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }
        }

        /* Agent ID pattern hint */
        .pattern-hint {
            display: inline-block;
            background: var(--cream);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--ink-soft);
            font-weight: 500;
            margin-top: 4px;
        }
        .pattern-hint code {
            background: var(--paper);
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: var(--navy-800);
            border: 1px solid var(--line);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Panel -->
        <div class="login-left">
            <div class="brand">
                <div class="logo-mark">BG</div>
                <div class="brand-text">
                    <h1>BetterGrowth</h1>
                    <span>Solutions</span>
                </div>
            </div>
            <div class="welcome-text">
                <h2>Welcome to <span class="highlight">Employee Portal</span></h2>
                <p>Secure onboarding & verification system for BetterGrowth Solutions employees.</p>
            </div>
            <div class="features">
                <div class="feature-item">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure & Encrypted Access</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-user-check"></i>
                    <span>Complete Verification Process</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-file-alt"></i>
                    <span>Document Management</span>
                </div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="login-right">
            <div class="login-header">
                <h2>Employee Login</h2>
                <p>Enter your credentials to access your account</p>
            </div>

            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($lockout): ?>
                <div class="error">
                    <i class="fas fa-lock"></i>
                    Too many failed attempts. Please try again later.
                </div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrfField(); ?>

                <div class="form-group">
                    <label for="agent_id">Agent ID</label>
                    <div class="input-wrapper">
                        <i class="fas fa-id-badge"></i>
                        <input type="text"
                               id="agent_id"
                               name="agent_id"
                               placeholder="e.g., BGS1001"
                               value="<?php echo isset($_POST['agent_id']) ? htmlspecialchars($_POST['agent_id']) : ''; ?>"
                               required
                               pattern="BGS[0-9]{4}"
                               title="Must be BGS followed by 4 digits"
                               autofocus>
                    </div>
                    <span class="pattern-hint">Format: <code>BGS</code> + 4 digits (e.g., <code>BGS1001</code>)</span>
                </div>

                <div class="form-group">
                    <label for="dob">Date of Birth</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-alt"></i>
                        <input type="text"
                               id="dob"
                               name="dob"
                               placeholder="DD/MM/YYYY"
                               value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>"
                               required>
                    </div>
                    <span class="input-hint">Format: Day/Month/Year (e.g., <strong>15/08/1990</strong>)</span>
                </div>

                <button type="submit" class="btn btn-gold" <?php echo $lockout ? 'disabled' : ''; ?>>
                    <?php echo $lockout ? '<i class="fas fa-lock"></i> Locked' : '<i class="fas fa-sign-in-alt"></i> Login'; ?>
                </button>
            </form>

            <div class="login-footer">
                <p>Contact your administrator if you need assistance.</p>
            </div>
        </div>
    </div>

    <script>
        // Auto-dismiss error after 5 seconds
        const errorBox = document.querySelector('.error');
        if (errorBox) {
            setTimeout(function() {
                errorBox.style.opacity = '0';
                errorBox.style.transition = 'opacity 0.5s ease';
                setTimeout(function() {
                    if (errorBox.parentNode) errorBox.remove();
                }, 500);
            }, 5000);
        }

        // Agent ID formatting - auto uppercase
        document.getElementById('agent_id')?.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // DOB auto-format
        document.getElementById('dob')?.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            if (val.length > 2 && val.length <= 4) {
                val = val.substring(0, 2) + '/' + val.substring(2);
            } else if (val.length > 4) {
                val = val.substring(0, 2) + '/' + val.substring(2, 4) + '/' + val.substring(4, 8);
            }
            this.value = val;
        });

        // Enter key submits form - default behavior
    </script>
</body>
</html>