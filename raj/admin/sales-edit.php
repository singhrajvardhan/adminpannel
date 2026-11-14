<?php
// /raj/admin/sales-edit.php
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

$selectedMonth = $_GET['month'] ?? date('Y-m');
$daysInMonth = date('t', strtotime($selectedMonth . '-01'));
$today = date('Y-m-d');

// Get all agents
$agentsQuery = executeQuery("SELECT agent_id, full_name FROM agents WHERE onboarding_completed = 1 ORDER BY full_name");
$agents = [];
if ($agentsQuery) {
    while ($row = $agentsQuery->fetch_assoc()) {
        $agents[] = $row;
    }
}

// Get existing sales for the month
$existingSales = [];
$salesQuery = executeQuery(
    "SELECT * FROM daily_sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?",
    [$selectedMonth]
);
if ($salesQuery) {
    while ($row = $salesQuery->fetch_assoc()) {
        $existingSales[$row['agent_id'] . '_' . $row['sale_date']] = $row;
    }
}

// Handle AJAX save for single cell
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    
    $agentId = $_POST['agent_id'] ?? '';
    $date = $_POST['date'] ?? '';
    $field = $_POST['field'] ?? '';
    $value = (int)($_POST['value'] ?? 0);
    
    if (empty($agentId) || empty($date) || empty($field)) {
        echo json_encode(['success' => false, 'message' => 'Missing data']);
        exit;
    }
    
    $key = $agentId . '_' . $date;
    
    if (isset($existingSales[$key])) {
        $result = executeQuery(
            "UPDATE daily_sales SET $field = ?, status = 'approved' WHERE id = ?",
            [$value, $existingSales[$key]['id']]
        );
    } else {
        $result = executeQuery(
            "INSERT INTO daily_sales (agent_id, sale_date, $field, status, approved_by, approved_at) 
             VALUES (?, ?, ?, 'approved', 'admin', NOW())",
            [$agentId, $date, $value]
        );
    }
    
    echo json_encode(['success' => $result !== false]);
    exit;
}

// Handle bulk save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    $agentId = $_POST['bulk_agent_id'] ?? '';
    $fromDay = (int)($_POST['from_day'] ?? 1);
    $toDay = (int)($_POST['to_day'] ?? $daysInMonth);
    $creditScore = (int)($_POST['bulk_credit_score'] ?? 0);
    $creditStrong = (int)($_POST['bulk_credit_strong'] ?? 0);
    $kikoff = (int)($_POST['bulk_kikoff'] ?? 0);
    $other = (int)($_POST['bulk_other'] ?? 0);
    
    $count = 0;
    for ($day = $fromDay; $day <= $toDay; $day++) {
        $date = sprintf('%s-%02d', $selectedMonth, $day);
        
        // Skip weekends
        if (date('N', strtotime($date)) >= 6) continue;
        
        $key = $agentId . '_' . $date;
        if (isset($existingSales[$key])) {
            executeQuery(
                "UPDATE daily_sales SET credit_score = ?, credit_strong = ?, kikoff = ?, other_sales = ?, status = 'approved' WHERE id = ?",
                [$creditScore, $creditStrong, $kikoff, $other, $existingSales[$key]['id']]
            );
        } else {
            executeQuery(
                "INSERT INTO daily_sales (agent_id, sale_date, credit_score, credit_strong, kikoff, other_sales, status, approved_by, approved_at) 
                 VALUES (?, ?, ?, ?, ?, ?, 'approved', 'admin', NOW())",
                [$agentId, $date, $creditScore, $creditStrong, $kikoff, $other]
            );
        }
        $count++;
    }
    
    $message = "✅ Bulk saved! Updated $count days for " . ($agentId === 'all' ? 'all agents' : $agentId);
    
    // Refresh data
    $existingSales = [];
    $salesQuery = executeQuery("SELECT * FROM daily_sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = ?", [$selectedMonth]);
    if ($salesQuery) {
        while ($row = $salesQuery->fetch_assoc()) {
            $existingSales[$row['agent_id'] . '_' . $row['sale_date']] = $row;
        }
    }
}

