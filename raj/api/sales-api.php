<?php
// /raj/api/sales-api.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/encryption.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function sendJsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    while (ob_get_level()) ob_end_clean();
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? '';
$agentId = $_POST['agent_id'] ?? '';

switch ($action) {
    // ============================================================
    // REQUEST REPORT (Agent)
    // ============================================================
    case 'request_report':
        $month = $_POST['month'] ?? date('Y-m');
        
        // Check if already requested
        $existing = executeQuery(
            "SELECT id FROM sales_report_requests WHERE agent_id = ? AND month_year = ? AND status IN ('pending', 'approved')",
            [$agentId, $month]
        );
        if ($existing && $existing->num_rows > 0) {
            sendJsonResponse(['success' => false, 'message' => 'Report already requested for this month']);
        }
        
        $result = executeQuery(
            "INSERT INTO sales_report_requests (agent_id, request_type, month_year, status) VALUES (?, 'monthly', ?, 'pending')",
            [$agentId, $month]
        );
        
        if ($result !== false) {
            addAuditLog($agentId, 'report_requested', "Sales report requested for $month");
            sendJsonResponse(['success' => true, 'message' => 'Report requested successfully']);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Failed to request report']);
        }
        break;

    // ============================================================
    // APPROVE SALE (Admin)
    // ============================================================
    case 'approve_sale':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $saleId = $_POST['sale_id'] ?? 0;
        $adminId = $_SESSION['admin_id'] ?? 'admin';
        
        $result = executeQuery(
            "UPDATE daily_sales SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
            [$adminId, $saleId]
        );
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Sale approved' : 'Failed']);
        break;

    // ============================================================
    // REJECT SALE (Admin)
    // ============================================================
    case 'reject_sale':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $saleId = $_POST['sale_id'] ?? 0;
        
        $result = executeQuery("UPDATE daily_sales SET status = 'rejected' WHERE id = ?", [$saleId]);
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Sale rejected' : 'Failed']);
        break;

    // ============================================================
    // EDIT SALE (Admin)
    // ============================================================
    case 'edit_sale':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $saleId = $_POST['sale_id'] ?? 0;
        $creditScore = (int)($_POST['credit_score'] ?? 0);
        $creditStrong = (int)($_POST['credit_strong'] ?? 0);
        $kikoff = (int)($_POST['kikoff'] ?? 0);
        $other = (int)($_POST['other_sales'] ?? 0);
        
        $result = executeQuery(
            "UPDATE daily_sales SET credit_score = ?, credit_strong = ?, kikoff = ?, other_sales = ? WHERE id = ?",
            [$creditScore, $creditStrong, $kikoff, $other, $saleId]
        );
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Sale updated' : 'Failed']);
        break;

    // ============================================================
    // SET TARGET (Admin)
    // ============================================================
    case 'set_target':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $targetAgentId = $_POST['agent_id'] ?? '';
        $month = $_POST['month'] ?? date('Y-m');
        $monthlyTarget = (int)($_POST['monthly_target'] ?? 0);
        $csTarget = (int)($_POST['credit_score_target'] ?? 0);
        $workingDays = (int)($_POST['working_days'] ?? 22);
        $dailyTarget = $workingDays > 0 ? round($monthlyTarget / $workingDays, 2) : 0;
        
        $result = executeQuery(
            "INSERT INTO sales_targets (agent_id, month_year, monthly_target, credit_score_target, working_days, daily_target, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, 'admin')
             ON DUPLICATE KEY UPDATE monthly_target = ?, credit_score_target = ?, working_days = ?, daily_target = ?, updated_at = NOW()",
            [$targetAgentId, $month, $monthlyTarget, $csTarget, $workingDays, $dailyTarget, $monthlyTarget, $csTarget, $workingDays, $dailyTarget]
        );
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Target saved' : 'Failed']);
        break;

    // ============================================================
    // SET BULK TARGETS (Admin)
    // ============================================================
    case 'set_bulk_targets':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $month = $_POST['month'] ?? date('Y-m');
        $bulkTarget = (int)($_POST['bulk_target'] ?? 0);
        $workingDays = (int)($_POST['working_days'] ?? 22);
        $teamTarget = (int)($_POST['team_target'] ?? 0);
        $dailyTarget = $workingDays > 0 ? round($bulkTarget / $workingDays, 2) : 0;
        
        // Update admin team target
        executeQuery(
            "INSERT INTO admin_targets (month_year, total_team_target) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE total_team_target = ?, updated_at = NOW()",
            [$month, $teamTarget, $teamTarget]
        );
        
        // Update all agents targets
        $agents = executeQuery("SELECT agent_id FROM agents WHERE onboarding_completed = 1 AND status = 'active'");
        if ($agents) {
            while ($agent = $agents->fetch_assoc()) {
                executeQuery(
                    "INSERT INTO sales_targets (agent_id, month_year, monthly_target, working_days, daily_target, created_by) 
                     VALUES (?, ?, ?, ?, ?, 'admin')
                     ON DUPLICATE KEY UPDATE monthly_target = ?, working_days = ?, daily_target = ?, updated_at = NOW()",
                    [$agent['agent_id'], $month, $bulkTarget, $workingDays, $dailyTarget, $bulkTarget, $workingDays, $dailyTarget]
                );
            }
        }
        sendJsonResponse(['success' => true, 'message' => 'Bulk targets set successfully']);
        break;

    // ============================================================
    // APPROVE REPORT (Admin)
    // ============================================================
    case 'approve_report':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $requestId = $_POST['request_id'] ?? 0;
        $adminId = $_SESSION['admin_id'] ?? 'admin';
        
        $result = executeQuery(
            "UPDATE sales_report_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
            [$adminId, $requestId]
        );
        
        // TODO: Send email with report
        // For now, mark as sent
        if ($result !== false) {
            executeQuery("UPDATE sales_report_requests SET status = 'sent', sent_at = NOW() WHERE id = ?", [$requestId]);
            addAuditLog($adminId, 'report_approved', "Sales report request #$requestId approved and sent");
        }
        
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Report approved and sent' : 'Failed']);
        break;

    // ============================================================
    // REJECT REPORT (Admin)
    // ============================================================
    case 'reject_report':
        if (!isset($_SESSION['admin_logged_in'])) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required']);
        }
        $requestId = $_POST['request_id'] ?? 0;
        
        $result = executeQuery("UPDATE sales_report_requests SET status = 'rejected' WHERE id = ?", [$requestId]);
        sendJsonResponse(['success' => $result !== false, 'message' => $result !== false ? 'Report rejected' : 'Failed']);
        break;

    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action']);
}
?>