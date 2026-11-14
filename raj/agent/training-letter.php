<?php
// /raj/agent/joining-letter.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$agentId = $_SESSION['agent_id'] ?? '';

// Check if joining letter exists
$letter = executeQuery("SELECT * FROM joining_letters WHERE agent_id = ?", [$agentId]);
if (!$letter || $letter->num_rows === 0) {
    // No letter yet - redirect or show message
    $noLetter = true;
} else {
    $data = $letter->fetch_assoc();
    $isApproved = ($data['status'] === 'approved');
}

// Handle signature submission (only if pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data) && $data['status'] === 'pending') {
    $signatureData = $_POST['signature_data'] ?? '';
    $acknowledged = $_POST['acknowledged'] ?? '0';
    
    if (empty($signatureData)) {
        $error = "Please provide your signature.";
    } elseif ($acknowledged !== '1') {
        $error = "Please acknowledge the terms.";
    } else {
        executeQuery("UPDATE joining_letters SET signature_data = ?, agreed_at = NOW(), ip_address = ?, user_agent = ? WHERE agent_id = ?",
            [$signatureData, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $agentId]);
        header('Location: joining-letter.php?signed=1');
        exit;
    }
}

// Get company stamp
$stampQuery = executeQuery("SELECT company_stamp_path FROM joining_letters WHERE company_stamp_path IS NOT NULL ORDER BY id DESC LIMIT 1");
$stampPath = ($stampQuery && $stampQuery->num_rows > 0) ? $stampQuery->fetch_assoc()['company_stamp_path'] : '';

