<?php
// /raj/admin/edit-agent.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';
$message = '';
$msgType = 'success';

// Get all agents for dropdown
$allAgents = [];
$agentsQuery = executeQuery("SELECT agent_id, full_name, email, mobile, date_of_birth, candidate_photo_path FROM agents WHERE onboarding_completed = 1 ORDER BY full_name");
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $allAgents[] = $row;
    }
}

// Selected agent
$selectedAgentId = $_GET['agent'] ?? ($_POST['agent_select'] ?? '');
$selectedAgent = null;

if (!empty($selectedAgentId)) {
    $selectedAgent = getAgent($selectedAgentId);
    if (!$selectedAgent) {
        foreach ($allAgents as $ag) {
            if ($ag['agent_id'] === $selectedAgentId) {
                $selectedAgent = $ag;
                break;
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_id_dob') {
        $currentAgentId = trim($_POST['current_agent_id'] ?? '');
        $newAgentId = strtoupper(trim($_POST['new_agent_id'] ?? ''));
        $dob = $_POST['date_of_birth'] ?? '';
        
        $errors = [];
        
        // Validate Agent ID
        if (empty($newAgentId)) {
            $errors[] = "New Agent ID is required.";
        } elseif (!preg_match('/^[A-Z0-9_-]{3,20}$/', $newAgentId)) {
            $errors[] = "Agent ID must be 3-20 characters (letters, numbers, underscore, hyphen).";
        } elseif ($newAgentId !== $currentAgentId) {
            // Check if new ID already exists
            $check = executeQuery("SELECT agent_id FROM agents WHERE agent_id = ?", [$newAgentId]);
            if ($check && $check->num_rows > 0) {
                $errors[] = "Agent ID '<strong>$newAgentId</strong>' already exists. Please choose a different ID.";
            }
        }
        
        // Validate DOB
        if (!empty($dob)) {
            $dobTimestamp = strtotime($dob);
            if (!$dobTimestamp) {
                $errors[] = "Invalid Date of Birth format.";
            } elseif ($dobTimestamp > time()) {
                $errors[] = "Date of Birth cannot be in the future.";
            } elseif ($dobTimestamp < strtotime('-100 years')) {
                $errors[] = "Date of Birth is too old (must be within last 100 years).";
            } elseif ($dobTimestamp > strtotime('-18 years')) {
                $errors[] = "Employee must be at least 18 years old.";
            }
        }
        
        if (empty($errors)) {
            // Update DOB first
            $encryptedDob = !empty($dob) ? encryptData($dob) : null;
            executeQuery("UPDATE agents SET date_of_birth = ? WHERE agent_id = ?", [$encryptedDob, $currentAgentId]);
            
            // Update Agent ID if changed
            if ($newAgentId !== $currentAgentId) {
                $conn = getDbConnection();
                
                // Temporarily disable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                // List of tables to update
                $tablesToUpdate = [
                    'attendance',
                    'daily_sales', 
                    'sales_targets', 
                    'agent_salaries',
                    'compliance_agreements', 
                    'training_letters', 
                    'joining_letters',
                    'sales_report_requests', 
                    'agent_login_logs',
                    'audit_log'
                ];
                
                foreach ($tablesToUpdate as $table) {
                    try {
                        // Check if table exists
                        $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
                        if ($tableCheck && $tableCheck->num_rows > 0) {
                            // Check if agent_id column exists
                            $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'agent_id'");
                            if ($colCheck && $colCheck->num_rows > 0) {
                                $newIdEscaped = $conn->real_escape_string($newAgentId);
                                $oldIdEscaped = $conn->real_escape_string($currentAgentId);
                                $conn->query("UPDATE `$table` SET agent_id = '$newIdEscaped' WHERE agent_id = '$oldIdEscaped'");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error updating table $table: " . $e->getMessage());
                        // Continue with other tables
                    }
                }
                
                // Update agents table
                try {
                    $newIdEscaped = $conn->real_escape_string($newAgentId);
                    $oldIdEscaped = $conn->real_escape_string($currentAgentId);
                    $conn->query("UPDATE agents SET agent_id = '$newIdEscaped' WHERE agent_id = '$oldIdEscaped'");
                } catch (Exception $e) {
                    error_log("Error updating agents table: " . $e->getMessage());
                }
                
                // Re-enable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                
                // Rename storage folder
                $oldFolder = __DIR__ . '/../storage/agents/' . $currentAgentId;
                $newFolder = __DIR__ . '/../storage/agents/' . $newAgentId;
                if (is_dir($oldFolder) && !is_dir($newFolder)) {
                    rename($oldFolder, $newFolder);
                }
                
                addAuditLog($adminId, 'agent_id_changed', "Agent ID changed: $currentAgentId → $newAgentId", $newAgentId);
                $message = "✅ Agent ID changed from <strong>$currentAgentId</strong> to <strong>$newAgentId</strong>!";
                $selectedAgentId = $newAgentId;
            } else {
                $message = "✅ Agent details updated successfully!";
            }
            
          if (!empty($dob)) {
    addAuditLog($adminId, 'agent_dob_updated', "DOB updated for agent $selectedAgentId", $selectedAgentId);
}
            
            $msgType = 'success';
            
            // Refresh agent data
            $selectedAgent = getAgent($selectedAgentId);
            if (!$selectedAgent) {
                foreach ($allAgents as $ag) {
                    if ($ag['agent_id'] === $selectedAgentId) {
                        $selectedAgent = $ag;
                        break;
                    }
                }
            }
            
            // Refresh all agents list
            $allAgents = [];
            $agentsQuery = executeQuery("SELECT agent_id, full_name, email, mobile, date_of_birth, candidate_photo_path FROM agents WHERE onboarding_completed = 1 ORDER BY full_name");
            if ($agentsQuery) {
                while ($row = $agentsQuery->fetch_assoc()) {
                    $allAgents[] = $row;
                }
            }
        } else {
            $message = implode('<br>', $errors);
            $msgType = 'error';
        }
    }
}

// Get DOB for selected agent
$dobValue = '';
if ($selectedAgent) {
    $rawDob = $selectedAgent['date_of_birth'] ?? '';
    if (!empty($rawDob)) {
        $decrypted = decryptData($rawDob);
        $dobValue = ($decrypted && strtotime($decrypted)) ? $decrypted : $rawDob;
    }
}

// Photo for selected agent
$photoUrl = '';
if ($selectedAgent && !empty($selectedAgent['candidate_photo_path'])) {
    $agentIdForPhoto = $selectedAgent['agent_id'] ?? $selectedAgentId;
    $fullPath = __DIR__ . '/../storage/agents/' . $agentIdForPhoto . '/' . $selectedAgent['candidate_photo_path'];
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentIdForPhoto . '&file=' . urlencode($selectedAgent['candidate_photo_path']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Agent ID & DOB - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --navy-700: #1c407a;
            --gold-500: #c9962f; --gold-400: #dcae4d; --gold-100: #fdf1de;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f; --warn: #9a6a10; --info: #29538c;
            --radius: 18px; --radius-sm: 12px; --radius-xs: 8px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 14px 28px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,.2); position: sticky; top: 0; z-index: 100;
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px; border-radius: 20px;
            font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover, .header .nav a.active { background: rgba(255,255,255,.12); }
        .header .nav a.active { color: var(--gold-500); font-weight: 600; }

        .content { max-width: 800px; margin: 0 auto; padding: 22px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 4px; text-align: center; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 20px; text-align: center; }

        .alert { padding: 14px 20px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 500; line-height: 1.6; }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        .card {
            background: var(--paper); border-radius: var(--radius); padding: 28px 30px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); margin-bottom: 20px;
        }
        .card h3 {
            font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950);
            margin-bottom: 18px; padding-bottom: 10px; border-bottom: 2px solid var(--gold-500);
            display: flex; align-items: center; gap: 8px;
        }
        .card h3 i { color: var(--gold-500); }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px;
        }
        .form-group label .required { color: var(--danger); }
        .form-group input, .form-group select {
            width: 100%; padding: 12px 16px; border: 2px solid var(--line); border-radius: var(--radius-xs);
            font-family: 'Inter', sans-serif; font-size: 14px; background: var(--paper);
            transition: border-color .3s;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: var(--gold-500); outline: none; background: #fffdf8;
        }
        .form-group input:read-only { background: var(--cream); cursor: not-allowed; }
        .form-group .hint { font-size: 10px; color: var(--ink-soft); margin-top: 3px; }

        .agent-profile {
            display: flex; align-items: center; gap: 14px; padding: 14px 18px;
            background: var(--cream); border-radius: var(--radius-sm); margin-bottom: 16px;
            border: 1px solid var(--line);
        }
        .agent-profile .photo {
            width: 55px; height: 55px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--gold-500); flex-shrink: 0;
        }
        .agent-profile .photo-placeholder {
            width: 55px; height: 55px; border-radius: 50%; background: var(--paper);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: #aaa; border: 2px dashed var(--line); flex-shrink: 0;
        }
        .agent-profile .info h4 { font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950); }
        .agent-profile .info span { font-size: 12px; color: var(--ink-soft); display: block; }

        .warning-box {
            background: #fdf1de; border: 1px solid var(--warn); border-radius: var(--radius-sm);
            padding: 12px 16px; font-size: 12px; color: var(--warn); margin-bottom: 16px;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .warning-box i { margin-top: 2px; }

        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 20px; }
        .btn {
            padding: 12px 30px; border: none; border-radius: 25px; font-weight: 700; font-size: 13px;
            cursor: pointer; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center;
            gap: 8px; transition: transform .2s, box-shadow .2s; text-decoration: none;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-gold { background: var(--gold-500); color: var(--navy-950); }
        .btn-outline { background: var(--paper); color: var(--ink-soft); border: 2px solid var(--line); }

        .empty-state { text-align: center; padding: 50px 20px; color: var(--ink-soft); }
        .empty-state i { font-size: 50px; display: block; margin-bottom: 10px; color: #ddd; }
        .empty-state h3 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); }

        .copyright-footer { text-align: center; padding: 20px; margin-top: 30px; border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft); }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media(max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .card { padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">✏️ Edit Employee</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="agents-manage.php">Agents</a>
            <a href="edit-agent.php" class="active">Edit Agent</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">✏️ Edit Employee Login ID & Date of Birth</h1>
        <p class="page-subtitle">Select an employee to update their Login ID and Date of Birth</p>

        <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Agent Selector -->
        <div class="card">
            <h3><i class="fas fa-user-search"></i> Select Employee</h3>
            <form method="GET">
                <div class="form-group">
                    <label>Choose Employee</label>
                    <select name="agent" onchange="this.form.submit()" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach($allAgents as $ag): ?>
                        <option value="<?php echo $ag['agent_id']; ?>" <?php echo $selectedAgentId === $ag['agent_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ag['full_name']); ?> (<?php echo $ag['agent_id']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if($selectedAgent): ?>
        <!-- Edit Form -->
        <div class="card">
            <h3><i class="fas fa-edit"></i> Edit Details</h3>

            <!-- Agent Profile Summary -->
            <div class="agent-profile">
                <?php if(!empty($photoUrl)): ?>
                    <img src="<?php echo $photoUrl; ?>" class="photo" alt="Photo">
                <?php else: ?>
                    <div class="photo-placeholder">👤</div>
                <?php endif; ?>
                <div class="info">
                    <h4><?php echo htmlspecialchars($selectedAgent['full_name'] ?? 'Agent'); ?></h4>
                    <span><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($selectedAgentId); ?></span>
                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($selectedAgent['email'] ?? 'N/A'); ?></span>
                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($selectedAgent['mobile'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <!-- Warning -->
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Important:</strong> Changing the Login ID will update ALL related records 
                    and rename the storage folder. Employee must be at least 18 years old.
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_id_dob">
                <input type="hidden" name="current_agent_id" value="<?php echo htmlspecialchars($selectedAgentId); ?>">
                <input type="hidden" name="agent_select" value="<?php echo htmlspecialchars($selectedAgentId); ?>">

                <div class="form-group">
                    <label>Current Login ID (Agent ID)</label>
                    <input type="text" value="<?php echo htmlspecialchars($selectedAgentId); ?>" readonly>
                </div>

                <div class="form-group">
                    <label>New Login ID <span class="required">*</span></label>
                    <input type="text" name="new_agent_id" id="newAgentId" 
                           value="<?php echo htmlspecialchars($selectedAgentId); ?>" 
                           placeholder="e.g., AGT0001" maxlength="20" required 
                           oninput="this.value=this.value.toUpperCase();">
                    <div class="hint">3-20 characters (A-Z, 0-9, underscore, hyphen)</div>
                </div>

                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth" 
                           value="<?php echo !empty($dobValue) ? htmlspecialchars(date('Y-m-d', strtotime($dobValue))) : ''; ?>"
                           max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                           min="<?php echo date('Y-m-d', strtotime('-100 years')); ?>">
                    <div class="hint">Employee must be between 18-100 years old</div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-gold">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="edit-agent.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            const currentId = document.querySelector('input[name="current_agent_id"]').value;
            const newId = document.querySelector('input[name="new_agent_id"]').value;
            const dob = document.querySelector('input[name="date_of_birth"]').value;
            
            if (currentId !== newId) {
                if (!confirm(
                    '⚠️ You are changing Login ID:\n\n' +
                    'FROM: ' + currentId + '\n' +
                    'TO: ' + newId + '\n\n' +
                    'This will update ALL related records and rename storage folder.\n\n' +
                    'Continue?'
                )) {
                    e.preventDefault();
                    return;
                }
            }
            
            if (dob) {
                const dobDate = new Date(dob);
                const today = new Date();
                const age = (today - dobDate) / (365.25 * 24 * 60 * 60 * 1000);
                
                if (age < 18) {
                    alert('❌ Employee must be at least 18 years old.');
                    e.preventDefault();
                    return;
                }
            }
        });
    </script>
</body>
</html>