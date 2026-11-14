<?php
// /raj/admin/forgot-password.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$step = $_GET['step'] ?? 'email'; // email | otp | reset
$error = '';
$success = '';

// ============================================================
// STEP 1: Send OTP to Email
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        // Check if admin exists
      $admin = executeQuery("SELECT id, username FROM admins WHERE username = ? OR email = ?", [$email, $email]);
        
        if ($admin && $admin->num_rows > 0) {
            $adminData = $admin->fetch_assoc();
            $adminId = $adminData['id'];
            
            // Generate OTP
            $otp = sprintf("%06d", rand(100000, 999999));
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            // Delete old tokens for this admin
            executeQuery("DELETE FROM admin_password_resets WHERE admin_id = ?", [$adminId]);
            
            // Insert new token
            executeQuery(
                "INSERT INTO admin_password_resets (admin_id, email, otp, token, expires_at) VALUES (?, ?, ?, ?, ?)",
                [$adminId, $email, $otp, $token, $expires]
            );
            
            // Send email
            $subject = "Password Reset OTP - BetterGrowth Solutions";
            $message = "
            <html>
            <body style='font-family: Arial, sans-serif; padding: 20px;'>
                <h2>🔐 Password Reset Request</h2>
                <p>You have requested to reset your admin password.</p>
                <div style='background: #f8f7f3; padding: 20px; border-radius: 10px; text-align: center; margin: 20px 0;'>
                    <p style='font-size: 12px; color: #666;'>Your OTP Code:</p>
                    <h1 style='font-size: 36px; color: #c9962f; letter-spacing: 8px; margin: 10px 0;'>{$otp}</h1>
                    <p style='font-size: 11px; color: #999;'>This code expires in 10 minutes</p>
                </div>
                <p style='color: #666; font-size: 12px;'>If you did not request this, please ignore this email.</p>
                <hr>
                <p style='color: #999; font-size: 11px;'>BetterGrowth Solutions | Admin Panel</p>
            </body>
            </html>";
            
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: BetterGrowth Solutions <" . SMTP_FROM . ">\r\n";
            
            $mailSent = mail($email, $subject, $message, $headers);
            
            if ($mailSent) {
                $success = "✅ OTP has been sent to your email. Please check your inbox.";
                $step = 'otp';
            } else {
                // Still proceed in development
                $success = "✅ OTP generated. (Email sending is configured but may not work on localhost)";
                $step = 'otp';
            }
            
            // Store in session for verification
            $_SESSION['reset_admin_id'] = $adminId;
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_otp'] = $otp; // Only for dev - remove in production
            $_SESSION['reset_token'] = $token;
            
        } else {
            $error = "No admin account found with that email/username.";
        }
    }
}

// ============================================================
// STEP 2: Verify OTP
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $enteredOtp = trim($_POST['otp'] ?? '');
    $adminId = $_SESSION['reset_admin_id'] ?? 0;
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($enteredOtp)) {
        $error = "Please enter the OTP code.";
        $step = 'otp';
    } else {
        // Verify OTP from database
        $verify = executeQuery(
            "SELECT * FROM admin_password_resets WHERE admin_id = ? AND otp = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
            [$adminId, $enteredOtp]
        );
        
        if ($verify && $verify->num_rows > 0) {
            // OTP verified
            executeQuery("UPDATE admin_password_resets SET used = 1 WHERE admin_id = ? AND otp = ?", [$adminId, $enteredOtp]);
            $success = "✅ OTP verified! Please set your new password.";
            $step = 'reset';
        } else {
            $error = "❌ Invalid or expired OTP. Please try again.";
            $step = 'otp';
        }
    }
}