// Agent photo
$agent = getAgent($agentId);
$photoUrl = '';
if (!empty($agent['candidate_photo_path'])) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $agent['candidate_photo_path'];
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($agent['candidate_photo_path']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Joining Letter - <?php echo APP_NAME; ?></title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-950: #0c1a38; --navy-800: #163063; --gold-500: #c9962f;
            --cream: #f8f7f3; --paper: #ffffff; --ink: #1c2536; --ink-soft: #667085;
            --line: #ecebe6; --success: #1e7a41; --danger: #b3352f;
            --radius: 14px;
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f5f4f0;color:var(--ink);}
        .header{
            background:linear-gradient(135deg,var(--navy-950),var(--navy-800));
            color:#eef2fa; padding:14px 28px; display:flex;
            justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
        }
        .header .brand{font-family:'Fraunces',serif; font-size:16px; font-weight:600;}
        .header a{color:#eef2fa; text-decoration:none; font-size:12px; padding:6px 14px; border-radius:20px;}
        .content{max-width:850px; margin:0 auto; padding:20px 24px 40px;}
        
        .letter{
            background:var(--paper); border-radius:var(--radius); padding:45px 50px;
            box-shadow:0 4px 20px rgba(0,0,0,.08); border:2px solid #d4c5a0;
            position:relative; margin-bottom:20px;
        }
        .letter-header{
            display:flex; justify-content:space-between; align-items:flex-start;
            border-bottom:3px double var(--gold-500); padding-bottom:16px; margin-bottom:20px;
            flex-wrap:wrap; gap:20px;
        }
        .company-info{text-align:right; flex:1; min-width:200px;}
        .company-info h2{font-family:'Fraunces',serif; font-size:24px; color:var(--navy-950); margin:0;}
        .company-info p{font-size:11px; color:var(--ink-soft); margin:2px 0;}
        
        .ref-no{font-size:11px; color:var(--ink-soft); margin-bottom:12px;}
        .date-line{text-align:right; font-size:12px; margin-bottom:16px;}
        
        .subject{font-size:15px; font-weight:700; color:var(--navy-950); margin-bottom:16px; text-decoration:underline;}
        
        .content-text{font-size:13px; line-height:1.8; margin-bottom:16px;}
        .content-text p{margin-bottom:10px;}
        
        .details-table{width:100%; border-collapse:collapse; margin:16px 0; font-size:13px;}
        .details-table td{padding:6px 10px; border:1px solid #ddd;}
        .details-table td:first-child{font-weight:600; background:#f9f9f9; width:180px;}
        
        .sign-section{
            display:flex; justify-content:space-between; margin-top:40px;
            border-top:1px solid var(--line); padding-top:20px; flex-wrap:wrap; gap:20px;
        }
        .sign-box{text-align:center; min-width:150px;}
        .sign-box .sign-line{border-bottom:1px solid #000; width:180px; margin:40px auto 4px;}
        .sign-box img{max-width:150px; max-height:60px;}
        .sign-box .sign-name{font-size:11px; color:var(--ink-soft);}
        
        .stamp-img{position:absolute; bottom:60px; right:80px; opacity:.7; max-width:120px;}
        
        .photo-box{text-align:center; margin:10px 0;}
        .photo-box img{width:90px; height:90px; border-radius:8px; object-fit:cover; border:2px solid var(--line);}
        
        .btn-row{display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:16px;}
        .btn{
            padding:10px 24px; border:none; border-radius:25px; font-weight:600; font-size:12px;
            cursor:pointer; font-family:'Inter',sans-serif; display:inline-flex; align-items:center; gap:6px;
        }
        .btn-gold{background:var(--gold-500); color:var(--navy-950);}
        .btn-pdf{background:#e74c3c; color:#fff;}
        
        .sign-pad{border:2px dashed var(--line); border-radius:10px; background:#fff; cursor:crosshair; width:100%; height:120px; margin-bottom:8px;}
        .sign-pad canvas{width:100%; height:100%; border-radius:10px;}
        
        .disclaimer{
            font-size:10px; color:#999; text-align:center; margin-top:24px;
            border-top:1px solid #eee; padding-top:12px; font-style:italic;
        }
        
        .alert{padding:14px 20px; border-radius:var(--radius); margin-bottom:16px; font-size:13px;}
        .alert-info{background:#e6eefb; color:var(--info); border:1px solid var(--info);}
        .alert-success{background:#e4f4e8; color:var(--success); border:1px solid var(--success);}
        
        .copyright-footer{text-align:center; padding:20px; margin-top:30px; border-top:1px solid var(--line); font-size:12px; color:var(--ink-soft);}
        .copyright-footer a{color:var(--gold-500); text-decoration:none; font-weight:600;}
        
        @media(max-width:768px){.letter{padding:20px;}}
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">📝 Joining Letter</div>
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </header>

    <div class="content">
        <?php if(isset($noLetter)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Your joining letter has not been created yet. Please contact admin.
            </div>
        <?php elseif(isset($isApproved) && $isApproved): ?>
            <!-- APPROVED - Show PDF download only -->
            <div class="alert alert-success">
                ✅ Your joining letter has been <strong>approved</strong>! You can download the PDF below.
            </div>
            <div class="btn-row">
                <button class="btn btn-pdf" onclick="window.open('joining-letter-pdf.php','_blank')">
                    <i class="fas fa-file-pdf"></i> Download Joining Letter (PDF)
                </button>
            </div>
            <!-- Show preview -->
            <div class="letter" id="letterContent">
                <?php include 'joining-letter-content.php'; ?>
            </div>
        <?php elseif(isset($data) && $data['status'] === 'pending' && !empty($data['signature_data'])): ?>
            <!-- Signed, pending approval -->
            <div class="alert alert-info">
                ⏳ Your joining letter has been <strong>signed</strong> and is pending admin approval. Once approved, you can download the PDF.
            </div>
        <?php elseif(isset($data) && $data['status'] === 'pending'): ?>
            <!-- Pending signature -->
            <div class="alert alert-info">
                📝 Please sign your joining letter below. This is a one-time signature.
            </div>
            
            <div class="letter" id="letterContent">
                <?php 
                // Include letter content
                $letterData = $data;
                include 'joining-letter-content.php'; 
                ?>
                
                <div class="sign-section">
                    <div class="sign-box" style="flex:1;">
                        <h4>✍️ Your Signature</h4>
                        <div class="sign-pad"><canvas id="signatureCanvas"></canvas></div>
                        <button type="button" class="btn" style="background:var(--cream);" onclick="clearSignature()">
                            <i class="fas fa-eraser"></i> Clear
                        </button>
                    </div>
                    <div class="sign-box">
                        <h4>🏢 Company Stamp</h4>
                        <?php if(!empty($stampPath)): ?>
                            <img src="<?php echo $stampPath; ?>" alt="Company Stamp" style="max-width:120px;">
                        <?php else: ?>
                            <p style="font-size:11px;color:var(--ink-soft);">Stamp will appear after admin upload</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <form method="POST" id="signForm">
                    <input type="hidden" name="signature_data" id="signatureData">
                    <input type="hidden" name="acknowledged" id="ackField" value="0">
                    <div style="margin:16px 0;">
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" id="ack" onchange="document.getElementById('ackField').value=this.checked?'1':'0'" style="width:18px;height:18px;accent-color:var(--gold-500);margin-top:2px;">
                            <span><strong>I confirm</strong> that I have read and accept all terms and conditions of this joining letter.</span>
                        </label>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-gold" id="submitBtn">
                            <i class="fas fa-check-circle"></i> Sign & Submit
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="copyright-footer">
        <span>Copyright © 2026 <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family. All Rights Reserved.</span>
    </div>

    <script>
        const canvas = document.getElementById('signatureCanvas');
        if(canvas){
            const ctx = canvas.getContext('2d');
            let drawing = false;
            function resizeCanvas(){
                const pad = canvas.parentElement;
                canvas.width = pad.offsetWidth;
                canvas.height = pad.offsetHeight;
                ctx.strokeStyle = '#1c2536';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
            function getPos(e){
                const rect = canvas.getBoundingClientRect();
                const cx = e.touches ? e.touches[0].clientX : e.clientX;
                const cy = e.touches ? e.touches[0].clientY : e.clientY;
                return {x: cx - rect.left, y: cy - rect.top};
            }
            function start(e){ e.preventDefault(); drawing=true; const p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); }
            function move(e){ if(!drawing) return; e.preventDefault(); const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }
            function stop(){ drawing=false; document.getElementById('signatureData').value = canvas.toDataURL(); }
            canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move);
            canvas.addEventListener('mouseup',stop); canvas.addEventListener('mouseleave',stop);
            canvas.addEventListener('touchstart',start); canvas.addEventListener('touchmove',move);
            canvas.addEventListener('touchend',stop);
        }
        function clearSignature(){
            const c = document.getElementById('signatureCanvas').getContext('2d');
            c.clearRect(0,0,canvas.width,canvas.height);
            document.getElementById('signatureData').value = '';
        }
        document.getElementById('signForm')?.addEventListener('submit',function(e){
            const sig = document.getElementById('signatureData').value;
            const ack = document.getElementById('ack').checked;
            if(!ack){ e.preventDefault(); alert('Please acknowledge the terms.'); return; }
            if(!sig||sig==='data:,'){
                e.preventDefault(); alert('Please provide your signature.'); return;
            }
            if(!confirm('This is a one-time signature. Continue?')){ e.preventDefault(); return; }
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
    </script>
</body>
</html>