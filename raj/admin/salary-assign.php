<?php
// /raj/admin/salary-assign.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$adminId = $_SESSION['admin_id'] ?? 'admin';
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$message = '';
$msgType = 'success';

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Assign/Update Salary
    if ($action === 'assign_salary') {
        $agentId = $_POST['agent_id'] ?? '';
        $salary = (float)($_POST['salary'] ?? 0);
        $effectiveDate = $_POST['effective_date'] ?? date('Y-m-d');
        $salaryType = $_POST['salary_type'] ?? 'in_hand';
        $revisionReason = trim($_POST['revision_reason'] ?? '');
        $revisionNotes = trim($_POST['revision_notes'] ?? '');
        $performanceRating = $_POST['performance_rating'] ?? '';
        
        if (empty($agentId)) {
            $message = "Please select an agent.";
            $msgType = 'error';
        } elseif ($salary <= 0) {
            $message = "Salary must be greater than 0.";
            $msgType = 'error';
        } else {
            // Deactivate old salary
            executeQuery("UPDATE agent_salaries SET status = 'inactive' WHERE agent_id = ? AND status = 'active'", [$agentId]);
            
            // Insert new salary
            $result = executeQuery(
                "INSERT INTO agent_salaries (agent_id, salary, effective_date, salary_type, revision_reason, revision_notes, performance_rating, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$agentId, $salary, $effectiveDate, $salaryType, $revisionReason, $revisionNotes, $performanceRating, $adminId]
            );
            
            // Update agent's salary in agents table
            executeQuery("UPDATE agents SET salary = ? WHERE agent_id = ?", [$salary, $agentId]);
            
            // Update joining letter if exists
            executeQuery("UPDATE joining_letters SET salary = ? WHERE agent_id = ?", [$salary, $agentId]);
            
            $message = "✅ Salary assigned successfully!";
            $msgType = 'success';
        }
    }
    
    // Bulk Update
    if ($action === 'bulk_update') {
        $bulkAgentIds = $_POST['bulk_agents'] ?? [];
        $bulkSalary = (float)($_POST['bulk_salary'] ?? 0);
        $bulkEffectiveDate = $_POST['bulk_effective_date'] ?? date('Y-m-d');
        
        if (empty($bulkAgentIds) || $bulkSalary <= 0) {
            $message = "Select agents and enter valid salary.";
            $msgType = 'error';
        } else {
            $count = 0;
            foreach ($bulkAgentIds as $agentId) {
                executeQuery("UPDATE agent_salaries SET status = 'inactive' WHERE agent_id = ? AND status = 'active'", [$agentId]);
                executeQuery("INSERT INTO agent_salaries (agent_id, salary, effective_date, salary_type, created_by) VALUES (?, ?, ?, 'in_hand', ?)", [$agentId, $bulkSalary, $bulkEffectiveDate, $adminId]);
                executeQuery("UPDATE agents SET salary = ? WHERE agent_id = ?", [$bulkSalary, $agentId]);
                $count++;
            }
            $message = "✅ Bulk salary updated for $count agents!";
            $msgType = 'success';
        }
    }
}

// ============================================================
// GET DATA
// ============================================================

// Get all agents with current salary
$agentsQuery = executeQuery("
    SELECT 
        a.agent_id, a.full_name, a.email, a.mobile, a.candidate_photo_path,
        a.designation, a.department, a.shift_type, a.work_location,
        a.joining_date, a.onboarding_completed,
        COALESCE(a.salary, 13000) as current_salary,
        COALESCE(s.effective_date, a.joining_date) as salary_effective_date,
        COALESCE(s.salary_type, 'in_hand') as salary_type,
        COALESCE(s.revision_reason, '') as revision_reason,
        COALESCE(s.revision_notes, '') as revision_notes,
        COALESCE(s.performance_rating, '') as performance_rating,
        COALESCE(s.created_at, a.created_at) as salary_updated_at
    FROM agents a
    LEFT JOIN agent_salaries s ON a.agent_id = s.agent_id AND s.status = 'active'
    WHERE a.onboarding_completed = 1
    ORDER BY a.full_name ASC
");

$agents = [];
$totalSalary = 0;
$maxSalary = 0;
$minSalary = PHP_INT_MAX;
$totalAgents = 0;

if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
        $totalSalary += (float)$row['current_salary'];
        $maxSalary = max($maxSalary, (float)$row['current_salary']);
        $minSalary = min($minSalary, (float)$row['current_salary']);
        $totalAgents++;
    }
}

if ($totalAgents === 0) $minSalary = 0;
$avgSalary = $totalAgents > 0 ? round($totalSalary / $totalAgents, 2) : 0;