// ============================================================
// STEP 3: Reset Password
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $adminId = $_SESSION['reset_admin_id'] ?? 0;
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = "Please fill in both password fields.";
        $step = 'reset';
    } elseif (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters.";
        $step = 'reset';
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
        $step = 'reset';
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$result = executeQuery("UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?", [$hashedPassword, $adminId]);        
        if ($result !== false) {
            $success = "✅ Password reset successful! You can now login with your new password.";
            $step = 'done';
            
            // Clear session
            unset($_SESSION['reset_admin_id'], $_SESSION['reset_email'], $_SESSION['reset_otp'], $_SESSION['reset_token']);
        } else {
            $error = "Failed to reset password. Please try again.";
            $step = 'reset';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --cream: #f8f7f3; --paper: #ffffff;
            --ink: #1c2536; --ink-soft: #667085; --line: #ecebe6;
            --success: #1e7a41; --danger: #b3352f; --info: #29538c;
            --radius: 16px; --radius-sm: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0c1a38 0%, #163063 50%, #1a3a6e 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .container {
            background: var(--paper); border-radius: var(--radius); padding: 40px 36px;
            max-width: 440px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .logo {
            text-align: center; margin-bottom: 24px;
        }
        .logo .icon {
            width: 60px; height: 60px; border-radius: 16px;
            background: linear-gradient(135deg, #e9c877, #c9962f);
            display: inline-flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 24px; color: #163063;
        }
        .logo h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); margin-top: 10px; }
        .logo p { font-size: 12px; color: var(--ink-soft); }

        .alert {
            padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px;
        }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 12px; font-weight: 600; color: var(--ink-soft);
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px;
        }
        .form-group input {
            width: 100%; padding: 12px 16px; border: 2px solid var(--line); border-radius: 10px;
            font-family: 'Inter', sans-serif; font-size: 14px; transition: border-color .3s;
        }
        .form-group input:focus { border-color: var(--gold-500); outline: none; }
        .form-group .input-icon { position: relative; }
        .form-group .input-icon i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--ink-soft);
        }
        .form-group .input-icon input { padding-left: 42px; }

        .otp-input {
            text-align: center; font-size: 28px !important; letter-spacing: 12px;
            font-weight: 700; font-family: 'Fraunces', serif !important;
        }

        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 10px; font-weight: 700;
            font-size: 14px; cursor: pointer; font-family: 'Inter', sans-serif;
            transition: transform .2s, box-shadow .2s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.15); }
        .btn-primary { background: var(--navy-800); color: #fff; }
        .btn-gold { background: var(--gold-500); color: var(--navy-950); }

        .back-link {
            text-align: center; margin-top: 16px; font-size: 13px;
        }
        .back-link a { color: var(--info); text-decoration: none; font-weight: 600; }
        .back-link a:hover { text-decoration: underline; }

        .step-indicator {
            display: flex; justify-content: center; gap: 8px; margin-bottom: 20px;
        }
        .step-dot {
            width: 12px; height: 12px; border-radius: 50%; background: var(--line);
        }
        .step-dot.active { background: var(--gold-500); }
        .step-dot.done { background: var(--success); }

        .copyright {
            text-align: center; margin-top: 20px; font-size: 11px; color: var(--ink-soft);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon">BG</div>
            <h2>Forgot Password</h2>
            <p>BetterGrowth Solutions Admin</p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot <?php echo $step === 'email' ? 'active' : ($step !== 'email' ? 'done' : ''); ?>"></div>
            <div class="step-dot <?php echo $step === 'otp' ? 'active' : ($step === 'reset' || $step === 'done' ? 'done' : ''); ?>"></div>
            <div class="step-dot <?php echo $step === 'reset' ? 'active' : ($step === 'done' ? 'done' : ''); ?>"></div>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($step === 'email'): ?>
        <!-- STEP 1: Enter Email -->
        <form method="POST">
            <div class="form-group">
                <label>Email Address or Username</label>
                <div class="input-icon">
                    <i class="fas fa-envelope"></i>
                    <input type="text" name="email" placeholder="Enter your email or username" required autofocus>
                </div>
            </div>
            <button type="submit" name="send_otp" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send OTP
            </button>
        </form>

        <?php elseif ($step === 'otp'): ?>
        <!-- STEP 2: Enter OTP -->
        <form method="POST">
            <p style="font-size:13px;color:var(--ink-soft);margin-bottom:12px;text-align:center;">
                We sent a 6-digit code to <strong><?php echo htmlspecialchars($_SESSION['reset_email'] ?? 'your email'); ?></strong>
            </p>
            <div class="form-group">
                <label>Enter OTP Code</label>
                <input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6" required autofocus oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <button type="submit" name="verify_otp" class="btn btn-gold">
                <i class="fas fa-check-circle"></i> Verify OTP
            </button>
            <div style="text-align:center;margin-top:10px;">
                <button type="submit" name="send_otp" style="background:none;border:none;color:var(--info);cursor:pointer;font-size:12px;text-decoration:underline;">
                    Resend OTP
                </button>
            </div>
        </form>

        <?php elseif ($step === 'reset'): ?>
        <!-- STEP 3: New Password -->
        <form method="POST" id="resetForm">
            <div class="form-group">
                <label>New Password</label>
                <div class="input-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="new_password" id="newPass" placeholder="Min 6 characters" required minlength="6" autofocus>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="input-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm_password" id="confirmPass" placeholder="Re-enter password" required minlength="6">
                </div>
                <p id="passMatch" style="font-size:11px;margin-top:4px;display:none;"></p>
            </div>
            <button type="submit" name="reset_password" class="btn btn-gold">
                <i class="fas fa-save"></i> Reset Password
            </button>
        </form>

        <?php elseif ($step === 'done'): ?>
        <!-- DONE -->
        <div style="text-align:center;padding:20px 0;">
            <div style="font-size:60px;margin-bottom:10px;">✅</div>
            <h3 style="font-family:'Fraunces',serif;color:var(--navy-950);">Password Reset Complete!</h3>
            <p style="color:var(--ink-soft);font-size:13px;margin:10px 0;">You can now login with your new password.</p>
            <a href="login.php" class="btn btn-primary" style="display:inline-block;text-decoration:none;margin-top:10px;">
                <i class="fas fa-sign-in-alt"></i> Go to Login
            </a>
        </div>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>

        <div class="copyright">
            © <?php echo date('Y'); ?> Rajvardhan Singh & Family. All Rights Reserved.
        </div>
    </div>

    <script>
        // Password match check
        const newPass = document.getElementById('newPass');
        const confirmPass = document.getElementById('confirmPass');
        const passMatch = document.getElementById('passMatch');
        
        if (newPass && confirmPass) {
            confirmPass.addEventListener('input', function() {
                if (this.value === '') {
                    passMatch.style.display = 'none';
                } else if (this.value === newPass.value) {
                    passMatch.style.display = 'block';
                    passMatch.style.color = '#1e7a41';
                    passMatch.textContent = '✅ Passwords match';
                } else {
                    passMatch.style.display = 'block';
                    passMatch.style.color = '#b3352f';
                    passMatch.textContent = '❌ Passwords do not match';
                }
            });
        }
    </script>
</body>
</html>