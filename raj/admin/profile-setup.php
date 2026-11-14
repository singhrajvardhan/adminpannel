<?php
// /raj/admin/profile-setup.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$adminId = $_SESSION['admin_id'] ?? 1;
$adminUsername = $_SESSION['admin_username'] ?? 'admin';

// Get current admin data
$adminData = executeQuery("SELECT * FROM admins WHERE id = ? OR username = ?", [$adminId, $adminUsername]);
$admin = ($adminData && $adminData->num_rows > 0) ? $adminData->fetch_assoc() : null;

if (!$admin) {
    die("Admin record not found. Please contact support.");
}

$message = '';
$msgType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // If changing password
    if (!empty($currentPassword) || !empty($newPassword) || !empty($confirmPassword)) {
        if (empty($currentPassword)) {
            $message = "Please enter your current password to change it.";
            $msgType = 'error';
        } elseif (empty($newPassword)) {
            $message = "Please enter a new password.";
            $msgType = 'error';
        } elseif (strlen($newPassword) < 6) {
            $message = "New password must be at least 6 characters.";
            $msgType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = "New passwords do not match.";
            $msgType = 'error';
        } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
            $message = "Current password is incorrect.";
            $msgType = 'error';
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            executeQuery("UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?", [$hashedPassword, $admin['id']]);
            $message = "✅ Password changed successfully!";
            $msgType = 'success';
        }
    }
    
    // Update profile info
    if (empty($message) || $msgType === 'success') {
        if (empty($fullName)) {
            if (empty($message)) {
                $message = "Please enter your full name.";
                $msgType = 'error';
            }
        } elseif (empty($email)) {
            if (empty($message)) {
                $message = "Please enter your email address.";
                $msgType = 'error';
            }
        } else {
            $result = executeQuery(
                "UPDATE admins SET full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?",
                [$fullName, $email, $phone, $admin['id']]
            );
            
            if ($result !== false) {
                if (empty($message)) {
                    $message = "✅ Profile updated successfully!";
                    $msgType = 'success';
                }
                
                // Update session
                $_SESSION['admin_name'] = $fullName;
                $_SESSION['admin_email'] = $email;
                
                // Refresh data
                $adminData = executeQuery("SELECT * FROM admins WHERE id = ?", [$admin['id']]);
                $admin = ($adminData && $adminData->num_rows > 0) ? $adminData->fetch_assoc() : $admin;
            } else {
                $message = "Error updating profile.";
                $msgType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Setup - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --cream: #f8f7f3; --paper: #ffffff;
            --ink: #1c2536; --ink-soft: #667085; --line: #ecebe6;
            --success: #1e7a41; --danger: #b3352f; --info: #29538c;
            --radius: 18px; --radius-sm: 12px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover { background: rgba(255,255,255,.12); }

        .content { max-width: 700px; margin: 0 auto; padding: 24px 28px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 20px; }

        .alert {
            padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px;
        }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 28px 32px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); margin-bottom: 20px;
        }
        .card h3 {
            font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950);
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
        }
        .card h3 i { color: var(--gold-500); }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px;
        }
        .form-group input {
            width: 100%; padding: 12px 16px; border: 2px solid var(--line); border-radius: 10px;
            font-family: 'Inter', sans-serif; font-size: 14px; transition: border-color .3s;
            background: var(--paper);
        }
        .form-group input:focus { border-color: var(--gold-500); outline: none; }
        .form-group input:read-only { background: var(--cream); color: var(--ink-soft); cursor: not-allowed; }
        .form-group .input-hint { font-size: 10px; color: var(--ink-soft); margin-top: 4px; }

        .btn {
            padding: 12px 28px; border: none; border-radius: 10px; font-weight: 700;
            font-size: 14px; cursor: pointer; font-family: 'Inter', sans-serif;
            transition: transform .2s, box-shadow .2s; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-primary { background: var(--gold-500); color: var(--navy-950); }
        .btn-outline { background: var(--paper); color: var(--ink-soft); border: 2px solid var(--line); }

        .divider {
            border: none; border-top: 1px solid var(--line); margin: 20px 0;
        }

        .info-box {
            background: #e6eefb; border-radius: 10px; padding: 14px 18px; margin-bottom: 16px;
            font-size: 12px; color: var(--info); display: flex; align-items: flex-start; gap: 10px;
        }
        .info-box i { font-size: 16px; margin-top: 2px; flex-shrink: 0; }

        .profile-header {
            display: flex; align-items: center; gap: 20px; margin-bottom: 20px;
            padding-bottom: 20px; border-bottom: 1px solid var(--line);
        }
        .profile-avatar {
            width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--navy-950);
            flex-shrink: 0;
        }
        .profile-info h2 { font-family: 'Fraunces', serif; font-size: 20px; color: var(--navy-950); }
        .profile-info .role { font-size: 12px; color: var(--ink-soft); }
        .profile-info .status {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 600; background: #e4f4e8; color: var(--success); margin-top: 4px;
        }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 20px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .card { padding: 20px 16px; }
            .profile-header { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">👤 Admin Profile</div>
        <div class="nav">
            <a href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="profile-setup.php"><i class="fas fa-user-cog"></i> Profile</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">👤 Profile Settings</h1>
        <p class="page-subtitle">Manage your profile information and password</p>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($admin['full_name'] ?? $admin['username'] ?? 'A', 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($admin['full_name'] ?? 'Administrator'); ?></h2>
                    <div class="role">@<?php echo htmlspecialchars($admin['username'] ?? 'admin'); ?></div>
                    <div class="status">✅ Active</div>
                </div>
            </div>

            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <span>This email will be used for <strong>password reset OTP</strong> and important system notifications. Make sure it's correct.</span>
            </div>

            <!-- Profile Info Form -->
            <form method="POST">
                <h3 style="margin-top:0;"><i class="fas fa-user-edit"></i> Personal Information</h3>
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>" readonly>
                    <div class="input-hint">Username cannot be changed</div>
                </div>
                
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>" 
                           placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" 
                           placeholder="your-email@gmail.com" required>
                    <div class="input-hint">Used for password recovery and notifications</div>
                </div>
                
                <div class="form-group">
                    <label>Phone Number (Optional)</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>" 
                           placeholder="+91 9876543210">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Profile
                </button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card">
            <form method="POST">
                <h3 style="margin-top:0;"><i class="fas fa-lock"></i> Change Password</h3>
                
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" placeholder="Enter current password">
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" placeholder="Min 6 characters" minlength="6">
                </div>
                
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter new password" minlength="6">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-key"></i> Update Password
                </button>
                
                <p style="font-size:11px;color:var(--ink-soft);margin-top:10px;">
                    <i class="fas fa-info-circle"></i> Leave password fields empty if you don't want to change it.
                </p>
            </form>
        </div>

        <!-- Quick Links -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
            <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="forgot-password.php" class="btn btn-outline"><i class="fas fa-redo"></i> Forgot Password Page</a>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © <?php echo date('Y'); ?> <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>
</body>
</html>