// Get salary history
$historyQuery = executeQuery("
    SELECT s.*, a.full_name 
    FROM agent_salaries s 
    JOIN agents a ON s.agent_id = a.agent_id 
    ORDER BY s.created_at DESC 
    LIMIT 50
");

$salaryHistory = [];
if ($historyQuery) {
    while ($row = $historyQuery->fetch_assoc()) {
        $salaryHistory[] = $row;
    }
}

// Get agents without salary assigned
$agentsWithoutSalary = array_filter($agents, function($a) {
    return (float)($a['current_salary'] ?? 0) <= 0 || empty($a['salary_effective_date']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Assignment - Admin</title>
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
            --radius: 18px; --radius-sm: 12px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f5f4f0; color:var(--ink); }

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

        .content { max-width: 1400px; margin: 0 auto; padding: 22px 28px 40px; }
        .page-title { font-family: 'Fraunces', serif; font-size: 26px; color: var(--navy-950); margin-bottom: 4px; }
        .page-subtitle { color: var(--ink-soft); font-size: 13px; margin-bottom: 20px; }

        .alert { padding: 14px 20px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 500; }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }
        .alert-error { background: #fbe7e7; color: var(--danger); border: 1px solid var(--danger); }

        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card {
            background: var(--paper); border-radius: var(--radius); padding: 20px 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); text-align: center;
        }
        .stat-card .stat-icon { font-size: 24px; margin-bottom: 6px; }
        .stat-card .stat-value { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 700; color: var(--navy-950); }
        .stat-card .stat-label { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; font-weight: 600; letter-spacing: .5px; }
        .stat-card.gold { border-top: 4px solid var(--gold-500); }
        .stat-card.green { border-top: 4px solid var(--success); }
        .stat-card.blue { border-top: 4px solid var(--info); }
        .stat-card.red { border-top: 4px solid var(--danger); }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin-bottom: 18px; flex-wrap: wrap; }
        .tab {
            padding: 10px 22px; border: none; border-radius: 25px; cursor: pointer;
            font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
            background: var(--paper); color: var(--ink-soft); border: 1px solid var(--line);
            transition: all .2s;
        }
        .tab:hover { background: var(--cream); }
        .tab.active { background: var(--navy-800); color: #fff; border-color: var(--navy-800); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Card */
        .card {
            background: var(--paper); border-radius: var(--radius); padding: 22px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line); margin-bottom: 20px;
        }
        .card h3 { font-family: 'Fraunces', serif; font-size: 17px; color: var(--navy-950); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .card h3 i { color: var(--gold-500); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 2px solid var(--line); border-radius: 8px;
            font-family: 'Inter', sans-serif; font-size: 13px; background: var(--paper);
            transition: border-color .3s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--gold-500); outline: none; background: #fffdf8;
        }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-group .hint { font-size: 10px; color: var(--ink-soft); margin-top: 3px; }

        .btn {
            padding: 10px 24px; border: none; border-radius: 25px; font-weight: 600; font-size: 13px;
            cursor: pointer; font-family: 'Inter', sans-serif; display: inline-flex; align-items: center;
            gap: 6px; transition: transform .2s, box-shadow .2s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-gold { background: var(--gold-500); color: var(--navy-950); }
        .btn-primary { background: var(--navy-800); color: #fff; }
        .btn-outline { background: var(--paper); color: var(--ink-soft); border: 2px solid var(--line); }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: var(--navy-800); color: #fff; padding: 10px 14px; text-align: left; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
        td { padding: 10px 14px; border-bottom: 1px solid var(--line); }
        tr:hover { background: var(--cream); }
        tr.highlight { background: #fffdf5; }
        .salary-badge { display: inline-block; padding: 4px 12px; border-radius: 15px; font-size: 11px; font-weight: 700; }
        .salary-badge.high { background: #e4f4e8; color: var(--success); }
        .salary-badge.mid { background: #fdf1de; color: var(--warn); }
        .salary-badge.low { background: #fbe7e7; color: var(--danger); }

        .mini-photo { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid var(--gold-500); }
        .mini-photo-placeholder { width: 32px; height: 32px; border-radius: 50%; background: var(--cream); display: inline-flex; align-items: center; justify-content: center; font-size: 14px; border: 2px solid var(--line); }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            max-width: 500px; width: 92%; box-shadow: 0 20px 50px rgba(12,26,56,.2);
            max-height: 90vh; overflow-y: auto;
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); margin-bottom: 14px; }

        .copyright-footer { text-align: center; padding: 20px; margin-top: 30px; border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft); }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media(max-width: 1024px) { .form-row-3 { grid-template-columns: 1fr; } }
        @media(max-width: 768px) {
            .content { padding: 12px 14px 30px; }
            .form-row { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            table { font-size: 10px; }
            th, td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">💰 Salary Assignment</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="salary-assign.php" class="active">Salary</a>
            <a href="agents-manage.php">Agents</a>
            <a href="joining-letter-setup.php">Joining Letters</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">💰 Salary Assignment & Management</h1>
        <p class="page-subtitle">Assign, update, and track agent salaries</p>

        <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card gold">
                <div class="stat-icon">💰</div>
                <div class="stat-value">₹<?php echo number_format($totalSalary, 0); ?></div>
                <div class="stat-label">Total Monthly Salary</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">📊</div>
                <div class="stat-value">₹<?php echo number_format($avgSalary, 0); ?></div>
                <div class="stat-label">Average Salary</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon">📈</div>
                <div class="stat-value">₹<?php echo number_format($maxSalary, 0); ?></div>
                <div class="stat-label">Highest Salary</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">📉</div>
                <div class="stat-value">₹<?php echo number_format($minSalary, 0); ?></div>
                <div class="stat-label">Lowest Salary</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $totalAgents; ?></div>
                <div class="stat-label">Total Agents</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('assign')">💰 Assign Salary</button>
            <button class="tab" onclick="switchTab('viewall')">📋 View All Salaries</button>
            <button class="tab" onclick="switchTab('history')">📜 Salary History</button>
            <button class="tab" onclick="switchTab('bulk')">⚡ Bulk Update</button>
        </div>

        <!-- ============ TAB 1: ASSIGN SALARY ============ -->
        <div class="tab-content active" id="tab-assign">
            <div class="card">
                <h3><i class="fas fa-user-plus"></i> Assign Salary to Agent</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_salary">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Agent *</label>
                            <select name="agent_id" required>
                                <option value="">-- Choose Agent --</option>
                                <?php foreach($agents as $ag): ?>
                                <option value="<?php echo $ag['agent_id']; ?>">
                                    <?php echo htmlspecialchars($ag['full_name']); ?> (<?php echo $ag['agent_id']; ?>) - Current: ₹<?php echo number_format((float)$ag['current_salary'], 0); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Salary Amount (₹) *</label>
                            <input type="number" name="salary" min="0" step="500" placeholder="e.g., 13000" required>
                            <div class="hint">Enter in-hand salary amount</div>
                        </div>
                    </div>
                    
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Effective Date *</label>
                            <input type="date" name="effective_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Salary Type</label>
                            <select name="salary_type">
                                <option value="in_hand">In-Hand</option>
                                <option value="ctc">CTC</option>
                                <option value="gross">Gross</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Performance Rating</label>
                            <select name="performance_rating">
                                <option value="">-- Select --</option>
                                <option value="excellent">⭐ Excellent</option>
                                <option value="good">👍 Good</option>
                                <option value="average">👌 Average</option>
                                <option value="needs_improvement">⚠️ Needs Improvement</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Revision Reason</label>
                        <input type="text" name="revision_reason" placeholder="e.g., Performance increment, Promotion, Market adjustment">
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="revision_notes" placeholder="Additional notes about this salary assignment"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Assign Salary</button>
                </form>
            </div>
        </div>

        <!-- ============ TAB 2: VIEW ALL SALARIES ============ -->
        <div class="tab-content" id="tab-viewall">
            <div class="card">
                <h3><i class="fas fa-list"></i> All Agent Salaries</h3>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Designation</th>
                                <th>Salary</th>
                                <th>Effective Date</th>
                                <th>Type</th>
                                <th>Performance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($agents as $ag): 
                                $sal = (float)$ag['current_salary'];
                                $badgeClass = $sal >= 20000 ? 'high' : ($sal >= 15000 ? 'mid' : 'low');
                                $photoPath = $ag['candidate_photo_path'] ?? '';
                                $photoUrl = '';
                                if(!empty($photoPath)) {
                                    $fullPath = __DIR__ . '/../storage/agents/' . $ag['agent_id'] . '/' . $photoPath;
                                    if(file_exists($fullPath)) {
                                        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $ag['agent_id'] . '&file=' . urlencode($photoPath);
                                    }
                                }
                            ?>
                            <tr class="<?php echo empty($ag['salary_effective_date']) ? 'highlight' : ''; ?>">
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <?php if(!empty($photoUrl)): ?>
                                            <img src="<?php echo $photoUrl; ?>" class="mini-photo" alt="Photo">
                                        <?php else: ?>
                                            <span class="mini-photo-placeholder">👤</span>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($ag['full_name']); ?></strong>
                                            <br><small style="color:var(--ink-soft);"><?php echo $ag['agent_id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($ag['designation'] ?? 'N/A'); ?></td>
                                <td><span class="salary-badge <?php echo $badgeClass; ?>">₹<?php echo number_format($sal, 0); ?></span></td>
                                <td><?php echo !empty($ag['salary_effective_date']) ? date('d M Y', strtotime($ag['salary_effective_date'])) : 'Not Set'; ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $ag['salary_type'] ?? 'in_hand')); ?></td>
                                <td>
                                    <?php 
                                    $rating = $ag['performance_rating'] ?? '';
                                    if($rating === 'excellent') echo '⭐ Excellent';
                                    elseif($rating === 'good') echo '👍 Good';
                                    elseif($rating === 'average') echo '👌 Average';
                                    elseif($rating === 'needs_improvement') echo '⚠️ Needs Improvement';
                                    else echo '—';
                                    ?>
                                </td>
                                <td><span class="salary-badge high">Active</span></td>
                                <td>
                                    <button class="btn btn-outline" style="font-size:10px;padding:4px 10px;" 
                                            onclick="openEditModal('<?php echo $ag['agent_id']; ?>', '<?php echo htmlspecialchars(addslashes($ag['full_name'])); ?>', <?php echo $sal; ?>)">
                                        ✏️ Edit
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============ TAB 3: HISTORY ============ -->
        <div class="tab-content" id="tab-history">
            <div class="card">
                <h3><i class="fas fa-history"></i> Salary Revision History</h3>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Salary</th>
                                <th>Effective Date</th>
                                <th>Reason</th>
                                <th>Rating</th>
                                <th>Changed By</th>
                                <th>Date Changed</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($salaryHistory)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--ink-soft);">No salary history yet.</td></tr>
                            <?php else: ?>
                            <?php foreach($salaryHistory as $sh): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sh['full_name']); ?></strong></td>
                                <td>₹<?php echo number_format((float)$sh['salary'], 0); ?></td>
                                <td><?php echo date('d M Y', strtotime($sh['effective_date'])); ?></td>
                                <td><?php echo !empty($sh['revision_reason']) ? htmlspecialchars($sh['revision_reason']) : '—'; ?></td>
                                <td><?php echo !empty($sh['performance_rating']) ? htmlspecialchars($sh['performance_rating']) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($sh['created_by']); ?></td>
                                <td><?php echo date('d M Y, h:i A', strtotime($sh['created_at'])); ?></td>
                                <td><span class="salary-badge <?php echo $sh['status'] === 'active' ? 'high' : 'low'; ?>"><?php echo ucfirst($sh['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============ TAB 4: BULK UPDATE ============ -->
        <div class="tab-content" id="tab-bulk">
            <div class="card">
                <h3><i class="fas fa-layer-group"></i> Bulk Salary Update</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_update">
                    
                    <div class="form-group">
                        <label>Select Multiple Agents *</label>
                        <div style="max-height:200px;overflow-y:auto;border:2px solid var(--line);border-radius:8px;padding:10px;">
                            <?php foreach($agents as $ag): ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer;">
                                <input type="checkbox" name="bulk_agents[]" value="<?php echo $ag['agent_id']; ?>" style="width:16px;height:16px;accent-color:var(--gold-500);">
                                <?php echo htmlspecialchars($ag['full_name']); ?> (<?php echo $ag['agent_id']; ?>) - ₹<?php echo number_format((float)$ag['current_salary'], 0); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Salary for Selected Agents (₹) *</label>
                            <input type="number" name="bulk_salary" min="0" step="500" placeholder="e.g., 15000" required>
                        </div>
                        <div class="form-group">
                            <label>Effective Date *</label>
                            <input type="date" name="bulk_effective_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-gold"><i class="fas fa-bolt"></i> Apply Bulk Update</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Salary Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>✏️ Edit Salary</h2>
            <form method="POST">
                <input type="hidden" name="action" value="assign_salary">
                <input type="hidden" name="agent_id" id="editAgentId">
                
                <div class="form-group">
                    <label>Agent</label>
                    <input type="text" id="editAgentName" readonly style="background:var(--cream);font-weight:600;">
                </div>
                
                <div class="form-group">
                    <label>New Salary (₹) *</label>
                    <input type="number" name="salary" id="editSalary" min="0" step="500" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Effective Date *</label>
                        <input type="date" name="effective_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Revision Reason</label>
                        <input type="text" name="revision_reason" placeholder="e.g., Increment">
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;margin-top:14px;">
                    <button type="submit" class="btn btn-gold">💾 Save</button>
                    <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector('.tab[onclick="switchTab(\'' + tabName + '\')"]').classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
        }

        function openEditModal(agentId, name, currentSalary) {
            document.getElementById('editAgentId').value = agentId;
            document.getElementById('editAgentName').value = name;
            document.getElementById('editSalary').value = currentSalary;
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }

        document.getElementById('editModal').addEventListener('click', function(e) {
            if(e.target === this) closeEditModal();
        });
    </script>
</body>
</html>