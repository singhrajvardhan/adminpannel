<?php
// /raj/api/attendance.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
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
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($data);
    exit;
}

/**
 * Get the current date according to US shift
 */
function getUsaShiftDate($zone = 'America/New_York') {
    $usaTime = new DateTime('now', new DateTimeZone($zone));
    return $usaTime->format('Y-m-d');
}

// Check if agent is logged in
if (!isset($_SESSION['agent_logged_in']) || $_SESSION['agent_logged_in'] !== true) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized. Please login again.']);
}

$agentId = $_POST['agent_id'] ?? $_SESSION['agent_id'] ?? '';
if (empty($agentId)) {
    sendJsonResponse(['success' => false, 'message' => 'Agent ID required']);
}

$action = $_POST['action'] ?? '';
$type = $_POST['type'] ?? '';
$comment = trim($_POST['comment'] ?? '');

// USE US SHIFT DATE INSTEAD OF INDIAN DATE
$today = getUsaShiftDate();

// Get location data from POST (for start_job)
$latitude = $_POST['latitude'] ?? '';
$longitude = $_POST['longitude'] ?? '';
$accuracy = $_POST['accuracy'] ?? '';

// Get or create attendance record
$attendanceResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
if ($attendanceResult && $attendanceResult->num_rows > 0) {
    $attendance = $attendanceResult->fetch_assoc();
} else {
    executeQuery("INSERT INTO attendance (agent_id, date, status) VALUES (?, ?, 'not_started')", [$agentId, $today]);
    $attendanceResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
    if ($attendanceResult) {
        $attendance = $attendanceResult->fetch_assoc();
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to create attendance record']);
    }
}

$breakDurations = ['lunch' => 30*60, 'snack' => 15*60, 'bio' => 0];
$maxSnackBreaks = 2;

