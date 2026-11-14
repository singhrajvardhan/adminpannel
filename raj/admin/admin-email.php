<?php
// /raj/admin/admin-email.php
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

// Get admin emails
$emailsResult = executeQuery("SELECT * FROM admin_email_settings ORDER BY is_primary DESC, id ASC");

// Get agents for selection
$agentsResult = executeQuery("SELECT agent_id, full_name, email FROM agents ORDER BY full_name ASC");

// Handle email send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $to = $_POST['to'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $report_type = $_POST['report_type'] ?? 'daily';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $selected_agents = $_POST['selected_agents'] ?? [];
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (empty($subject) || empty($message)) {
        $error = 'Subject and message are required.';
    } else {
        // Build recipient list
        $recipients = [];
        if ($to === 'all') {
            // Get all agents
            $allAgents = executeQuery("SELECT email FROM agents WHERE email IS NOT NULL AND email != ''");
            while ($row = $allAgents->fetch_assoc()) {
                $recipients[] = $row['email'];
            }
            // Add admin emails
            $adminEmails = executeQuery("SELECT email FROM admin_email_settings");
            while ($row = $adminEmails->fetch_assoc()) {
                $recipients[] = $row['email'];
            }
        } elseif ($to === 'admin') {
            $adminEmails = executeQuery("SELECT email FROM admin_email_settings");
            while ($row = $adminEmails->fetch_assoc()) {
                $recipients[] = $row['email'];
            }
        } elseif ($to === 'selected' && !empty($selected_agents)) {
            foreach ($selected_agents as $agentId) {
                $agent = getAgent($agentId);
                if ($agent && !empty($agent['email'])) {
                    $recipients[] = $agent['email'];
                }
            }
        } elseif (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $to;
        } else {
            $error = 'Invalid recipient selection.';
        }
        
        if (empty($recipients)) {
            $error = 'No valid recipients found.';
        }
        
        if (empty($error)) {
            // Generate report data
            $reportData = generateReportData($report_type, $date_from, $date_to, $selected_agents);
            
            // Send email
            $sentCount = 0;
            foreach ($recipients as $email) {
                $fullMessage = $message . "\n\n" . $reportData;
                if (sendEmailReport($email, $subject, $fullMessage)) {
                    $sentCount++;
                }
            }
            
            $success = "Email sent to $sentCount recipient(s).";
        }
    }
}

// Handle adding email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_email'])) {
    $newEmail = trim($_POST['new_email'] ?? '');
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (!validateEmail($newEmail)) {
        $error = 'Invalid email address.';
    } else {
        // Check if email exists
        $check = executeQuery("SELECT id FROM admin_email_settings WHERE email = ?", [$newEmail]);
        if ($check && $check->num_rows > 0) {
            $error = 'Email already exists.';
        } else {
            // If primary, unset other primary
            if ($isPrimary) {
                executeQuery("UPDATE admin_email_settings SET is_primary = 0");
            }
            executeQuery("INSERT INTO admin_email_settings (email, is_primary) VALUES (?, ?)", [$newEmail, $isPrimary]);
            $success = "Email added successfully!";
            // Refresh emails
            $emailsResult = executeQuery("SELECT * FROM admin_email_settings ORDER BY is_primary DESC, id ASC");
        }
    }
}

// Handle delete email
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $check = executeQuery("SELECT is_primary FROM admin_email_settings WHERE id = ?", [$id]);
    if ($check && $row = $check->fetch_assoc()) {
        if ($row['is_primary'] == 1) {
            $error = 'Cannot delete primary email.';
        } else {
            executeQuery("DELETE FROM admin_email_settings WHERE id = ?", [$id]);
            $success = 'Email deleted successfully!';
            $emailsResult = executeQuery("SELECT * FROM admin_email_settings ORDER BY is_primary DESC, id ASC");
        }
    }
}

// Handle set primary
if (isset($_GET['set_primary'])) {
    $id = (int)$_GET['set_primary'];
    executeQuery("UPDATE admin_email_settings SET is_primary = 0");
    executeQuery("UPDATE admin_email_settings SET is_primary = 1 WHERE id = ?", [$id]);
    $success = 'Primary email updated!';
    $emailsResult = executeQuery("SELECT * FROM admin_email_settings ORDER BY is_primary DESC, id ASC");
}

$csrf = generateCsrfToken();

