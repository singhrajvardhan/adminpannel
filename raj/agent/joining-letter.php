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
$agent = getAgent($agentId);
if (!$agent) { header('Location: login.php'); exit; }

// ============================================================
// Check if compliance is signed first
// ============================================================
$complianceCheck = executeQuery("SELECT * FROM compliance_agreements WHERE agent_id = ?", [$agentId]);
$complianceSigned = ($complianceCheck && $complianceCheck->num_rows > 0);
$complianceData = $complianceSigned ? $complianceCheck->fetch_assoc() : null;
$complianceApproved = $complianceSigned && ($complianceData['status'] ?? '') === 'approved';

if (!$complianceSigned) {
    header('Location: compliance.php?redirect=joining');
    exit;
}

// Check if joining letter exists
$letterQuery = executeQuery("SELECT * FROM joining_letters WHERE agent_id = ?", [$agentId]);
$letterExists = ($letterQuery && $letterQuery->num_rows > 0);
$letter = $letterExists ? $letterQuery->fetch_assoc() : null;

// Get company stamp
$stampQuery = executeQuery("SELECT setting_value FROM company_settings WHERE setting_key = 'company_stamp'");
$stampPath = '';
if ($stampQuery && $stampQuery->num_rows > 0) {
    $stampPath = $stampQuery->fetch_assoc()['setting_value'];
}
if (empty($stampPath)) {
    $stampQuery2 = executeQuery("SELECT company_stamp_path FROM joining_letters WHERE company_stamp_path IS NOT NULL AND company_stamp_path != '' ORDER BY id DESC LIMIT 1");
    if ($stampQuery2 && $stampQuery2->num_rows > 0) {
        $stampPath = $stampQuery2->fetch_assoc()['company_stamp_path'];
    }
}

// Agent photo
$photoUrl = '';
if (!empty($agent['candidate_photo_path'])) {
    $fullPath = __DIR__ . '/../storage/agents/' . $agentId . '/' . $agent['candidate_photo_path'];
    if (file_exists($fullPath)) {
        $photoUrl = '/raj/api/upload-document.php?action=serve&agent=' . $agentId . '&file=' . urlencode($agent['candidate_photo_path']);
    }
}

$name = $agent['full_name'] ?: 'Employee';
$email = $agent['email'] ?: 'N/A';
$phone = $agent['mobile'] ?: 'N/A';
$joiningDate = $letter['joining_date'] ?? $agent['joining_date'] ?? date('Y-m-d');
$designation = $letter['designation'] ?? $agent['designation'] ?? 'Sales Executive';
$department = $letter['department'] ?? $agent['department'] ?? 'Sales';
$salary = $letter['salary'] ?? $agent['salary'] ?? 13000;
$shiftType = $letter['shift_type'] ?? $agent['shift_type'] ?? 'night';
$workLocation = $letter['work_location'] ?? $agent['work_location'] ?? 'home';

// Check for salary revision
$salaryRevised = !empty($letter['salary_revised']) && $letter['salary_revised'] > 0;
$revisedSalary = $letter['salary_revised'] ?? 0;
$revisionReason = $letter['salary_revision_reason'] ?? '';
$revisionDate = $letter['salary_revision_date'] ?? '';
$adminComment = $letter['admin_comment'] ?? '';
$performanceRemarks = $letter['performance_remarks'] ?? '';

$displaySalary = $salaryRevised ? $revisedSalary : $salary;

$isPending = $letter && $letter['status'] === 'pending';
$isApproved = $letter && $letter['status'] === 'approved';
$isRejected = $letter && $letter['status'] === 'rejected';
$isSigned = $letter && !empty($letter['signature_data']);
$canSign = $isPending && !$isSigned;

// Handle signature submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSign) {
    $signatureData = $_POST['signature_data'] ?? '';
    $acknowledged = $_POST['acknowledged'] ?? '0';
    $termsAgreed = $_POST['terms_agreed'] ?? '0';
    
    $allComplianceChecked = true;
    for ($i = 1; $i <= 9; $i++) {
        if (($_POST['join_policy_' . $i] ?? '0') !== '1') {
            $allComplianceChecked = false;
            break;
        }
    }
    
    $errors = [];
    if (empty($signatureData) || $signatureData === 'data:,') {
        $errors[] = "Please draw your signature.";
    }
    if ($acknowledged !== '1') {
        $errors[] = "Please acknowledge that you have read all terms.";
    }
    if ($termsAgreed !== '1') {
        $errors[] = "Please agree to all terms and conditions.";
    }
    if (!$allComplianceChecked) {
        $errors[] = "Please check ALL compliance policy sections.";
    }
    
    if (empty($errors)) {
        $result = executeQuery(
            "UPDATE joining_letters SET signature_data = ?, agreed_at = NOW(), ip_address = ?, user_agent = ? WHERE agent_id = ?",
            [$signatureData, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $agentId]
        );
        
        if ($result !== false) {
            header('Location: joining-letter.php?signed=success');
            exit;
        } else {
            $errorMsg = "Failed to save signature. Please try again.";
        }
    } else {
        $errorMsg = implode('<br>', $errors);
    }
}

