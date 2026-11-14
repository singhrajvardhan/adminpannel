<?php
// /raj/admin/json-storage-manager.php
require_once __DIR__ . '/../includes/auth.php';
requireAdminAuth();
require_once __DIR__ . '/../includes/json-storage.php';

$storage = getJsonStorage();
$stats = $storage->getStats();
$action = $_GET['action'] ?? '';
$agentId = $_GET['agent_id'] ?? '';

if ($action === 'export' && $agentId) {
    $data = $storage->exportAgentData($agentId);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $agentId . '_export_' . date('Y-m-d') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'backups' && $agentId) {
    $backups = $storage->getBackups($agentId, 'profile.json');
    echo json_encode($backups);
    exit;
}

if ($action === 'restore' && $agentId && isset($_GET['backup'])) {
    $result = $storage->restoreBackup($agentId, 'profile.json', $_GET['backup']);
    if ($result) {
        $msg = 'Backup restored successfully!';
    } else {
        $msg = 'Failed to restore backup.';
    }
    header('Location: json-storage-manager.php?msg=' . urlencode($msg));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSON Storage Manager - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
        .container { max-width: 1200px; margin: 0 auto; padding: 24px 30px 40px; }
        .header {
            background: radial-gradient(120% 140% at 100% 0%, rgba(201,150,47,.14), transparent 55%),
                linear-gradient(190deg, var(--navy-950), var(--navy-800) 90%);
            color: #eef2fa;
            padding: 14px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: var(--shadow-md);
        }
        .header .brand { display: flex; align-items: center; gap: 12px; }
        .header .brand .logo-mark {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Fraunces', serif; font-weight: 700; font-size: 15px;
            color: var(--navy-950);
        }
        .header .brand .brand-text {
            font-family: 'Fraunces', serif;
            font-size: 16px; font-weight: 600; color: #fff;
        }
        .header .brand .brand-text span {
            color: var(--gold-300); font-size: 10px; letter-spacing: 2px;
            text-transform: uppercase; display: block; font-weight: 400;
        }
        .header .user-info { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .header .logout {
            color: #f2a3a0; text-decoration: none; padding: 6px 16px;
            border-radius: var(--radius-sm); background: rgba(255,255,255,.08);
            transition: background .25s var(--ease), transform .25s var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
            font-weight: 500; font-size: 13px;
        }
        .header .logout:hover {
            background: rgba(240,90,80,.15); transform: translateY(-1px);
        }
        .back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--navy-800); text-decoration: none; font-weight: 600;
            margin-bottom: 20px; padding: 8px 16px;
            background: var(--paper); border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm); transition: transform .2s;
        }
        .back-btn:hover { transform: translateX(-4px); }
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: var(--paper); padding: 20px 22px;
            border-radius: var(--radius); box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            transition: transform .3s var(--ease), box-shadow .3s var(--ease);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card .number {
            font-family: 'Fraunces', serif;
            font-size: 28px; font-weight: 700; color: var(--navy-950);
        }
        .stat-card .label { font-size: 13px; color: var(--ink-soft); font-weight: 500; margin-top: 2px; }
        .stat-card .icon { font-size: 20px; float: right; color: var(--gold-500); }
        .table-container {
            background: var(--paper); border-radius: var(--radius);
            padding: 20px 22px; box-shadow: var(--shadow-sm);
            border: 1px solid var(--line); overflow-x: auto; margin-bottom: 20px;
        }
        .table-container h2 {
            font-family: 'Fraunces', serif; color: var(--navy-950); font-size: 18px;
            margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; padding: 10px 12px; background: transparent;
            color: var(--ink-soft); font-weight: 600; font-size: 11px;
            text-transform: uppercase; letter-spacing: .5px;
            border-bottom: 1px solid var(--line); white-space: nowrap;
        }
        td { padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 13px; }
        tbody tr:hover { background: #faf9f6; }
        .btn {
            padding: 6px 14px; border: none; border-radius: var(--radius-sm);
            font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px;
            font-family: 'Inter', sans-serif; transition: transform .2s, box-shadow .2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-primary { background: var(--navy-800); color: white; }
        .btn-success { background: var(--success-ink); color: white; }
        .btn-gold { background: linear-gradient(135deg, var(--gold-400), var(--gold-500)); color: var(--navy-950); }
        .btn-secondary { background: var(--cream); color: var(--ink-soft); border: 1px solid var(--line); }
        .btn-sm { padding: 4px 10px; font-size: 10px; }
        .msg { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 16px; }
        .msg-success { background: var(--success-bg); color: var(--success-ink); border-left: 4px solid var(--success-ink); }
        .msg-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: stretch; text-align: center; }
            .header .brand { justify-content: center; }
            .header .user-info { justify-content: center; flex-wrap: wrap; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .container { padding: 16px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">
            <div class="logo-mark">BG</div>
            <div class="brand-text">BetterGrowth<span>Solutions</span></div>
        </div>
        <div class="user-info">
            <span>Admin</span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>
    <div class="container">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <?php if (isset($_GET['msg'])): ?>
            <div class="msg msg-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>

        <h1 style="font-family:'Fraunces',serif;color:var(--navy-950);font-size:26px;margin-bottom:20px;">
            <i class="fas fa-database" style="color:var(--gold-500);"></i> JSON Storage Manager
        </h1>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="icon"><i class="fas fa-users"></i></span>
                <div class="number"><?php echo $stats['total_agents']; ?></div>
                <div class="label">Total Agents</div>
            </div>
            <div class="stat-card">
                <span class="icon"><i class="fas fa-hard-drive"></i></span>
                <div class="number"><?php echo $stats['total_size_formatted']; ?></div>
                <div class="label">Storage Used</div>
            </div>
            <div class="stat-card">
                <span class="icon"><i class="fas fa-history"></i></span>
                <div class="number"><?php echo $stats['backup_count']; ?></div>
                <div class="label">Backups Created</div>
            </div>
        </div>

        <div class="table-container">
            <h2><i class="fas fa-list" style="color:var(--gold-500);"></i> Agent Storage Details</h2>
            <table>
                <thead>
                    <tr>
                        <th>Agent ID</th>
                        <th>Storage Size</th>
                        <th>Backups</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['agents'] as $id => $info): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($id); ?></strong></td>
                        <td><?php echo $info['size_formatted']; ?></td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="viewBackups('<?php echo $id; ?>')">
                                <i class="fas fa-history"></i> View
                            </button>
                        </td>
                        <td>
                            <a href="?action=export&agent_id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-download"></i> Export
                            </a>
                            <button class="btn btn-success btn-sm" onclick="viewProfile('<?php echo $id; ?>')">
                                <i class="fas fa-eye"></i> View Profile
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Backup Modal -->
        <div id="backupModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(12,26,56,.7);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
            <div style="background:var(--paper);border-radius:var(--radius);padding:30px;max-width:600px;width:92%;max-height:80vh;overflow-y:auto;box-shadow:var(--shadow-lg);">
                <h2 style="font-family:'Fraunces',serif;color:var(--navy-950);font-size:20px;margin-bottom:16px;">
                    <i class="fas fa-history" style="color:var(--gold-500);"></i> Backups
                </h2>
                <div id="backupList"></div>
                <div style="margin-top:16px;text-align:right;">
                    <button onclick="closeBackupModal()" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>

        <div style="margin-top:16px;padding:16px 20px;background:var(--warn-bg);border-radius:var(--radius-sm);border-left:4px solid var(--warn-ink);">
            <p style="color:var(--warn-ink);font-size:13px;">
                <i class="fas fa-shield-alt"></i> 
                <strong>JSON Storage Safety Features:</strong>
                <span style="display:block;margin-top:4px;">
                    • <strong>flock()</strong> - File locking prevents concurrent writes<br>
                    • <strong>Atomic Writes</strong> - Write to temp file then rename<br>
                    • <strong>Backups</strong> - Automatic version history with timestamps<br>
                    • <strong>Encryption</strong> - Sensitive data encrypted at rest
                </span>
            </p>
        </div>
    </div>

    <script>
        function viewBackups(agentId) {
            const modal = document.getElementById('backupModal');
            const list = document.getElementById('backupList');
            modal.style.display = 'flex';
            list.innerHTML = '<p>Loading...</p>';
            
            fetch('?action=backups&agent_id=' + agentId)
                .then(r => r.json())
                .then(data => {
                    if (data.length === 0) {
                        list.innerHTML = '<p style="color:var(--ink-soft);">No backups found.</p>';
                        return;
                    }
                    let html = '<table style="width:100%;">';
                    html += '<tr><th>Date/Time</th><th>Size</th><th>Action</th></tr>';
                    data.forEach(backup => {
                        html += `<tr>
                            <td>${backup.datetime}</td>
                            <td>${(backup.size / 1024).toFixed(1)} KB</td>
                            <td>
                                <a href="?action=restore&agent_id=${agentId}&backup=${backup.timestamp}" 
                                   class="btn btn-gold btn-sm" onclick="return confirm('Restore this backup?')">
                                    <i class="fas fa-undo"></i> Restore
                                </a>
                            </td>
                        </tr>`;
                    });
                    html += '</table>';
                    list.innerHTML = html;
                })
                .catch(() => {
                    list.innerHTML = '<p style="color:red;">Failed to load backups.</p>';
                });
        }

        function closeBackupModal() {
            document.getElementById('backupModal').style.display = 'none';
        }

        function viewProfile(agentId) {
            window.location.href = 'view-agent.php?id=' + agentId;
        }

        document.getElementById('backupModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeBackupModal();
        });
    </script>
</body>
</html>