function generateReportData($type, $date_from, $date_to, $agents) {
    $data = "========================================\n";
    $data .= "BETTERGROWTH SOLUTIONS - EMPLOYEE REPORT\n";
    $data .= "========================================\n\n";
    
    if ($type === 'daily') {
        $date = $date_from ?: date('Y-m-d');
        $data .= "Report Date: " . date('d M Y', strtotime($date)) . "\n";
        $data .= "Report Type: Daily Summary\n\n";
        
        $whereClause = "date = '$date'";
        if (!empty($agents)) {
            $agentList = implode("','", $agents);
            $whereClause .= " AND agent_id IN ('$agentList')";
        }
        
        $result = executeQuery("SELECT 
            a.agent_id, a.full_name, a.email,
            att.login_time, att.logout_time, att.job_start_time, att.job_end_time,
            att.lunch_start, att.lunch_end, att.snack_start, att.snack_end,
            att.bio_start, att.bio_end, att.bio_count, att.status
            FROM agents a
            LEFT JOIN attendance att ON a.agent_id = att.agent_id AND att.date = '$date'
            " . (!empty($agents) ? "WHERE a.agent_id IN ('" . implode("','", $agents) . "')" : "")
        );
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data .= "Agent: " . $row['agent_id'] . " - " . $row['full_name'] . "\n";
                $data .= "  Login: " . ($row['login_time'] ? date('h:i A', strtotime($row['login_time'])) : 'Not logged in') . "\n";
                $data .= "  Logout: " . ($row['logout_time'] ? date('h:i A', strtotime($row['logout_time'])) : 'Active') . "\n";
                $data .= "  Lunch: " . ($row['lunch_start'] && $row['lunch_end'] ? date('h:i A', strtotime($row['lunch_start'])) . ' - ' . date('h:i A', strtotime($row['lunch_end'])) : 'Not taken') . "\n";
                $data .= "  Snack: " . ($row['snack_start'] && $row['snack_end'] ? date('h:i A', strtotime($row['snack_start'])) . ' - ' . date('h:i A', strtotime($row['snack_end'])) : 'Not taken') . "\n";
                $data .= "  Bio: " . ($row['bio_start'] && $row['bio_end'] ? date('h:i A', strtotime($row['bio_start'])) . ' - ' . date('h:i A', strtotime($row['bio_end'])) : 'Not taken') . " (Count: " . ($row['bio_count'] ?? 0) . ")\n";
                $data .= "  Status: " . ucfirst(str_replace('_', ' ', $row['status'] ?: 'Not Started')) . "\n";
                $data .= "  Email: " . $row['email'] . "\n\n";
            }
        } else {
            $data .= "No data found for the selected date.\n";
        }
    } elseif ($type === 'monthly') {
        $month = $date_from ?: date('Y-m');
        $data .= "Report Month: " . date('M Y', strtotime($month . '-01')) . "\n";
        $data .= "Report Type: Monthly Summary\n\n";
        
        $monthStart = date('Y-m-01', strtotime($month . '-01'));
        $monthEnd = date('Y-m-t', strtotime($month . '-01'));
        
        $whereClause = "att.date BETWEEN '$monthStart' AND '$monthEnd'";
        if (!empty($agents)) {
            $agentList = implode("','", $agents);
            $whereClause .= " AND a.agent_id IN ('$agentList')";
        }
        
        $result = executeQuery("SELECT 
            a.agent_id, a.full_name, a.email,
            COUNT(att.id) as total_days,
            SUM(CASE WHEN att.lunch_start IS NOT NULL AND att.lunch_end IS NOT NULL THEN 1 ELSE 0 END) as lunch_count,
            SUM(CASE WHEN att.snack_start IS NOT NULL AND att.snack_end IS NOT NULL THEN 1 ELSE 0 END) as snack_count,
            SUM(att.bio_count) as total_bio
            FROM agents a
            LEFT JOIN attendance att ON a.agent_id = att.agent_id
            WHERE $whereClause
            GROUP BY a.agent_id
        ");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data .= "Agent: " . $row['agent_id'] . " - " . $row['full_name'] . "\n";
                $data .= "  Working Days: " . ($row['total_days'] ?? 0) . "\n";
                $data .= "  Lunch Breaks: " . ($row['lunch_count'] ?? 0) . "\n";
                $data .= "  Snack Breaks: " . ($row['snack_count'] ?? 0) . "\n";
                $data .= "  Bio Breaks: " . ($row['total_bio'] ?? 0) . "\n";
                $data .= "  Email: " . $row['email'] . "\n\n";
            }
        } else {
            $data .= "No data found for the selected month.\n";
        }
    } elseif ($type === 'custom') {
        $data .= "Report Period: " . date('d M Y', strtotime($date_from)) . " to " . date('d M Y', strtotime($date_to)) . "\n";
        $data .= "Report Type: Custom Period Summary\n\n";
        
        $whereClause = "att.date BETWEEN '$date_from' AND '$date_to'";
        if (!empty($agents)) {
            $agentList = implode("','", $agents);
            $whereClause .= " AND a.agent_id IN ('$agentList')";
        }
        
        $result = executeQuery("SELECT 
            a.agent_id, a.full_name, a.email,
            COUNT(att.id) as total_days,
            SUM(CASE WHEN att.lunch_start IS NOT NULL AND att.lunch_end IS NOT NULL THEN 1 ELSE 0 END) as lunch_count,
            SUM(CASE WHEN att.snack_start IS NOT NULL AND att.snack_end IS NOT NULL THEN 1 ELSE 0 END) as snack_count,
            SUM(att.bio_count) as total_bio
            FROM agents a
            LEFT JOIN attendance att ON a.agent_id = att.agent_id
            WHERE $whereClause
            GROUP BY a.agent_id
        ");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data .= "Agent: " . $row['agent_id'] . " - " . $row['full_name'] . "\n";
                $data .= "  Active Days: " . ($row['total_days'] ?? 0) . "\n";
                $data .= "  Lunch Breaks: " . ($row['lunch_count'] ?? 0) . "\n";
                $data .= "  Snack Breaks: " . ($row['snack_count'] ?? 0) . "\n";
                $data .= "  Bio Breaks: " . ($row['total_bio'] ?? 0) . "\n";
                $data .= "  Email: " . $row['email'] . "\n\n";
            }
        } else {
            $data .= "No data found for the selected period.\n";
        }
    }
    
    $data .= "========================================\n";
    $data .= "Report generated: " . date('d M Y H:i:s') . "\n";
    $data .= "© " . APP_NAME . "\n";
    
    return $data;
}

