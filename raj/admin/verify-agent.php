<?php
// /raj/admin/verify-agent.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuth();

$agentId = $_GET['id'] ?? '';
if (!$agentId) {
    die('Agent ID required');
}

$action = $_GET['action'] ?? '';
if (!in_array($action, ['approve', 'reject', 'correction'])) {
    die('Invalid action');
}

$agent = getAgent($agentId);
if (!$agent) {
    die('Agent not found');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $reason = trim($_POST['reason'] ?? '');
        $status = '';
        $statusLabel = '';
        
        if ($action === 'approve') {
            $status = 'verified';
            $statusLabel = 'Approved';
        } elseif ($action === 'reject') {
            if (empty($reason)) {
                $error = 'Reason is required for rejection.';
            } else {
                $status = 'rejected';
                $statusLabel = 'Rejected';
            }
        } elseif ($action === 'correction') {
            if (empty($reason)) {
                $error = 'Reason is required for correction request.';
            } else {
                $status = 'correction_requested';
                $statusLabel = 'Correction Requested';
            }
        }
        
        if (empty($error) && !empty($status)) {
            $updateSql = "UPDATE agents SET 
                verification_status = ?, 
                verified_by = 'admin',
                verification_timestamp = NOW(),
                verification_reason = ?
                WHERE agent_id = ?";
            $result = executeQuery($updateSql, [$status, $reason, $agentId]);
            
            if ($result !== false) {
                addAuditLog($agentId, $statusLabel, $reason);
                $success = "Agent <strong>$agentId</strong> has been <strong>$statusLabel</strong> successfully!";
                
                // Redirect after 2 seconds
                echo '<meta http-equiv="refresh" content="2;url=view-agent.php?id=' . $agentId . '&msg=' . urlencode($statusLabel) . '">';
            } else {
                $error = 'Failed to update agent status. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();

// Get status badge color
$statusColors = [
    'pending' => 'badge-info',
    'verified' => 'badge-success',
    'rejected' => 'badge-danger',
    'correction_requested' => 'badge-warning'
];

$actionColors = [
    'approve' => ['bg' => '#d4edda', 'color' => '#155724', 'icon' => 'fa-check-circle', 'label' => 'Approve'],
    'reject' => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => 'fa-times-circle', 'label' => 'Reject'],
    'correction' => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => 'fa-edit', 'label' => 'Request Correction']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Agent - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 560px;
            width: 100%;
            background: var(--paper);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 40px 44px;
            position: relative;
            overflow: hidden;
        }

        /* Decorative line at top */
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold-500), var(--gold-300), var(--gold-500));
        }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 6px;
        }

        .header .icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .header .icon-wrapper.approve {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .header .icon-wrapper.reject {
            background: var(--danger-bg);
            color: var(--danger-ink);
        }

        .header .icon-wrapper.correction {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }

        .header h1 {
            font-family: 'Fraunces', serif;
            font-size: 24px;
            font-weight: 600;
            color: var(--navy-950);
        }

        .subtitle {
            color: var(--ink-soft);
            font-size: 14px;
            margin-bottom: 24px;
            padding-left: 62px;
        }

        /* Action Badge */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .action-badge.approve {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .action-badge.reject {
            background: var(--danger-bg);
            color: var(--danger-ink);
        }

        .action-badge.correction {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }

        /* Agent Info Card */
        .agent-card {
            background: var(--cream);
            border-radius: var(--radius-sm);
            padding: 18px 22px;
            margin-bottom: 24px;
            border: 1px solid var(--line);
        }

        .agent-card .agent-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 14px;
        }

        .agent-card .agent-row .label {
            color: var(--ink-soft);
            font-weight: 500;
        }

        .agent-card .agent-row .value {
            font-weight: 600;
            color: var(--ink);
        }

        .agent-card .agent-row .value .badge {
            font-size: 12px;
            padding: 2px 12px;
            border-radius: 999px;
            font-weight: 600;
        }

        .badge-success {
            background: var(--success-bg);
            color: var(--success-ink);
        }

        .badge-danger {
            background: var(--danger-bg);
            color: var(--danger-ink);
        }

        .badge-warning {
            background: var(--warn-bg);
            color: var(--warn-ink);
        }

        .badge-info {
            background: var(--info-bg);
            color: var(--info-ink);
        }

        /* Message Box */
        .message-box {
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
        }

        .message-box i {
            font-size: 18px;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .message-box.approve {
            background: var(--success-bg);
            color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }

        .message-box.reject {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }

        .message-box.correction {
            background: var(--warn-bg);
            color: var(--warn-ink);
            border-left: 4px solid var(--warn-ink);
        }

        .message-box.error {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border-left: 4px solid var(--danger-ink);
        }

        .message-box.success {
            background: var(--success-bg);
            color: var(--success-ink);
            border-left: 4px solid var(--success-ink);
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
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

        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: border-color .25s var(--ease), box-shadow .25s var(--ease);
            background: var(--cream);
            color: var(--ink);
            resize: vertical;
            min-height: 100px;
        }

        .form-group textarea:focus {
            border-color: var(--gold-500);
            outline: none;
            box-shadow: 0 0 0 4px rgba(201,150,47,.12);
            background: var(--paper);
        }

        .form-group textarea::placeholder {
            color: #aaa;
        }

        .form-group .char-count {
            text-align: right;
            font-size: 12px;
            color: var(--ink-soft);
            margin-top: 4px;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
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
            flex: 1;
            justify-content: center;
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

        .btn-success {
            background: var(--success-ink);
            color: white;
        }

        .btn-success:hover {
            background: #156b34;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger-ink);
            color: white;
        }

        .btn-danger:hover {
            background: #992b26;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: var(--warn-ink);
            color: white;
        }

        .btn-warning:hover {
            background: #7a550d;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--cream);
            color: var(--ink-soft);
            border: 2px solid var(--line);
            flex: 0.5;
        }

        .btn-secondary:hover {
            background: var(--line);
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Success animation */
        .success-animation {
            text-align: center;
            padding: 20px 0;
        }

        .success-animation .checkmark {
            font-size: 64px;
            color: var(--success-ink);
            animation: popIn .6s var(--ease);
        }

        @keyframes popIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-animation h3 {
            font-family: 'Fraunces', serif;
            color: var(--navy-950);
            margin-top: 12px;
        }

        .success-animation p {
            color: var(--ink-soft);
            font-size: 14px;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .container {
                padding: 28px 20px;
            }

            .header {
                flex-direction: column;
                text-align: center;
            }

            .subtitle {
                padding-left: 0;
                text-align: center;
            }

            .header h1 {
                font-size: 20px;
            }

            .action-badge {
                justify-content: center;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                flex: 1;
                width: 100%;
                justify-content: center;
            }

            .btn-secondary {
                flex: 1;
            }

            .agent-card .agent-row {
                flex-direction: column;
                gap: 2px;
            }

            .agent-card .agent-row .value {
                font-weight: 600;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!empty($success)): ?>
            <!-- Success State -->
            <div class="success-animation">
                <div class="checkmark">
                    <?php if ($action === 'approve'): ?>
                        ✅
                    <?php elseif ($action === 'reject'): ?>
                        ⚠️
                    <?php else: ?>
                        📝
                    <?php endif; ?>
                </div>
                <h3><?php echo $success; ?></h3>
                <p>Redirecting back to agent details...</p>
                <div style="margin-top:16px;">
                    <a href="view-agent.php?id=<?php echo $agentId; ?>" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Agent
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="header">
                <div class="icon-wrapper <?php echo $action; ?>">
                    <?php if ($action === 'approve'): ?>
                        <i class="fas fa-check"></i>
                    <?php elseif ($action === 'reject'): ?>
                        <i class="fas fa-times"></i>
                    <?php else: ?>
                        <i class="fas fa-edit"></i>
                    <?php endif; ?>
                </div>
                <h1>Verify Agent</h1>
            </div>
            <p class="subtitle"><?php echo ucfirst($action); ?> agent: <strong><?php echo htmlspecialchars($agentId); ?></strong></p>

            <!-- Action Badge -->
            <div class="action-badge <?php echo $action; ?>">
                <i class="fas <?php echo $actionColors[$action]['icon']; ?>"></i>
                <?php echo $actionColors[$action]['label']; ?> Agent
            </div>

            <!-- Agent Info -->
            <div class="agent-card">
                <div class="agent-row">
                    <span class="label">Name</span>
                    <span class="value"><?php echo htmlspecialchars($agent['full_name'] ?: 'N/A'); ?></span>
                </div>
                <div class="agent-row">
                    <span class="label">Email</span>
                    <span class="value"><?php echo htmlspecialchars($agent['email'] ?: 'N/A'); ?></span>
                </div>
                <div class="agent-row">
                    <span class="label">Mobile</span>
                    <span class="value"><?php echo htmlspecialchars($agent['mobile'] ?: 'N/A'); ?></span>
                </div>
                <div class="agent-row">
                    <span class="label">Current Status</span>
                    <span class="value">
                        <span class="badge <?php echo $statusColors[$agent['verification_status']] ?? 'badge-info'; ?>">
                            <?php echo ucfirst($agent['verification_status'] ?: 'Pending'); ?>
                        </span>
                    </span>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="message-box error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrfField(); ?>

                <?php if ($action === 'approve'): ?>
                    <div class="message-box approve">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Are you sure you want to approve this agent?</strong>
                            <p style="margin-top:4px; font-weight:400;">This action will mark the agent as verified and complete their onboarding process.</p>
                        </div>
                    </div>
                    <input type="hidden" name="reason" value="Approved by admin">
                <?php else: ?>
                    <div class="form-group">
                        <label for="reason">
                            Reason for <?php echo $action === 'reject' ? 'Rejection' : 'Correction Request'; ?>
                            <span class="required">*</span>
                        </label>
                        <textarea id="reason" name="reason" 
                                  placeholder="<?php echo $action === 'reject' ? 'Please provide a detailed reason for rejection...' : 'Please specify what needs to be corrected...'; ?>" 
                                  required
                                  maxlength="500"
                                  oninput="document.getElementById('charCount').textContent = this.value.length + '/500'"></textarea>
                        <div class="char-count">
                            <span id="charCount">0/500</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="btn-group">
                    <button type="submit" class="btn 
                        <?php echo $action === 'approve' ? 'btn-success' : ($action === 'reject' ? 'btn-danger' : 'btn-warning'); ?>">
                        <?php if ($action === 'approve'): ?>
                            <i class="fas fa-check"></i> Confirm Approve
                        <?php elseif ($action === 'reject'): ?>
                            <i class="fas fa-times"></i> Confirm Reject
                        <?php else: ?>
                            <i class="fas fa-edit"></i> Confirm Correction
                        <?php endif; ?>
                    </button>
                    <a href="view-agent.php?id=<?php echo $agentId; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Auto-dismiss error after 5 seconds
        const errorBox = document.querySelector('.message-box.error');
        if (errorBox) {
            setTimeout(function() {
                errorBox.style.opacity = '0';
                errorBox.style.transition = 'opacity 0.5s ease';
                setTimeout(function() {
                    if (errorBox.parentNode) errorBox.remove();
                }, 500);
            }, 5000);
        }

        // Character counter for textarea
        const textarea = document.getElementById('reason');
        if (textarea) {
            textarea.addEventListener('input', function() {
                const count = this.value.length;
                document.getElementById('charCount').textContent = count + '/500';
                if (count > 450) {
                    document.getElementById('charCount').style.color = '#b3352f';
                } else {
                    document.getElementById('charCount').style.color = '';
                }
            });
        }
    </script>
</body>
</html>