// Current agent filter
$filterAgent = $_GET['agent'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Sales - Admin</title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --gold-400: #dcae4d; --cream: #f8f7f3; --paper: #ffffff;
            --ink: #1c2536; --ink-soft: #667085; --line: #ecebe6;
            --success: #1e7a41; --danger: #b3352f; --info: #29538c;
            --radius: 14px; --radius-sm: 10px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f4f0; color: var(--ink); }

        .header {
            background: linear-gradient(135deg, #1a0a0a, #3d1515);
            color: #fce8e8; padding: 12px 24px; display: flex;
            justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .header .brand { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; }
        .header .nav { display: flex; gap: 6px; flex-wrap: wrap; }
        .header .nav a {
            color: #fce8e8; text-decoration: none; padding: 6px 14px;
            border-radius: 20px; font-size: 12px; transition: background .2s;
        }
        .header .nav a:hover { background: rgba(255,255,255,.12); }

        .content { max-width: 1400px; margin: 0 auto; padding: 20px 24px 40px; }

        .page-title { font-family: 'Fraunces', serif; font-size: 24px; color: var(--navy-950); margin-bottom: 4px; }
        .alert {
            padding: 12px 18px; border-radius: var(--radius-sm); margin-bottom: 14px;
            font-size: 13px; font-weight: 500;
        }
        .alert-success { background: #e4f4e8; color: var(--success); border: 1px solid var(--success); }

        /* Controls Bar */
        .controls-bar {
            background: var(--paper); border-radius: var(--radius); padding: 16px 20px;
            margin-bottom: 16px; box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
        }
        .controls-bar select, .controls-bar input {
            padding: 8px 14px; border: 2px solid var(--line); border-radius: 25px;
            font-family: 'Inter', sans-serif; font-size: 12px; background: var(--paper);
        }
        .controls-bar select { min-width: 160px; }
        .btn-sm {
            padding: 8px 18px; border: none; border-radius: 25px; font-size: 12px;
            font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;
            display: inline-flex; align-items: center; gap: 6px; transition: transform .2s;
        }
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-bulk { background: var(--gold-500); color: var(--navy-950); }
        .btn-reset { background: var(--cream); color: var(--ink-soft); border: 1px solid var(--line); }
        .btn-nav { background: var(--paper); color: var(--ink); border: 1px solid var(--line); text-decoration: none; }

        /* Agent Tab Cards */
        .agent-tabs {
            display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap;
            background: var(--paper); border-radius: var(--radius); padding: 10px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            max-height: 200px; overflow-y: auto;
        }
        .agent-tab {
            padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 12px;
            font-weight: 500; white-space: nowrap; border: 1px solid var(--line);
            background: var(--paper); color: var(--ink-soft); transition: all .2s;
            text-decoration: none;
        }
        .agent-tab:hover { background: var(--cream); }
        .agent-tab.active { background: var(--navy-800); color: #fff; border-color: var(--navy-800); font-weight: 600; }
        .agent-tab.all-tab { background: var(--gold-500); color: var(--navy-950); border-color: var(--gold-500); font-weight: 700; }
        .agent-tab.all-tab.active { background: var(--navy-800); color: var(--gold-300); }

        /* Sales Cards Grid */
        .sales-cards-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px; margin-bottom: 16px;
        }
        .day-card {
            background: var(--paper); border-radius: var(--radius-sm); padding: 14px 16px;
            box-shadow: var(--shadow-sm); border: 1px solid var(--line);
            transition: transform .2s; position: relative;
        }
        .day-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .day-card.weekend { background: #fafafa; opacity: .7; }
        .day-card.today { border: 2px solid var(--gold-500); background: #fffdf8; }
        .day-card .day-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--line);
        }
        .day-card .day-date { font-weight: 700; font-size: 14px; color: var(--navy-950); }
        .day-card .day-name {
            padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 600;
        }
        .day-card .day-name.weekday { background: #f0f0f0; color: var(--ink-soft); }
        .day-card .day-name.weekend { background: #fef0f0; color: var(--danger); }
        .day-card .day-name.today-badge { background: var(--gold-500); color: #fff; }
        .day-card .saved-indicator {
            position: absolute; top: 8px; right: 8px; font-size: 10px;
            color: var(--success); display: none;
        }
        .day-card .saved-indicator.show { display: block; }

        .day-card .sales-inputs {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .day-card .input-group { text-align: center; }
        .day-card .input-group label {
            display: block; font-size: 9px; font-weight: 600; color: var(--ink-soft);
            text-transform: uppercase; margin-bottom: 3px;
        }
        .day-card .input-group input {
            width: 100%; padding: 8px 6px; text-align: center; border: 2px solid var(--line);
            border-radius: 8px; font-size: 13px; font-family: 'Inter', sans-serif;
            transition: border-color .3s, background .3s;
        }
        .day-card .input-group input:focus {
            border-color: var(--gold-500); outline: none; background: #fffdf8;
        }
        .day-card .input-group input.has-value {
            background: #e8f5e9; border-color: #66bb6a;
        }
        .day-card .day-total {
            text-align: center; margin-top: 8px; padding-top: 8px;
            border-top: 1px solid var(--line); font-weight: 700;
            font-family: 'Fraunces', serif; font-size: 16px; color: var(--navy-950);
        }
        .day-card .day-total span { font-size: 10px; color: var(--ink-soft); font-weight: 400; }

        .empty-state {
            text-align: center; padding: 40px; color: var(--ink-soft);
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 40px; margin-bottom: 10px; color: #ddd; }

        /* Bulk Modal */
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(12,26,56,.7); z-index: 9999; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: var(--paper); border-radius: var(--radius); padding: 24px;
            max-width: 480px; width: 92%; box-shadow: 0 20px 50px rgba(12,26,56,.2);
        }
        .modal-content h2 { font-family: 'Fraunces', serif; font-size: 18px; color: var(--navy-950); margin-bottom: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .form-group { margin-bottom: 10px; }
        .form-group label { display: block; font-size: 10px; font-weight: 600; color: var(--ink-soft); text-transform: uppercase; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 2px solid var(--line); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; }
        .form-group input:focus, .form-group select:focus { border-color: var(--gold-500); outline: none; }
        .btn-group { display: flex; gap: 10px; margin-top: 14px; }
        .btn-save { padding: 10px 24px; background: var(--navy-800); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-cancel { padding: 10px 24px; background: var(--cream); color: var(--ink-soft); border: 2px solid var(--line); border-radius: 8px; cursor: pointer; }

        .copyright-footer {
            text-align: center; padding: 20px; margin-top: 30px;
            border-top: 1px solid var(--line); font-size: 12px; color: var(--ink-soft);
        }
        .copyright-footer a { color: var(--gold-500); text-decoration: none; font-weight: 600; }

        @media (max-width: 768px) {
            .content { padding: 10px 12px 30px; }
            .sales-cards-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
            .controls-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📝 Sales Editor</div>
        <div class="nav">
            <a href="sales-manage.php?month=<?php echo $selectedMonth; ?>"><i class="fas fa-arrow-left"></i> Back</a>
            <a href="db.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">✎ Edit Sales - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h1>

        <?php if (isset($message)): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Controls Bar -->
        <div class="controls-bar">
            <?php 
                $prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
                $nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
            ?>
            <a href="?month=<?php echo $prevMonth; ?>&agent=<?php echo $filterAgent; ?>" class="btn-sm btn-nav">← Prev</a>
            <select onchange="window.location.href='?month='+this.value+'&agent=<?php echo $filterAgent; ?>'">
                <?php for ($m=1;$m<=12;$m++): $v=sprintf('%04d-%02d',date('Y'),$m); ?>
                <option value="<?php echo $v; ?>" <?php echo $v===$selectedMonth?'selected':''; ?>><?php echo date('F Y',strtotime($v.'-01')); ?></option>
                <?php endfor; ?>
            </select>
            <a href="?month=<?php echo $nextMonth; ?>&agent=<?php echo $filterAgent; ?>" class="btn-sm btn-nav">Next →</a>
            <button class="btn-sm btn-bulk" onclick="openBulkModal()"><i class="fas fa-layer-group"></i> Bulk Fill</button>
            <span style="font-size:11px;color:var(--ink-soft);margin-left:auto;">
                💡 Click any field to edit. Changes save automatically.
            </span>
        </div>

        <!-- Agent Tabs -->
        <div class="agent-tabs">
            <a href="?month=<?php echo $selectedMonth; ?>&agent=all" class="agent-tab all-tab <?php echo $filterAgent==='all'?'active':''; ?>">
                👥 All Agents
            </a>
            <?php foreach ($agents as $agent): ?>
            <a href="?month=<?php echo $selectedMonth; ?>&agent=<?php echo $agent['agent_id']; ?>" 
               class="agent-tab <?php echo $filterAgent===$agent['agent_id']?'active':''; ?>">
                <?php echo htmlspecialchars($agent['full_name']); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Sales Cards -->
        <div class="sales-cards-grid">
            <?php 
            $displayAgents = ($filterAgent === 'all') ? $agents : array_filter($agents, fn($a) => $a['agent_id'] === $filterAgent);
            
            foreach ($displayAgents as $agent):
                $aid = $agent['agent_id'];
                for ($day = 1; $day <= $daysInMonth; $day++):
                    $date = sprintf('%s-%02d', $selectedMonth, $day);
                    $key = $aid . '_' . $date;
                    $sale = $existingSales[$key] ?? null;
                    $cs = $sale['credit_score'] ?? 0;
                    $cstrong = $sale['credit_strong'] ?? 0;
                    $ko = $sale['kikoff'] ?? 0;
                    $other = $sale['other_sales'] ?? 0;
                    $dayTotal = $cs + $cstrong + $ko + $other;
                    $dayOfWeek = date('N', strtotime($date));
                    $isWeekend = $dayOfWeek >= 6;
                    $isToday = ($date === $today);
                    
                    if ($filterAgent !== 'all' && $isWeekend) continue; // Skip weekends when viewing single agent
            ?>
            <div class="day-card <?php echo $isWeekend ? 'weekend' : ''; ?> <?php echo $isToday ? 'today' : ''; ?>" 
                 data-agent="<?php echo $aid; ?>" data-date="<?php echo $date; ?>">
                
                <?php if ($filterAgent === 'all'): ?>
                <div style="font-size:10px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;">
                    <?php echo htmlspecialchars($agent['full_name']); ?>
                </div>
                <?php endif; ?>
                
                <div class="day-header">
                    <div>
                        <span class="day-date"><?php echo date('M d', strtotime($date)); ?></span>
                        <?php echo $isToday ? ' 📌' : ''; ?>
                    </div>
                    <span class="day-name <?php echo $isWeekend ? 'weekend' : 'weekday'; ?> <?php echo $isToday ? 'today-badge' : ''; ?>">
                        <?php echo date('D', strtotime($date)); ?>
                    </span>
                </div>
                
                <span class="saved-indicator">✅ Saved</span>
                
                <div class="sales-inputs">
                    <div class="input-group">
                        <label>⭐ Credit Score</label>
                        <input type="number" value="<?php echo $cs ?: ''; ?>" placeholder="0" min="0"
                               class="auto-save <?php echo $cs > 0 ? 'has-value' : ''; ?>"
                               data-field="credit_score" data-agent="<?php echo $aid; ?>" data-date="<?php echo $date; ?>"
                               onchange="saveField(this)" onfocus="this.select()">
                    </div>
                    <div class="input-group">
                        <label>🛡️ Credit Strong</label>
                        <input type="number" value="<?php echo $cstrong ?: ''; ?>" placeholder="0" min="0"
                               class="auto-save <?php echo $cstrong > 0 ? 'has-value' : ''; ?>"
                               data-field="credit_strong" data-agent="<?php echo $aid; ?>" data-date="<?php echo $date; ?>"
                               onchange="saveField(this)" onfocus="this.select()">
                    </div>
                    <div class="input-group">
                        <label>🏦 KikOff</label>
                        <input type="number" value="<?php echo $ko ?: ''; ?>" placeholder="0" min="0"
                               class="auto-save <?php echo $ko > 0 ? 'has-value' : ''; ?>"
                               data-field="kikoff" data-agent="<?php echo $aid; ?>" data-date="<?php echo $date; ?>"
                               onchange="saveField(this)" onfocus="this.select()">
                    </div>
                    <div class="input-group">
                        <label>📦 Other</label>
                        <input type="number" value="<?php echo $other ?: ''; ?>" placeholder="0" min="0"
                               class="auto-save <?php echo $other > 0 ? 'has-value' : ''; ?>"
                               data-field="other_sales" data-agent="<?php echo $aid; ?>" data-date="<?php echo $date; ?>"
                               onchange="saveField(this)" onfocus="this.select()">
                    </div>
                </div>
                
                <div class="day-total">
                    <span>Total:</span> <?php echo $dayTotal; ?>
                </div>
            </div>
            <?php endfor; endforeach; ?>
            
            <?php if (empty($displayAgents)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No agents found</h3>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bulk Fill Modal -->
    <div id="bulkModal" class="modal">
        <div class="modal-content">
            <h2>📦 Bulk Fill Sales</h2>
            <form method="POST">
                <input type="hidden" name="bulk_save" value="1">
                
                <div class="form-group">
                    <label>Agent</label>
                    <select name="bulk_agent_id">
                        <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo $agent['agent_id']; ?>" <?php echo $filterAgent!=='all'&&$filterAgent===$agent['agent_id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($agent['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>From Day</label>
                        <input type="number" name="from_day" value="1" min="1" max="<?php echo $daysInMonth; ?>">
                    </div>
                    <div class="form-group">
                        <label>To Day</label>
                        <input type="number" name="to_day" value="<?php echo $daysInMonth; ?>" min="1" max="<?php echo $daysInMonth; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>⭐ Credit Score</label>
                        <input type="number" name="bulk_credit_score" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>🛡️ Credit Strong</label>
                        <input type="number" name="bulk_credit_strong" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>🏦 KikOff</label>
                        <input type="number" name="bulk_kikoff" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>📦 Other</label>
                        <input type="number" name="bulk_other" value="0" min="0">
                    </div>
                </div>
                
                <p style="font-size:10px;color:var(--ink-soft);margin:6px 0;">
                    ⚠️ Weekends (Sat/Sun) will be skipped automatically.
                </p>
                
                <div class="btn-group">
                    <button type="submit" class="btn-save">💾 Apply Bulk Fill</button>
                    <button type="button" class="btn-cancel" onclick="closeBulkModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Copyright Footer -->
    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        // Auto-save when field changes
        function saveField(input) {
            const value = parseInt(input.value) || 0;
            const field = input.dataset.field;
            const agentId = input.dataset.agent;
            const date = input.dataset.date;
            
            // Highlight
            if (value > 0) {
                input.classList.add('has-value');
            } else {
                input.classList.remove('has-value');
            }
            
            // Update total for this card
            updateCardTotal(input);
            
            // Save via AJAX
            const params = new URLSearchParams({
                ajax_save: 1,
                agent_id: agentId,
                date: date,
                field: field,
                value: value
            });
            
            fetch('sales-edit.php?month=<?php echo $selectedMonth; ?>&agent=<?php echo $filterAgent; ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Show saved indicator
                    const card = input.closest('.day-card');
                    const indicator = card.querySelector('.saved-indicator');
                    if (indicator) {
                        indicator.classList.add('show');
                        setTimeout(() => indicator.classList.remove('show'), 1500);
                    }
                }
            })
            .catch(err => console.error('Save error:', err));
        }
        
        function updateCardTotal(input) {
            const card = input.closest('.day-card');
            const inputs = card.querySelectorAll('input.auto-save');
            let total = 0;
            inputs.forEach(inp => {
                total += parseInt(inp.value) || 0;
            });
            const totalEl = card.querySelector('.day-total');
            if (totalEl) {
                totalEl.innerHTML = '<span>Total:</span> ' + total;
            }
        }

        // Bulk modal
        function openBulkModal() {
            document.getElementById('bulkModal').classList.add('show');
        }
        function closeBulkModal() {
            document.getElementById('bulkModal').classList.remove('show');
        }
        document.getElementById('bulkModal').addEventListener('click', function(e) {
            if (e.target === this) closeBulkModal();
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.activeElement.tagName === 'INPUT') {
                // Move to next input
                const inputs = Array.from(document.querySelectorAll('input.auto-save'));
                const idx = inputs.indexOf(document.activeElement);
                if (idx >= 0 && idx < inputs.length - 1) {
                    e.preventDefault();
                    inputs[idx + 1].focus();
                    inputs[idx + 1].select();
                }
            }
        });
    </script>
</body>
</html>