function sendEmailReport($to, $subject, $message) {
    $headers = "From: " . APP_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Email - <?php echo APP_NAME; ?></title>
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

        .email-section {
            background: var(--paper);
            border-radius: var(--radius);
            padding: 24px 28px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            margin-bottom: 20px;
        }
        .email-section h2 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            font-size: 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .email-section h2 i { color: var(--gold-500); }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-weight: 600; font-size: 13px;
            color: var(--ink); margin-bottom: 4px;
        }
        .form-group label .required { color: var(--danger-ink); margin-left: 2px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px;
            border: 2px solid var(--line); border-radius: var(--radius-sm);
            font-size: 13px; font-family: 'Inter', sans-serif;
            background: var(--cream); color: var(--ink);
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease);
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--gold-500); outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .btn {
            padding: 10px 24px; border: none; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; cursor: pointer;
            font-family: 'Inter', sans-serif; transition: transform .2s, box-shadow .2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-400), var(--gold-500));
            color: var(--navy-950);
            box-shadow: 0 2px 8px rgba(201,150,47,.3);
        }
        .btn-gold:hover { box-shadow: 0 6px 20px rgba(201,150,47,.35); }
        .btn-secondary {
            background: var(--cream); color: var(--ink-soft);
            border: 2px solid var(--line);
        }
        .btn-secondary:hover { background: var(--line); }
        .btn-danger {
            background: var(--danger-ink); color: white;
        }
        .btn-danger:hover { background: #992b26; }

        .alert {
            padding: 12px 16px; border-radius: var(--radius-sm);
            margin-bottom: 16px; display: flex; align-items: flex-start;
            gap: 10px; font-size: 13px;
        }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert-success {
            background: var(--success-bg); color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }
        .alert-error {
            background: var(--danger-bg); color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }

        .email-list { margin-top: 12px; }
        .email-item {
            display: flex; align-items: center; gap: 12px;
            padding: 8px 12px; background: var(--cream);
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            margin-bottom: 6px;
        }
        .email-item .email { flex: 1; font-weight: 500; }
        .email-item .badge {
            padding: 2px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 600;
            background: var(--gold-500); color: var(--navy-950);
        }
        .email-item .badge.primary { background: var(--success-ink); color: white; }

        .agent-select { max-height: 150px; overflow-y: auto; border: 2px solid var(--line); border-radius: var(--radius-sm); padding: 8px 12px; }
        .agent-select label { display: block; padding: 4px 0; font-size: 13px; cursor: pointer; }
        .agent-select label:hover { background: var(--cream); }
        .agent-select input[type="checkbox"] { margin-right: 8px; }

        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(12,26,56,.45); z-index: 30; }

        @media (max-width: 900px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-backdrop.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px 40px; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .sidebar { width: 60px; }
            .sidebar-brand .logo-mark .brand-text { display: none; }
            .sidebar-nav a span:not(.icon) { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .main-header h1 { font-size: 22px; }
            .email-section { padding: 16px; }
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
                <a href="location-tracking.php">
                    <span class="icon"><i class="fas fa-map-marker-alt"></i></span>
                    <span>Location Tracking</span>
                </a>
                <a href="admin-email.php" class="active">
                    <span class="icon"><i class="fas fa-envelope"></i></span>
                    <span>Email Reports</span>
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
                        <h1>✉️ Admin Email Reports</h1>
                        <div class="subtitle">Manage admin emails and send reports to agents</div>
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

            <!-- Admin Emails Section -->
            <div class="email-section">
                <h2><i class="fas fa-envelope" style="color:var(--gold-500);"></i> Admin Email Addresses</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <?php endif; ?>

                <div class="email-list">
                    <?php while ($row = $emailsResult->fetch_assoc()): ?>
                        <div class="email-item">
                            <span class="email"><?php echo htmlspecialchars($row['email']); ?></span>
                            <?php if ($row['is_primary'] == 1): ?>
                                <span class="badge primary">⭐ Primary</span>
                            <?php else: ?>
                                <a href="?set_primary=<?php echo $row['id']; ?>" class="btn btn-secondary btn-sm" style="padding:2px 10px;font-size:10px;">Set Primary</a>
                            <?php endif; ?>
                            <?php if ($row['is_primary'] != 1): ?>
                                <a href="?delete_id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" style="padding:2px 10px;font-size:10px;" onclick="return confirm('Delete this email?')">Delete</a>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>

                <form method="post" style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                    <?php echo csrfField(); ?>
                    <input type="email" name="new_email" placeholder="Enter new admin email" style="padding:8px 14px;border:2px solid var(--line);border-radius:var(--radius-sm);font-size:13px;flex:1;min-width:200px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                        <input type="checkbox" name="is_primary" value="1"> Set as Primary
                    </label>
                    <button type="submit" name="add_email" class="btn btn-gold">Add Email</button>
                </form>
            </div>

            <!-- Send Email Section -->
            <div class="email-section">
                <h2><i class="fas fa-paper-plane" style="color:var(--gold-500);"></i> Send Report</h2>

                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="send_email" value="1">

                    <div class="form-group">
                        <label>Recipient <span class="required">*</span></label>
                        <select name="to" id="recipientSelect" onchange="toggleRecipient(this.value)" required>
                            <option value="">-- Select Recipient --</option>
                            <option value="all">All Agents & Admin</option>
                            <option value="admin">Admin Only</option>
                            <option value="selected">Selected Agents</option>
                            <option value="custom">Custom Email</option>
                        </select>
                    </div>

                    <div id="customEmailGroup" style="display:none;" class="form-group">
                        <label>Custom Email Address</label>
                        <input type="email" name="to" placeholder="Enter email address">
                    </div>

                    <div id="selectedAgentsGroup" style="display:none;" class="form-group">
                        <label>Select Agents</label>
                        <div class="agent-select">
                            <?php 
                            $agentsResult2 = executeQuery("SELECT agent_id, full_name, email FROM agents ORDER BY full_name ASC");
                            while ($row = $agentsResult2->fetch_assoc()): 
                            ?>
                            <label>
                                <input type="checkbox" name="selected_agents[]" value="<?php echo $row['agent_id']; ?>">
                                <?php echo htmlspecialchars($row['agent_id']); ?> - <?php echo htmlspecialchars($row['full_name']); ?> (<?php echo htmlspecialchars($row['email']); ?>)
                            </label>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Subject <span class="required">*</span></label>
                        <input type="text" name="subject" required placeholder="Email subject">
                    </div>

                    <div class="form-group">
                        <label>Message <span class="required">*</span></label>
                        <textarea name="message" required placeholder="Enter your message here..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Type</label>
                            <select name="report_type">
                                <option value="daily">Daily Report</option>
                                <option value="monthly">Monthly Summary</option>
                                <option value="custom">Custom Period</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="date_from" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div class="form-group" id="dateToGroup" style="display:none;">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <button type="submit" class="btn btn-gold">
                        <i class="fas fa-paper-plane"></i> Send Report
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
        }

        menuToggle?.addEventListener('click', toggleSidebar);

        function toggleRecipient(value) {
            const customGroup = document.getElementById('customEmailGroup');
            const selectedGroup = document.getElementById('selectedAgentsGroup');
            const customInput = customGroup?.querySelector('input[name="to"]');
            
            if (value === 'custom') {
                customGroup.style.display = 'block';
                selectedGroup.style.display = 'none';
                if (customInput) customInput.name = 'to';
            } else if (value === 'selected') {
                selectedGroup.style.display = 'block';
                customGroup.style.display = 'none';
                if (customInput) customInput.name = 'custom_email_temp';
            } else {
                customGroup.style.display = 'none';
                selectedGroup.style.display = 'none';
                if (customInput) customInput.name = 'custom_email_temp';
            }
        }

        // Show/hide date to based on report type
        document.querySelector('select[name="report_type"]')?.addEventListener('change', function() {
            const dateToGroup = document.getElementById('dateToGroup');
            if (this.value === 'custom') {
                dateToGroup.style.display = 'block';
            } else {
                dateToGroup.style.display = 'none';
            }
        });
    </script>
</body>
</html>