switch ($action) {
    // ============================================================
    // START JOB - WITH LOCATION CAPTURE
    // ============================================================
    case 'start_job':
        if (empty($attendance['job_start_time'])) {
            $now = date('Y-m-d H:i:s');  // This will be server time (should be set to IST)
            $timeOnly = date('H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            
            // Encrypt location if provided
            $encLat = !empty($latitude) ? encryptData($latitude) : '';
            $encLng = !empty($longitude) ? encryptData($longitude) : '';
            
            $sql = "UPDATE attendance SET 
                job_start_time = ?, 
                login_time = ?,
                location_lat = ?,
                location_lng = ?,
                location_accuracy = ?,
                location_captured_at = ?,
                location_ip = ?,
                status = 'present' 
                WHERE agent_id = ? AND date = ?";
            
            $result = executeQuery($sql, [$now, $timeOnly, $encLat, $encLng, $accuracy, $now, $ip, $agentId, $today]);
            
            if ($result !== false) {
                addAuditLog($agentId, 'job_started', 'Started job with location capture');
                sendJsonResponse([
                    'success' => true, 
                    'message' => 'Job started', 
                    'location_captured' => !empty($latitude) && !empty($longitude),
                    'lat' => $latitude,
                    'lng' => $longitude
                ]);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to start job']);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Already started']);
        }
        break;

    // ============================================================
    // RESUME WORK AFTER LOGOUT
    // ============================================================
    case 'resume_work':
        if (!empty($attendance['logout_time'])) {
            $sql = "UPDATE attendance SET 
                logout_time = NULL,
                job_paused = 0,
                status = 'present' 
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$agentId, $today]);
            
            if ($result !== false) {
                addAuditLog($agentId, 'work_resumed', 'Resumed work after logout');
                sendJsonResponse(['success' => true, 'message' => 'Work resumed successfully']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to resume work']);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'You are not logged out']);
        }
        break;

    // ============================================================
    // RESTART DAY (Clear all data and start fresh)
    // ============================================================
    case 'restart_day':
        if (empty($attendance['job_start_time']) || !empty($attendance['logout_time'])) {
            $sql = "UPDATE attendance SET 
                job_start_time = NULL,
                job_end_time = NULL,
                login_time = NULL,
                logout_time = NULL,
                job_paused = 0,
                job_pause_time = NULL,
                total_pause_seconds = 0,
                lunch_start = NULL,
                lunch_end = NULL,
                snack_start = NULL,
                snack_end = NULL,
                bio_start = NULL,
                bio_end = NULL,
                snack_count = 0,
                break_timer_start = NULL,
                break_timer_end = NULL,
                break_timer_type = NULL,
                break_comment = NULL,
                break_alert_shown = 0,
                break_ended_alert = 0,
                resume_alert_shown = 0,
                bio_count = 0,
                status = 'not_started' 
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$agentId, $today]);
            
            if ($result !== false) {
                addAuditLog($agentId, 'day_restarted', 'Restarted day');
                sendJsonResponse(['success' => true, 'message' => 'Day restarted successfully']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to restart day']);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Cannot restart while working. Please logout first.']);
        }
        break;

    // ============================================================
    // PAUSE JOB
    // ============================================================
    case 'pause_job':
        if (empty($attendance['job_start_time'])) {
            sendJsonResponse(['success' => false, 'message' => 'Job not started yet']);
        } elseif (!empty($attendance['logout_time'])) {
            sendJsonResponse(['success' => false, 'message' => 'Already logged out']);
        } elseif ($attendance['job_paused'] == 1) {
            sendJsonResponse(['success' => false, 'message' => 'Already paused']);
        } else {
            $now = date('Y-m-d H:i:s');
            $sql = "UPDATE attendance SET 
                job_paused = 1,
                job_pause_time = ?,
                status = 'paused'
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$now, $agentId, $today]);
            
            if ($result !== false) {
                addAuditLog($agentId, 'job_paused', 'Job paused');
                sendJsonResponse(['success' => true, 'message' => 'Job paused']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to pause job']);
            }
        }
        break;

    // ============================================================
    // RESUME JOB
    // ============================================================
    case 'resume_job':
        if (empty($attendance['job_start_time'])) {
            sendJsonResponse(['success' => false, 'message' => 'Job not started yet']);
        } elseif (!empty($attendance['logout_time'])) {
            sendJsonResponse(['success' => false, 'message' => 'Already logged out']);
        } elseif ($attendance['job_paused'] != 1) {
            sendJsonResponse(['success' => false, 'message' => 'Job is not paused']);
        } else {
            $pauseTime = strtotime($attendance['job_pause_time']);
            $pauseDuration = time() - $pauseTime;
            $totalPause = ($attendance['total_pause_seconds'] ?? 0) + $pauseDuration;
            
            $sql = "UPDATE attendance SET 
                job_paused = 0,
                job_pause_time = NULL,
                total_pause_seconds = ?,
                status = 'present'
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$totalPause, $agentId, $today]);
            
            if ($result !== false) {
                addAuditLog($agentId, 'job_resumed', 'Job resumed');
                sendJsonResponse(['success' => true, 'message' => 'Job resumed']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to resume job']);
            }
        }
        break;

    // ============================================================
    // START BREAK
    // ============================================================
    case 'start_break':
        if (!empty($attendance['break_timer_start']) && empty($attendance['break_timer_end'])) {
            sendJsonResponse(['success' => false, 'message' => 'Another break in progress']);
        }
        
        if ($type === 'snack') {
            $snackCount = (int)($attendance['snack_count'] ?? 0);
            if ($snackCount >= $maxSnackBreaks) {
                sendJsonResponse(['success' => false, 'message' => 'You have already taken ' . $maxSnackBreaks . ' snack breaks today']);
            }
        }
        
        if ($attendance['job_paused'] == 1) {
            $pauseTime = strtotime($attendance['job_pause_time']);
            $pauseDuration = time() - $pauseTime;
            $totalPause = ($attendance['total_pause_seconds'] ?? 0) + $pauseDuration;
            executeQuery("UPDATE attendance SET 
                job_paused = 0,
                job_pause_time = NULL,
                total_pause_seconds = ?
                WHERE agent_id = ? AND date = ?", 
                [$totalPause, $agentId, $today]);
        }
        
        if ($type === 'bio') {
            $time = date('H:i:s');
            $startTimestamp = time();
            $sql = "UPDATE attendance SET 
                bio_start = ?,
                break_timer_start = FROM_UNIXTIME(?),
                break_timer_type = 'bio',
                break_comment = ?,
                break_alert_shown = 0,
                break_ended_alert = 0,
                resume_alert_shown = 0,
                status = 'on_break'
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$time, $startTimestamp, $comment, $agentId, $today]);
            if ($result !== false) {
                addAuditLog($agentId, 'break_started', 'Bio break started');
                sendJsonResponse(['success' => true, 'message' => 'Bio break started']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to start break']);
            }
        } else {
            $field = $type . '_start';
            if (empty($attendance[$field])) {
                $time = date('H:i:s');
                $startTimestamp = time();
                $sql = "UPDATE attendance SET 
                    $field = ?,
                    break_timer_start = FROM_UNIXTIME(?),
                    break_timer_type = ?,
                    break_comment = ?,
                    break_alert_shown = 0,
                    break_ended_alert = 0,
                    resume_alert_shown = 0,
                    status = 'on_break'
                    WHERE agent_id = ? AND date = ?";
                $result = executeQuery($sql, [$time, $startTimestamp, $type, $comment, $agentId, $today]);
                if ($result !== false) {
                    addAuditLog($agentId, 'break_started', ucfirst($type) . ' break started');
                    sendJsonResponse(['success' => true, 'message' => ucfirst($type) . ' break started']);
                } else {
                    sendJsonResponse(['success' => false, 'message' => 'Failed to start break']);
                }
            } else {
                sendJsonResponse(['success' => false, 'message' => ucfirst($type) . ' break already taken today']);
            }
        }
        break;

    // ============================================================
    // END BREAK
    // ============================================================
    case 'end_break':
        if (empty($attendance['break_timer_start'])) {
            sendJsonResponse(['success' => false, 'message' => 'No active break']);
        }
        $currentType = $attendance['break_timer_type'] ?? 'bio';
        
        if ($currentType === 'bio') {
            $time = date('H:i:s');
            $endTimestamp = time();
            $sql = "UPDATE attendance SET 
                bio_end = ?,
                break_timer_end = FROM_UNIXTIME(?),
                break_timer_type = NULL,
                break_comment = NULL,
                break_alert_shown = 0,
                break_ended_alert = 1,
                resume_alert_shown = 0,
                bio_count = bio_count + 1,
                status = 'present'
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$time, $endTimestamp, $agentId, $today]);
            if ($result !== false) {
                addAuditLog($agentId, 'break_ended', 'Bio break ended');
                sendJsonResponse(['success' => true, 'message' => 'Bio break ended', 'break_ended' => true]);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to end break']);
            }
        } else {
            $field = $currentType . '_end';
            if (empty($attendance[$field])) {
                $time = date('H:i:s');
                $endTimestamp = time();
                $startTimestamp = strtotime($attendance['break_timer_start']);
                $elapsed = $endTimestamp - $startTimestamp;
                $duration = $breakDurations[$currentType] ?? 0;
                
                $sql = "UPDATE attendance SET 
                    $field = ?,
                    break_timer_end = FROM_UNIXTIME(?),
                    break_timer_type = NULL,
                    break_comment = NULL,
                    break_alert_shown = 0,
                    break_ended_alert = 1,
                    resume_alert_shown = 0,
                    status = 'present'";
                if ($currentType === 'snack') {
                    $sql .= ", snack_count = snack_count + 1";
                }
                $sql .= " WHERE agent_id = ? AND date = ?";
                $result = executeQuery($sql, [$time, $endTimestamp, $agentId, $today]);
                if ($result !== false) {
                    $elapsedMinutes = (int)floor($elapsed / 60);
                    $elapsedSeconds = (int)($elapsed % 60);
                    $timeString = sprintf("%02d:%02d", $elapsedMinutes, $elapsedSeconds);
                    addAuditLog($agentId, 'break_ended', ucfirst($currentType) . " break ended (Duration: $timeString)");
                    sendJsonResponse(['success' => true, 'message' => ucfirst($currentType) . ' break ended', 'break_ended' => true]);
                } else {
                    sendJsonResponse(['success' => false, 'message' => 'Failed to end break']);
                }
            } else {
                sendJsonResponse(['success' => false, 'message' => ucfirst($currentType) . ' break already ended']);
            }
        }
        break;

    // ============================================================
    // CHECK BREAK ENDED
    // ============================================================
    case 'check_break_ended':
        $result = executeQuery("SELECT break_ended_alert, resume_alert_shown FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $breakEnded = (int)($row['break_ended_alert'] ?? 0);
            $resumeShown = (int)($row['resume_alert_shown'] ?? 0);
            sendJsonResponse(['success' => true, 'break_ended' => $breakEnded == 1, 'resume_shown' => $resumeShown == 1]);
        } else {
            sendJsonResponse(['success' => false, 'break_ended' => false]);
        }
        break;

    // ============================================================
    // MARK RESUME ALERT SHOWN
    // ============================================================
    case 'mark_resume_alert_shown':
        $sql = "UPDATE attendance SET resume_alert_shown = 1 WHERE agent_id = ? AND date = ?";
        $result = executeQuery($sql, [$agentId, $today]);
        sendJsonResponse(['success' => true]);
        break;

    // ============================================================
    // CLEAR BREAK ENDED ALERT
    // ============================================================
    case 'clear_break_ended_alert':
        $sql = "UPDATE attendance SET break_ended_alert = 0 WHERE agent_id = ? AND date = ?";
        $result = executeQuery($sql, [$agentId, $today]);
        sendJsonResponse(['success' => true]);
        break;

    // ============================================================
    // MARK ALERT SHOWN
    // ============================================================
    case 'mark_alert_shown':
        $sql = "UPDATE attendance SET break_alert_shown = 1 WHERE agent_id = ? AND date = ?";
        $result = executeQuery($sql, [$agentId, $today]);
        sendJsonResponse(['success' => true]);
        break;

    // ============================================================
    // GET LIVE STATUS
    // ============================================================
    case 'get_live_status':
        $freshResult = executeQuery("SELECT * FROM attendance WHERE agent_id = ? AND date = ?", [$agentId, $today]);
        if ($freshResult && $freshResult->num_rows > 0) {
            $fresh = $freshResult->fetch_assoc();
            $response = [
                'success' => true,
                'data' => [
                    'job_start_time' => $fresh['job_start_time'],
                    'job_end_time' => $fresh['job_end_time'],
                    'job_paused' => (int)($fresh['job_paused'] ?? 0),
                    'logout_time' => $fresh['logout_time'],
                    'break_timer_start' => $fresh['break_timer_start'],
                    'break_timer_end' => $fresh['break_timer_end'],
                    'break_timer_type' => $fresh['break_timer_type'],
                    'lunch_start' => $fresh['lunch_start'],
                    'lunch_end' => $fresh['lunch_end'],
                    'snack_start' => $fresh['snack_start'],
                    'snack_end' => $fresh['snack_end'],
                    'bio_start' => $fresh['bio_start'],
                    'bio_end' => $fresh['bio_end'],
                    'snack_count' => (int)($fresh['snack_count'] ?? 0),
                    'total_pause_seconds' => (int)($fresh['total_pause_seconds'] ?? 0),
                    'break_alert_shown' => (int)($fresh['break_alert_shown'] ?? 0),
                    'break_ended_alert' => (int)($fresh['break_ended_alert'] ?? 0),
                    'resume_alert_shown' => (int)($fresh['resume_alert_shown'] ?? 0),
                    'status' => $fresh['status'],
                    'location_lat' => $fresh['location_lat'],
                    'location_lng' => $fresh['location_lng'],
                    'location_accuracy' => $fresh['location_accuracy'],
                    'location_captured_at' => $fresh['location_captured_at'],
                    'location_ip' => $fresh['location_ip']
                ]
            ];
            sendJsonResponse($response);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'No data found']);
        }
        break;

    // ============================================================
    // GET LOCATION HISTORY
    // ============================================================
    case 'get_locations':
        $days = intval($_POST['days'] ?? 20);
        $result = executeQuery("
            SELECT 
                date,
                job_start_time,
                location_lat,
                location_lng,
                location_accuracy,
                location_captured_at,
                location_ip
            FROM attendance 
            WHERE agent_id = ? 
                AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND location_lat IS NOT NULL 
                AND location_lng IS NOT NULL
            ORDER BY date DESC, job_start_time DESC
        ", [$agentId, $days]);
        
        $locations = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $locations[] = [
                    'date' => $row['date'],
                    'job_start_time' => $row['job_start_time'],
                    'latitude' => decryptData($row['location_lat']),
                    'longitude' => decryptData($row['location_lng']),
                    'accuracy' => $row['location_accuracy'],
                    'captured_at' => $row['location_captured_at'],
                    'ip' => $row['location_ip']
                ];
            }
        }
        sendJsonResponse(['success' => true, 'locations' => $locations]);
        break;

    // ============================================================
    // LOGOUT
    // ============================================================
    case 'logout':
        if (empty($attendance['logout_time'])) {
            // If break is active, end it first
            if (!empty($attendance['break_timer_start']) && empty($attendance['break_timer_end'])) {
                $type = $attendance['break_timer_type'] ?? 'lunch';
                if ($type === 'bio') {
                    $time = date('H:i:s');
                    executeQuery("UPDATE attendance SET 
                        bio_end = ?,
                        break_timer_end = NOW(),
                        break_timer_type = NULL,
                        break_comment = NULL
                        WHERE agent_id = ? AND date = ?", 
                        [$time, $agentId, $today]);
                } else {
                    $field = $type . '_end';
                    $time = date('H:i:s');
                    executeQuery("UPDATE attendance SET 
                        $field = ?,
                        break_timer_end = NOW(),
                        break_timer_type = NULL,
                        break_comment = NULL
                        WHERE agent_id = ? AND date = ?", 
                        [$time, $agentId, $today]);
                }
            }
            
            $now = date('Y-m-d H:i:s');
            $logoutTime = date('H:i:s');
            $sql = "UPDATE attendance SET 
                job_end_time = ?,
                logout_time = ?, 
                job_paused = 0,
                status = 'logged_out' 
                WHERE agent_id = ? AND date = ?";
            $result = executeQuery($sql, [$now, $logoutTime, $agentId, $today]);
            if ($result !== false) {
                addAuditLog($agentId, 'logged_out', 'Logged out');
                sendJsonResponse(['success' => true, 'message' => 'Logged out']);
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Failed to logout']);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Already logged out']);
        }
        break;

    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action']);
}
?>