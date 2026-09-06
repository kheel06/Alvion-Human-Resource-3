<?php
/**
 * POST: Submit time correction request
 * GET: List my correction requests
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $row ? (int) $row['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
} elseif ($employeeId) {
    $employeeId = (int) $employeeId;
}
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT id, date, requested_time_in, requested_time_out, reason, status, created_at, reviewed_at
        FROM timesheet_correction_requests
        WHERE employee_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$employeeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$date = $input['date'] ?? '';
$timeIn = $input['requested_time_in'] ?? $input['time_in'] ?? '';
$timeOut = $input['requested_time_out'] ?? $input['time_out'] ?? '';
$reason = trim($input['reason'] ?? '');
$timesheetId = isset($input['timesheet_id']) ? (int) $input['timesheet_id'] : null;

if (!$date || !$reason || strlen($reason) < 10) {
    echo json_encode(['success' => false, 'message' => 'Date and reason (min 10 chars) are required']);
    exit;
}

if (strtotime($date) >= strtotime('today')) {
    echo json_encode(['success' => false, 'message' => 'Correction must be for a past date']);
    exit;
}

try {
    $stmt = $db->prepare("
        INSERT INTO timesheet_correction_requests (employee_id, timesheet_id, date, requested_time_in, requested_time_out, reason, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $employeeId,
        $timesheetId ?: null,
        $date,
        $timeIn ?: null,
        $timeOut ?: null,
        $reason,
    ]);
    $id = $db->lastInsertId();
    if (function_exists('logAction')) {
        logAction('correction_request', 'hr3_attendance', $id, null, ['date' => $date, 'reason' => $reason]);
    }
    echo json_encode(['success' => true, 'message' => 'Correction request submitted', 'id' => (int) $id]);
} catch (PDOException $e) {
    error_log('Correction request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
}