$signedSuccess = isset($_GET['signed']) && $_GET['signed'] === 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Joining Letter - <?php echo APP_NAME; ?></title>
    <link rel="icon" href="/bettergrowthsolutions.jpg" type="image/jpeg" />
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
            --gold-100: #fdf1de;
            --cream: #f8f7f3;
            --paper: #ffffff;
            --ink: #1c2536;
            --ink-soft: #667085;
            --line: #ecebe6;
            --success: #1e7a41;
            --danger: #b3352f;
            --warn: #9a6a10;
            --info: #29538c;
            --radius: 18px;
            --radius-sm: 12px;
            --shadow-sm: 0 2px 8px rgba(12,26,56,.05);
            --shadow-md: 0 8px 24px rgba(12,26,56,.1);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f5f4f0; color:var(--ink); }

        /* ===== HEADER ===== */
        .header {
            background:linear-gradient(135deg,var(--navy-950),var(--navy-800));
            color:#eef2fa;
            padding:12px 28px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.15);
            position:sticky;
            top:0;
            z-index:100;
        }
        .header-left {
            display:flex;
            align-items:center;
            gap:14px;
        }
        .header-logo {
            height:40px;
            width:auto;
            border-radius:8px;
            background:rgba(255,255,255,.08);
            padding:4px 10px;
        }
        .header-logo img {
            height:100%;
            width:auto;
        }
        .header-brand {
            font-family:'Fraunces',serif;
            font-size:18px;
            font-weight:700;
            letter-spacing:-0.5px;
            color:#f5e7c8;
        }
        .header-brand span {
            color:var(--gold-400);
        }
        .header-nav {
            display:flex;
            gap:4px;
            flex-wrap:wrap;
        }
        .header-nav a {
            color:#eef2fa;
            text-decoration:none;
            padding:6px 16px;
            border-radius:20px;
            font-size:12px;
            font-weight:500;
            transition:all .25s;
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:rgba(255,255,255,.04);
        }
        .header-nav a:hover {
            background:rgba(255,255,255,.12);
            transform:translateY(-1px);
        }
        .header-nav a.active {
            background:var(--gold-500);
            color:var(--navy-950);
        }

        /* ===== CONTENT ===== */
        .content {
            max-width:960px;
            margin:0 auto;
            padding:20px 24px 40px;
        }

        /* ===== ALERTS ===== */
        .alert {
            padding:14px 22px;
            border-radius:var(--radius-sm);
            margin-bottom:16px;
            font-size:13px;
            line-height:1.6;
            display:flex;
            align-items:flex-start;
            gap:10px;
        }
        .alert-success { background:#e4f4e8; color:var(--success); border:1px solid var(--success); }
        .alert-error { background:#fbe7e7; color:var(--danger); border:1px solid var(--danger); }
        .alert-info { background:#e6eefb; color:var(--info); border:1px solid var(--info); }
        .alert-warning { background:var(--gold-100); color:var(--warn); border:1px solid var(--warn); }
        .alert .alert-icon { font-size:18px; flex-shrink:0; margin-top:1px; }

        /* ===== COMPLIANCE BADGE ===== */
        .compliance-badge {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:6px 18px;
            border-radius:20px;
            font-size:12px;
            font-weight:600;
        }
        .compliance-badge.done { background:#e4f4e8; color:var(--success); }
        .compliance-badge.pending { background:#fdf1de; color:var(--warn); }

        /* ===== STATUS CARD ===== */
        .status-card {
            background:var(--paper);
            border-radius:var(--radius);
            padding:40px 30px;
            text-align:center;
            box-shadow:var(--shadow-sm);
            border:1px solid var(--line);
            margin-bottom:20px;
        }
        .status-card .status-icon {
            font-size:70px;
            margin-bottom:10px;
            display:block;
        }
        .status-card h2 {
            font-family:'Fraunces',serif;
            font-size:22px;
            color:var(--navy-950);
            margin-bottom:8px;
        }
        .status-badge {
            display:inline-block;
            padding:8px 24px;
            border-radius:25px;
            font-weight:700;
            font-size:13px;
            margin:8px 0;
        }
        .status-badge.pending { background:#fdf1de; color:var(--warn); }
        .status-badge.approved { background:#e4f4e8; color:var(--success); }
        .status-badge.rejected { background:#fbe7e7; color:var(--danger); }

        /* ===== SALARY REVISION NOTICE ===== */
        .salary-revision-notice {
            margin:12px 0;
            padding:14px 20px;
            background:linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-radius:var(--radius-sm);
            border-left:5px solid var(--success);
            text-align:left;
        }
        .salary-revision-notice .revision-title {
            font-weight:700;
            color:var(--success);
            font-size:14px;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .salary-revision-notice .revision-details {
            margin-top:4px;
            font-size:13px;
            color:var(--ink);
        }
        .salary-revision-notice .revision-details strong {
            color:var(--navy-800);
        }
        .salary-revision-notice .revision-reason {
            margin-top:6px;
            padding:8px 12px;
            background:rgba(255,255,255,0.6);
            border-radius:6px;
            font-size:12px;
            color:var(--ink-soft);
            border:1px solid #a5d6a7;
        }
        .salary-revision-notice .revision-reason i {
            color:var(--gold-500);
            margin-right:4px;
        }

        /* ===== ADMIN COMMENT ===== */
        .admin-comment-box {
            margin:12px 0;
            padding:14px 20px;
            background:#fff3e0;
            border-radius:var(--radius-sm);
            border-left:5px solid #ff9800;
            text-align:left;
        }
        .admin-comment-box .comment-title {
            font-weight:700;
            color:#e65100;
            font-size:13px;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .admin-comment-box .comment-text {
            margin-top:4px;
            font-size:13px;
            color:var(--ink);
            padding:8px 12px;
            background:rgba(255,255,255,0.6);
            border-radius:6px;
            border:1px solid #ffcc80;
        }
        .admin-comment-box .comment-text i {
            color:var(--gold-500);
            margin-right:4px;
        }

        /* ===== PERFORMANCE REMARKS ===== */
        .performance-remarks-box {
            margin:12px 0;
            padding:14px 20px;
            background:#e3f2fd;
            border-radius:var(--radius-sm);
            border-left:5px solid #1976d2;
            text-align:left;
        }
        .performance-remarks-box .remarks-title {
            font-weight:700;
            color:#0d47a1;
            font-size:13px;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .performance-remarks-box .remarks-text {
            margin-top:4px;
            font-size:13px;
            color:var(--ink);
            padding:8px 12px;
            background:rgba(255,255,255,0.6);
            border-radius:6px;
            border:1px solid #90caf9;
        }

        /* ===== LETTER CARD ===== */
        .letter-card {
            background:var(--paper);
            border-radius:var(--radius);
            padding:44px 48px;
            box-shadow:var(--shadow-md);
            border:2px solid #d4c5a0;
            position:relative;
            margin-bottom:20px;
        }
        .letter-card::before {
            content:'';
            position:absolute;
            top:0; left:0; right:0; bottom:0;
            background:linear-gradient(135deg,rgba(201,150,47,.03),transparent);
            border-radius:var(--radius);
            pointer-events:none;
        }

        /* ===== LETTER HEADER ===== */
        .letter-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            border-bottom:3px double var(--gold-500);
            padding-bottom:18px;
            margin-bottom:22px;
            flex-wrap:wrap;
            gap:20px;
            position:relative;
        }
        .letter-header .logo-section {
            display:flex;
            align-items:center;
            gap:16px;
        }
        .letter-header .logo-img {
            height:56px;
            width:auto;
            border-radius:10px;
            background:var(--cream);
            padding:4px 10px;
            border:1px solid var(--gold-100);
        }
        .letter-header .logo-img img {
            height:100%;
            width:auto;
        }
        .letter-header .brand-text {
            font-family:'Fraunces',serif;
            font-size:20px;
            font-weight:700;
            color:var(--navy-950);
            line-height:1.2;
        }
        .letter-header .brand-text .gold {
            color:var(--gold-500);
        }
        .letter-header .brand-text .sub {
            font-size:9px;
            font-weight:400;
            color:var(--ink-soft);
            letter-spacing:2.5px;
            text-transform:uppercase;
            display:block;
            font-family:'Inter',sans-serif;
        }

        .company-info {
            text-align:right;
            flex:1;
            min-width:200px;
        }
        .company-info h2 {
            font-family:'Fraunces',serif;
            font-size:20px;
            color:var(--navy-950);
            margin:0;
        }
        .company-info .tagline {
            font-size:9px;
            color:var(--gold-500);
            letter-spacing:2.5px;
            text-transform:uppercase;
            font-weight:600;
        }
        .company-info p {
            font-size:11px;
            color:var(--ink-soft);
            margin:2px 0;
        }
        .ref-no {
            font-size:10px;
            color:var(--ink-soft);
            letter-spacing:0.5px;
            font-weight:500;
        }
        .date-line {
            text-align:right;
            font-size:12px;
            color:var(--ink-soft);
            margin-bottom:16px;
        }

        .subject {
            font-size:16px;
            font-weight:700;
            color:var(--navy-950);
            margin-bottom:16px;
            text-decoration:underline;
            text-underline-offset:5px;
            text-decoration-color:var(--gold-500);
            text-decoration-thickness:2px;
        }
        .salutation {
            font-size:14px;
            margin-bottom:14px;
            line-height:1.7;
        }
        .salutation strong {
            color:var(--navy-800);
        }

        /* ===== PHOTO ===== */
        .photo-row {
            text-align:center;
            margin:14px 0 22px;
        }
        .photo-row img {
            width:110px;
            height:110px;
            border-radius:12px;
            object-fit:cover;
            border:4px solid var(--gold-500);
            box-shadow:var(--shadow-sm);
        }

        /* ===== DETAILS TABLE ===== */
        .details-table {
            width:100%;
            border-collapse:collapse;
            margin:18px 0;
            font-size:13px;
            border-radius:var(--radius-sm);
            overflow:hidden;
        }
        .details-table td {
            padding:10px 16px;
            border:1px solid #e8e6dd;
        }
        .details-table td:first-child {
            font-weight:600;
            background:#faf8f3;
            width:170px;
            color:var(--ink-soft);
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        .details-table td:last-child {
            background:#fff;
        }
        .details-table tr:hover td {
            background:#fdfcf7;
        }
        .details-table .revised-salary {
            background:#e8f5e9 !important;
            font-weight:700;
            color:var(--success);
        }
        .details-table .original-salary {
            text-decoration:line-through;
            color:var(--ink-soft);
            font-weight:400;
            margin-right:8px;
        }
        .details-table .salary-increase-icon {
            color:var(--success);
            margin-left:4px;
        }

        /* ===== TERMS ===== */
        .terms-section {
            margin:22px 0 18px;
        }
        .terms-section h4 {
            font-family:'Fraunces',serif;
            font-size:15px;
            color:var(--navy-800);
            border-bottom:2px solid var(--gold-500);
            padding-bottom:8px;
            margin-bottom:14px;
        }
        .terms-section ol {
            padding-left:24px;
            columns:2;
            column-gap:30px;
        }
        .terms-section li {
            font-size:12.5px;
            line-height:1.9;
            margin-bottom:4px;
            break-inside:avoid;
        }
        @media(max-width:600px) {
            .terms-section ol {
                columns:1;
            }
        }

        /* ===== COMPLIANCE CHECK SECTION ===== */
        .compliance-check-section {
            margin:22px 0 18px;
            padding:18px 22px;
            background:#fdf8f0;
            border:1px solid #e0d5b0;
            border-radius:var(--radius-sm);
        }
        .compliance-check-section h4 {
            font-family:'Fraunces',serif;
            font-size:14px;
            color:var(--navy-800);
            margin-bottom:10px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .compliance-check-section h4 i {
            color:var(--gold-500);
        }
        .compliance-progress {
            font-size:11px;
            color:var(--ink-soft);
            margin-bottom:10px;
            font-weight:500;
        }
        .compliance-check-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:4px 20px;
        }
        @media(max-width:600px) {
            .compliance-check-grid {
                grid-template-columns:1fr;
            }
        }
        .compliance-check-item {
            display:flex;
            align-items:center;
            gap:10px;
            padding:5px 0;
            font-size:12px;
            cursor:pointer;
            transition:color .2s;
        }
        .compliance-check-item:hover {
            color:var(--navy-800);
        }
        .compliance-check-item input[type="checkbox"] {
            width:17px;
            height:17px;
            accent-color:var(--gold-500);
            cursor:pointer;
            flex-shrink:0;
        }
        .compliance-check-item .policy-num {
            display:inline-block;
            background:var(--gold-100);
            color:var(--navy-800);
            font-weight:700;
            font-size:10px;
            padding:0 7px;
            border-radius:4px;
            min-width:22px;
            text-align:center;
        }

        /* ===== SIGNATURE SECTION ===== */
        .sign-section {
            display:flex;
            gap:30px;
            margin-top:24px;
            padding-top:20px;
            border-top:2px solid var(--line);
            flex-wrap:wrap;
            align-items:flex-end;
        }
        .sign-box {
            flex:1;
            min-width:200px;
            text-align:center;
        }
        .sign-box h5 {
            font-size:12px;
            color:var(--ink-soft);
            margin-bottom:10px;
            text-transform:uppercase;
            letter-spacing:1.2px;
            font-weight:600;
        }
        .sign-pad {
            border:2px dashed var(--line);
            border-radius:10px;
            background:#fff;
            cursor:crosshair;
            width:100%;
            height:140px;
            margin-bottom:8px;
            transition:border-color .3s,box-shadow .3s;
        }
        .sign-pad:hover {
            border-color:var(--gold-500);
            box-shadow:0 0 0 4px rgba(201,150,47,.08);
        }
        .sign-pad canvas {
            width:100%;
            height:100%;
            border-radius:10px;
        }
        .sign-stamp {
            max-width:130px;
            opacity:.85;
        }
        .sign-clear-btn {
            font-size:11px;
            padding:5px 16px;
            border:none;
            background:var(--cream);
            border-radius:20px;
            cursor:pointer;
            color:var(--ink-soft);
            transition:all .2s;
        }
        .sign-clear-btn:hover {
            background:var(--line);
            color:var(--ink);
        }

        /* ===== CHECKBOX LIST ===== */
        .checkbox-list {
            margin:20px 0;
        }
        .checkbox-item {
            display:flex;
            align-items:flex-start;
            gap:14px;
            padding:14px 20px;
            border:1px solid var(--line);
            border-radius:var(--radius-sm);
            margin-bottom:10px;
            cursor:pointer;
            transition:border-color .3s,background .3s;
            background:var(--cream);
        }
        .checkbox-item:hover {
            border-color:var(--gold-500);
            background:#fcf9f2;
        }
        .checkbox-item input[type="checkbox"] {
            width:20px;
            height:20px;
            margin-top:2px;
            accent-color:var(--gold-500);
            flex-shrink:0;
            cursor:pointer;
        }
        .checkbox-item strong {
            color:var(--navy-800);
        }

        /* ===== BUTTONS ===== */
        .btn-row {
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            justify-content:center;
            margin-top:20px;
        }
        .btn {
            padding:12px 32px;
            border:none;
            border-radius:30px;
            font-weight:700;
            font-size:13px;
            cursor:pointer;
            font-family:'Inter',sans-serif;
            display:inline-flex;
            align-items:center;
            gap:10px;
            transition:all .25s;
            text-decoration:none;
        }
        .btn:hover:not(:disabled) {
            transform:translateY(-2px);
            box-shadow:var(--shadow-md);
        }
        .btn-gold {
            background:linear-gradient(135deg,var(--gold-500),var(--gold-400));
            color:var(--navy-950);
        }
        .btn-gold:hover:not(:disabled) {
            background:linear-gradient(135deg,#d4a33a,#e0b85a);
        }
        .btn-primary {
            background:linear-gradient(135deg,var(--navy-800),var(--navy-950));
            color:#fff;
        }
        .btn-primary:hover:not(:disabled) {
            background:linear-gradient(135deg,#1a3d7a,#0c1a38);
        }
        .btn-pdf {
            background:linear-gradient(135deg,#e74c3c,#c0392b);
            color:#fff;
        }
        .btn-pdf:hover:not(:disabled) {
            background:linear-gradient(135deg,#f05a4a,#d04434);
        }
        .btn-outline {
            background:var(--paper);
            color:var(--ink-soft);
            border:2px solid var(--line);
        }
        .btn-outline:hover:not(:disabled) {
            border-color:var(--gold-500);
            color:var(--ink);
        }
        .btn:disabled {
            opacity:.5;
            cursor:not-allowed;
            transform:none!important;
        }

        /* ===== DISCLAIMER ===== */
        .disclaimer-box {
            margin-top:20px;
            padding:14px 20px;
            background:#fffdf8;
            border:1px solid #e0d5b0;
            border-radius:var(--radius-sm);
            font-size:11px;
            color:var(--ink-soft);
            text-align:center;
            font-style:italic;
            line-height:1.7;
        }
        .disclaimer-box strong {
            color:var(--warn);
            font-style:normal;
        }

        /* ===== FOOTER ===== */
        .copyright-footer {
            text-align:center;
            padding:20px;
            margin-top:30px;
            border-top:1px solid var(--line);
            font-size:12px;
            color:var(--ink-soft);
        }
        .copyright-footer a {
            color:var(--gold-500);
            text-decoration:none;
            font-weight:600;
        }
        .copyright-footer a:hover {
            text-decoration:underline;
        }

        /* ===== RESPONSIVE ===== */
        @media(max-width:768px) {
            .header {
                padding:10px 16px;
            }
            .header-brand {
                font-size:14px;
            }
            .header-nav a {
                padding:4px 12px;
                font-size:11px;
            }
            .letter-card {
                padding:24px 16px;
            }
            .letter-header .logo-img {
                height:40px;
            }
            .letter-header .brand-text {
                font-size:16px;
            }
            .sign-section {
                flex-direction:column;
            }
            .details-table td:first-child {
                width:100px;
                font-size:10px;
            }
            .content {
                padding:12px 12px 30px;
            }
            .status-card {
                padding:30px 20px;
            }
            .compliance-check-grid {
                grid-template-columns:1fr;
            }
        }
        @media(max-width:480px) {
            .header-nav a span {
                display:none;
            }
            .letter-card {
                padding:16px 12px;
            }
            .details-table td {
                padding:6px 10px;
                font-size:12px;
            }
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            .header,
            .header-nav,
            .btn-row,
            .checkbox-list,
            .alert,
            .copyright-footer,
            .compliance-badge,
            .status-card .btn,
            .sign-clear-btn,
            #signForm .btn-row {
                display:none!important;
            }
            .content {
                padding:0;
                margin:0;
                max-width:100%;
            }
            .letter-card {
                box-shadow:none;
                border:1px solid #ccc;
                border-radius:0;
                padding:30px;
            }
            .letter-card::before {
                display:none;
            }
            body {
                background:#fff;
            }
            .compliance-check-section {
                background:#f9f6ed!important;
                -webkit-print-color-adjust:exact;
                print-color-adjust:exact;
            }
            .photo-row img {
                -webkit-print-color-adjust:exact;
                print-color-adjust:exact;
            }
            .sign-pad {
                border-color:#999;
            }
            .salary-revision-notice,
            .admin-comment-box,
            .performance-remarks-box {
                -webkit-print-color-adjust:exact;
                print-color-adjust:exact;
            }
        }
    </style>
</head>
<body>
    <!-- ===== HEADER ===== -->
    <header class="header">
        <div class="header-left">
            <div class="header-logo">
                <img src="/bettergrowthsolutions.jpg" alt="Better Growth Solutions Logo">
            </div>
        </div>
        <nav class="header-nav">
            <a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a>
            <a href="compliance.php"><i class="fas fa-file-signature"></i> <span>Compliance</span></a>
            <a href="joining-letter.php" class="active"><i class="fas fa-file-contract"></i> <span>Joining Letter</span></a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
        </nav>
    </header>

    <!-- ===== CONTENT ===== -->
    <div class="content">

        <?php if ($signedSuccess): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <div><strong>Success!</strong> Your joining letter has been signed and submitted. Awaiting admin approval.</div>
        </div>
        <?php endif; ?>
        <?php if (isset($errorMsg)): ?>
        <div class="alert alert-error">
            <span class="alert-icon">❌</span>
            <div><?php echo $errorMsg; ?></div>
        </div>
        <?php endif; ?>

        <!-- Compliance Status -->
        <div style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
            <span class="compliance-badge <?php echo $complianceApproved ? 'done' : 'pending'; ?>">
                <i class="fas <?php echo $complianceApproved ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                Compliance: <?php echo $complianceApproved ? 'Approved ✓' : 'Signed (Pending Approval)'; ?>
            </span>
            <?php if ($letterExists && $isApproved): ?>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-pdf" style="padding:8px 20px;font-size:12px;" onclick="window.open('j1.php','_blank')">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button class="btn btn-outline" style="padding:8px 20px;font-size:12px;" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$letterExists): ?>
        <!-- ===== NO LETTER ===== -->
        <div class="status-card">
            <span class="status-icon">📄</span>
            <h2>No Joining Letter Yet</h2>
            <p style="color:var(--ink-soft);margin-bottom:16px;">Your joining letter has not been created by the admin yet. Please check back later.</p>
            <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Dashboard</a>
        </div>

        <?php elseif ($isApproved): ?>
        <!-- ===== APPROVED ===== -->
        <div class="status-card">
            <span class="status-icon">✅</span>
            <h2>Joining Letter Approved!</h2>
            <div class="status-badge approved"><i class="fas fa-check-circle"></i> Approved</div>

            <p style="color:var(--ink-soft);">Approved by: <strong><?php echo htmlspecialchars($letter['approved_by'] ?? 'Admin'); ?></strong></p>
            <p style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Signed on: <?php echo date('d M Y, h:i A', strtotime($letter['agreed_at'] ?? 'now')); ?></p>
            
            <div style="margin-top:16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a href="j1.php" target="_blank" class="btn btn-pdf" style="padding:10px 30px;font-size:14px;">
                    <i class="fas fa-file-pdf"></i> Download PDF (A4)
                </a>
                <button class="btn btn-outline" style="padding:10px 30px;font-size:14px;" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <div class="letter-card">
            <?php showLetter($letter, $photoUrl, $stampPath, $complianceData, $displaySalary, $salaryRevised, $revisedSalary, $revisionReason, $revisionDate, $adminComment, $performanceRemarks); ?>
        </div>

        <?php elseif ($isSigned && $isPending): ?>
        <!-- ===== SIGNED - PENDING ===== -->
        <div class="status-card">
            <span class="status-icon">⏳</span>
            <h2>Awaiting Approval</h2>
            <div class="status-badge pending"><i class="fas fa-clock"></i> Pending</div>
            <p style="color:var(--ink-soft);">Signed: <strong><?php echo date('d M Y, h:i A', strtotime($letter['agreed_at'] ?? 'now')); ?></strong></p>
            <p style="font-size:12px;color:var(--ink-soft);">Admin will review and approve your joining letter shortly.</p>
            <a href="dashboard.php" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-home"></i> Dashboard</a>
        </div>
        <div class="letter-card">
            <?php showLetter($letter, $photoUrl, $stampPath, $complianceData, $displaySalary, $salaryRevised, $revisedSalary, $revisionReason, $revisionDate, $adminComment, $performanceRemarks); ?>
        </div>

        <?php elseif ($canSign): ?>
        <!-- ===== CAN SIGN ===== -->
        <div class="alert alert-warning">
            <span class="alert-icon">⚠️</span>
            <div><strong>Action Required:</strong> Please read the joining letter carefully, check all compliance boxes, draw your signature, and submit.</div>
        </div>

        <div class="letter-card">
            <?php showLetter($letter, $photoUrl, $stampPath, $complianceData, $displaySalary, $salaryRevised, $revisedSalary, $revisionReason, $revisionDate, $adminComment, $performanceRemarks); ?>

            <!-- COMPLIANCE CHECKBOXES -->
            <div class="compliance-check-section">
                <h4><i class="fas fa-clipboard-check"></i> Compliance Policy Acknowledgment</h4>
                <p class="compliance-progress">
                    <span id="joinCheckedCount">0</span> / 9 policies acknowledged
                    <span style="display:inline-block;width:120px;height:4px;background:#e0d5b0;border-radius:4px;margin-left:10px;vertical-align:middle;">
                        <span id="joinProgressBar" style="display:block;height:100%;background:var(--gold-500);border-radius:4px;width:0%;transition:width .3s;"></span>
                    </span>
                </p>
                <div class="compliance-check-grid">
                    <?php
                    $policies = [
                        1 => 'Working Hours & Attendance',
                        2 => 'Technical Requirements',
                        3 => 'Leave & Holiday Policy',
                        4 => 'Salary & Compensation',
                        5 => 'Monitoring & Screen Sharing',
                        6 => 'Data Protection & Privacy',
                        7 => 'Professional Conduct',
                        8 => 'Confidentiality',
                        9 => 'Disciplinary Action',
                    ];
                    foreach($policies as $num => $title): ?>
                    <label class="compliance-check-item">
                        <input type="checkbox" name="join_policy_<?php echo $num; ?>" value="1" onchange="updateJoinProgress()">
                        <span><span class="policy-num"><?php echo $num; ?></span> I agree to <strong><?php echo $title; ?></strong></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SIGNATURE -->
            <div class="sign-section">
                <div class="sign-box">
                    <h5><i class="fas fa-pen-fancy"></i> Your Digital Signature</h5>
                    <div class="sign-pad">
                        <canvas id="signatureCanvas"></canvas>
                    </div>
                    <button type="button" class="sign-clear-btn" onclick="clearSignature()">
                        <i class="fas fa-eraser"></i> Clear
                    </button>
                </div>
                <div class="sign-box">
                    <h5><i class="fas fa-stamp"></i> Company Authority</h5>
                    <?php if(!empty($stampPath)): ?>
                        <img src="<?php echo $stampPath; ?>" class="sign-stamp" alt="Company Stamp">
                    <?php else: ?>
                        <div style="padding:30px;border:1px dashed #ccc;border-radius:10px;font-size:11px;color:var(--ink-soft);">
                            <i class="fas fa-stamp" style="font-size:24px;display:block;margin-bottom:8px;color:var(--gold-500);"></i>
                            Company Stamp
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SIGN FORM -->
            <form method="POST" id="signForm">
                <input type="hidden" name="signature_data" id="signatureData">
                <input type="hidden" name="acknowledged" id="ackField" value="0">
                <input type="hidden" name="terms_agreed" id="termsField" value="0">
                <?php foreach($policies as $num => $title): ?>
                <input type="hidden" name="join_policy_<?php echo $num; ?>" id="joinHiddenPolicy<?php echo $num; ?>" value="0">
                <?php endforeach; ?>

                <div class="checkbox-list">
                    <label class="checkbox-item">
                        <input type="checkbox" id="ackCheck" onchange="document.getElementById('ackField').value=this.checked?'1':'0'">
                        <div>
                            <strong>I have read and understood</strong> all terms and conditions in this joining letter.
                            <br><small style="color:var(--ink-soft);">This includes salary, work hours, leave policy, and all other provisions.</small>
                        </div>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="termsCheck" onchange="document.getElementById('termsField').value=this.checked?'1':'0'">
                        <div>
                            <strong>I agree to comply</strong> with all company rules, regulations, and policies.
                            <br><small style="color:var(--ink-soft);">This includes code of conduct, confidentiality, and data protection.</small>
                        </div>
                    </label>
                </div>

                <div class="disclaimer-box">
                    <strong>⚠️ Important:</strong> You can only sign <strong>ONCE</strong>. After admin approval, you can download the PDF copy.
                    Your signature is legally binding and confirms your acceptance of this appointment.
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-gold" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Sign &amp; Submit Joining Letter
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== FOOTER ===== -->
    <div class="copyright-footer">
        <i class="fas fa-copyright" style="color:var(--gold-500);"></i>
        <?php echo date('Y'); ?> <a href="https://rajvardhansingh.in">Rajvardhan Singh</a> &amp; Family.
        All Rights Reserved. | Better Growth Solutions
    </div>

    <script>
        // ===== Join Page Compliance Progress =====
        function updateJoinProgress() {
            let checked = 0;
            for (let i = 1; i <= 9; i++) {
                const cb = document.querySelector('input[name="join_policy_' + i + '"]');
                const hidden = document.getElementById('joinHiddenPolicy' + i);
                if (cb && cb.checked) {
                    checked++;
                    if(hidden) hidden.value = '1';
                } else {
                    if(hidden) hidden.value = '0';
                }
            }
            const countEl = document.getElementById('joinCheckedCount');
            const barEl = document.getElementById('joinProgressBar');
            if(countEl) countEl.textContent = checked;
            if(barEl) barEl.style.width = (checked/9*100) + '%';
        }

        // ===== Signature Pad =====
        const canvas = document.getElementById('signatureCanvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            let drawing = false;
            
            function resizeCanvas() {
                const pad = canvas.parentElement;
                const rect = pad.getBoundingClientRect();
                canvas.width = rect.width || 300;
                canvas.height = rect.height || 140;
                ctx.strokeStyle = '#1c2536';
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            function getPos(e) {
                const rect = canvas.getBoundingClientRect();
                const cx = e.touches ? e.touches[0].clientX : e.clientX;
                const cy = e.touches ? e.touches[0].clientY : e.clientY;
                return {
                    x: (cx - rect.left) * (canvas.width / rect.width),
                    y: (cy - rect.top) * (canvas.height / rect.height)
                };
            }

            function start(e) {
                e.preventDefault();
                drawing = true;
                const p = getPos(e);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
            }

            function move(e) {
                if (!drawing) return;
                e.preventDefault();
                const p = getPos(e);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
            }

            function stop() {
                if (drawing) {
                    drawing = false;
                    document.getElementById('signatureData').value = canvas.toDataURL();
                }
            }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', stop);
            canvas.addEventListener('mouseleave', stop);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', stop);
        }

        function clearSignature() {
            const c = document.getElementById('signatureCanvas');
            if(c) {
                const ctx = c.getContext('2d');
                ctx.clearRect(0, 0, c.width, c.height);
                document.getElementById('signatureData').value = '';
            }
        }

        // ===== Form Validation =====
        document.getElementById('signForm')?.addEventListener('submit', function(e) {
            const sig = document.getElementById('signatureData').value;
            const ack = document.getElementById('ackCheck').checked;
            const terms = document.getElementById('termsCheck').checked;
            
            let allChecked = true;
            for (let i = 1; i <= 9; i++) {
                const cb = document.querySelector('input[name="join_policy_' + i + '"]');
                if (!cb || !cb.checked) { allChecked = false; break; }
            }

            const errors = [];
            if (!sig || sig === 'data:,') errors.push('• Draw your signature in the signature pad.');
            if (!ack) errors.push('• Acknowledge that you have read all terms.');
            if (!terms) errors.push('• Agree to comply with company policies.');
            if (!allChecked) errors.push('• Check ALL 9 compliance policy sections.');

            if (errors.length > 0) {
                e.preventDefault();
                alert('⚠️ Please complete the following:\n\n' + errors.join('\n'));
                return;
            }

            if (!confirm('⚠️ This is a ONE-TIME signature. You cannot sign again after submission.\n\nAre you sure you want to submit your joining letter?')) {
                e.preventDefault();
                return;
            }

            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
    </script>
</body>
</html>

<?php
// ============================================================
// LETTER CONTENT FUNCTION with Salary Revision Support
// ============================================================
function showLetter($letter, $photoUrl, $stampPath, $complianceData, $displaySalary, $salaryRevised, $revisedSalary, $revisionReason, $revisionDate, $adminComment, $performanceRemarks) {
    $d = $letter;
    $complianceId = $complianceData['id'] ?? 'N/A';
    $originalSalary = $d['salary'] ?? 13000;
?>
    <!-- ===== LETTER HEADER ===== -->
    <div class="letter-header">
        <div class="logo-section">
            <div class="logo-img">
                <img src="/bettergrowthsolutions.jpg" alt="Better Growth Solutions">
            </div>
        </div>
        <div class="company-info">
            <div class="ref-no">Ref: BGS/JL/<?php echo date('Y'); ?>/<?php echo htmlspecialchars($d['agent_id']); ?></div>
            <p>Cosmos City, Bicholi Mardana, Indore – 452016</p>
<p>🌐 <a href="https://bettergrowthsolutions.com/" style="color: black; text-decoration: none;">bettergrowthsolutions.com</a></p>
            <p>📧 info@bettergrowthsolutions.com</p>
            <p>📞 +91 92443 30496</p>
        </div>
    </div>

    <div class="date-line">Date: <?php echo date('d M Y', strtotime($d['joining_date'] ?? 'now')); ?></div>

    <div class="subject">SUBJECT: APPOINTMENT LETTER</div>

    <div class="salutation">
        <p>Dear <strong><?php echo htmlspecialchars($d['full_name']); ?></strong>,</p>
        <p>We are pleased to appoint you as <strong><?php echo htmlspecialchars($d['designation'] ?? 'Sales Executive'); ?></strong> 
        in our <strong><?php echo htmlspecialchars($d['department'] ?? 'Sales'); ?></strong> department. 
        We believe your skills and experience will be a valuable asset to our team.</p>
    </div>

    <?php if(!empty($photoUrl)): ?>
    <div class="photo-row">
        <img src="<?php echo $photoUrl; ?>" alt="Candidate Photo">
    </div>
    <?php endif; ?>

    <!-- ===== ADMIN COMMENT (if exists) ===== -->
    <?php if(!empty($adminComment)): ?>
    <div class="admin-comment-box">
        <div class="comment-title">
            <i class="fas fa-comment-dots"></i> Admin Note
        </div>
        <div class="comment-text">
            <i class="fas fa-quote-left"></i> <?php echo nl2br(htmlspecialchars($adminComment)); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== PERFORMANCE REMARKS (if exists) ===== -->
    <?php if(!empty($performanceRemarks)): ?>
    <div class="performance-remarks-box">
        <div class="remarks-title">
            <i class="fas fa-chart-line"></i> Performance Remarks
        </div>
        <div class="remarks-text">
            <i class="fas fa-star" style="color:#ffc107;"></i> <?php echo nl2br(htmlspecialchars($performanceRemarks)); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== SALARY REVISION NOTICE ===== -->
    <?php if($salaryRevised && $revisedSalary > 0): ?>
    <div class="salary-revision-notice">
        <div class="revision-title">
            <i class="fas fa-arrow-up"></i> Salary Revised!
        </div>
        <div class="revision-details">
            <strong>New Salary:</strong> ₹<?php echo number_format($revisedSalary, 0); ?>/month 
            <span style="color:var(--success);font-weight:600;">↑ ₹<?php echo number_format($revisedSalary - $originalSalary, 0); ?> increase</span>
            <br>
            <strong>Previous:</strong> ₹<?php echo number_format($originalSalary, 0); ?>/month
            <?php if(!empty($revisionDate)): ?>
            <br><strong>Revised on:</strong> <?php echo date('d M Y', strtotime($revisionDate)); ?>
            <?php endif; ?>
        </div>
        <?php if(!empty($revisionReason)): ?>
        <div class="revision-reason">
            <i class="fas fa-info-circle"></i> <strong>Reason:</strong> <?php echo htmlspecialchars($revisionReason); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ===== DETAILS TABLE ===== -->
    <table class="details-table">
        <tr><td>Employee Name</td><td><strong><?php echo htmlspecialchars($d['full_name']); ?></strong></td></tr>
        <tr><td>Agent ID</td><td><?php echo htmlspecialchars($d['agent_id']); ?></td></tr>
        <tr><td>Email</td><td><?php echo htmlspecialchars($d['email'] ?? ''); ?></td></tr>
        <tr><td>Phone</td><td><?php echo htmlspecialchars($d['phone'] ?? ''); ?></td></tr>
        <tr><td>Date of Joining</td><td><strong><?php echo date('d M Y', strtotime($d['joining_date'] ?? 'now')); ?></strong></td></tr>
        <tr><td>Designation</td><td><?php echo htmlspecialchars($d['designation'] ?? 'Sales Executive'); ?></td></tr>
        <tr><td>Department</td><td><?php echo htmlspecialchars($d['department'] ?? 'Sales'); ?></td></tr>
        <tr>
            <td>Starting Salary</td>
            <td>
                <?php if($salaryRevised && $revisedSalary > 0): ?>
                    <span class="original-salary">₹<?php echo number_format($originalSalary, 0); ?>/month</span>
                    <strong class="revised-salary">₹<?php echo number_format($revisedSalary, 0); ?>/month</strong>
                    <span class="salary-increase-icon">⬆</span>
                    <span style="font-size:11px;color:var(--success);font-weight:600;">
                        (₹<?php echo number_format($revisedSalary - $originalSalary, 0); ?> increase)
                    </span>
                <?php else: ?>
                    <strong>₹<?php echo number_format($displaySalary, 0); ?>/month</strong> (in-hand)
                <?php endif; ?>
            </td>
        </tr>
        <tr><td>Shift Timing</td><td><?php echo ($d['shift_type'] ?? 'night') === 'night' ? '🕒 7:30 PM – 4:30 AM IST' : '🕒 Day Shift'; ?></td></tr>
        <tr><td>Work Location</td><td><?php echo ($d['work_location'] ?? 'home') === 'home' ? '🏠 Work From Home' : '🏢 Office'; ?></td></tr>
        <tr><td>Probation Period</td><td>3 Months (extendable based on performance)</td></tr>
    </table>

    <!-- ===== TERMS ===== -->
    <div class="terms-section">
        <h4>📋 Terms &amp; Conditions</h4>
        <ol>
            <li>Employment is subject to company rules, regulations, and code of conduct.</li>
            <li>Salary will be credited on <strong>10th of every month</strong>.</li>
            <li>Strict confidentiality of company and customer data is required at all times.</li>
            <li>Working hours: <strong>9 hours/day</strong>, Monday–Friday.</li>
            <li>Must adhere to the compliance policy signed separately.</li>
            <li>Notice period: <strong>30 days</strong> for termination by either party.</li>
            <li>Performance reviews conducted periodically; increments are performance-based.</li>
            <li>Leave policy as per company HR guidelines.</li>
        </ol>
        <p style="margin-top:10px;font-size:13px;color:var(--ink-soft);">
            We look forward to a long and mutually beneficial association with you.
        </p>
        <p style="margin-top:8px;">Yours sincerely,<br><strong>For Better Growth Solutions</strong></p>
    </div>

    <!-- ===== SIGNATURE SECTION ===== -->
    <div class="sign-section">
        <div class="sign-box">
            <?php if(!empty($d['signature_data'])): ?>
                <img src="<?php echo $d['signature_data']; ?>" style="max-width:200px;max-height:80px;" alt="Employee Signature">
            <?php else: ?>
                <div style="border-bottom:2px solid #1c2536;width:200px;margin:30px auto 4px;"></div>
            <?php endif; ?>
            <p style="font-size:12px;margin-top:4px;">
                <strong><?php echo htmlspecialchars($d['full_name']); ?></strong><br>
                <span style="color:var(--ink-soft);font-size:11px;">Employee</span>
            </p>
        </div>
        <div class="sign-box">
            <?php if(!empty($stampPath)): ?>
                <img src="<?php echo $stampPath; ?>" class="sign-stamp" alt="Company Stamp">
            <?php endif; ?>
            <div style="border-bottom:2px solid #1c2536;width:200px;margin:30px auto 4px;"></div>
            <p style="font-size:12px;margin-top:4px;">
                <strong>Authorized Signatory</strong><br>
                <span style="color:var(--ink-soft);font-size:11px;">Better Growth Solutions</span>
            </p>
        </div>
    </div>

    <!-- ===== DISCLAIMER ===== -->
    <div class="disclaimer-box">
        <strong>Disclaimer:</strong> Neither BGS nor Rajvardhan Singh is responsible for any inadvertent error in this letter.<br>
        <strong>This document is electronically generated and is valid without a physical signature.</strong><br>
        BetterGrowth Solutions &copy; <?php echo date('Y'); ?> | Compliance Agreement #<?php echo $complianceId; ?>
        <?php if($salaryRevised && $revisedSalary > 0): ?>
        | <span style="color:var(--success);font-weight:600;">Salary Revised ✓</span>
        <?php endif; ?>
    </div>
<?php } ?>